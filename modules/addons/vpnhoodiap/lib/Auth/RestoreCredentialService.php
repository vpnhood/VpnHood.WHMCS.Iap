<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Auth;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\Cbor;
use WHMCS\Module\Addon\VpnHoodIap\Jwk;
use WHMCS\Module\Addon\VpnHoodIap\Jwt;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Zero-tap sign-in restoration (Android "Restore Credentials", Play policy
 * effective April 2027): the device holds a WebAuthn-style key pair that
 * survives device-to-device transfer, and this service is its relying party.
 *
 * Wire shape (the portal-neutral subset of WebAuthn):
 *  - registration-options / assertion-options hand out a `requestJson` the
 *    device consumes VERBATIM — the server owns challenge, rp and user, so no
 *    client ever composes one.
 *  - registration stores the credential's public key against the signed-in
 *    user; the assertion signs the server's challenge with it and becomes a
 *    session with no interaction on the device.
 *
 * Trust argument:
 *  - Challenges are single-use rows, hashed at rest like every other token
 *    here, minutes-long, and bound to their purpose (register vs assert) —
 *    a registration challenge can never sign anybody in.
 *  - Registration happens only over an authenticated session; attestation is
 *    always `none` on this credential type, so the session IS the trust root.
 *  - The assertion is verified the WebAuthn way: type, challenge, rpId hash,
 *    then the ES256 signature over authenticatorData || sha256(clientDataJSON)
 *    against the stored key. The origin (the app's signing-key hash on
 *    Android) is captured at registration and must match at assertion.
 *  - User presence is deliberately NOT required: restore credentials are
 *    silent by design — that is the whole feature.
 */
class RestoreCredentialService
{
    private const CHALLENGE_TTL_SECONDS = 600;
    private const PURPOSE_REGISTER = 'register';
    private const PURPOSE_ASSERT = 'assert';

    /** Devices come and go; keep the newest few keys per account, never a museum. */
    private const MAX_CREDENTIALS_PER_USER = 5;

    /** @param string $rpId the relying-party id — the portal's host, one per install */
    public function __construct(private readonly string $rpId)
    {
    }

    // ------------------------------------------------------------ options --

    /**
     * WebAuthn PublicKeyCredentialCreationOptions for the signed-in user, as
     * the verbatim `requestJson` string the device API consumes.
     */
    public function registrationOptions(array $user): string
    {
        $challenge = $this->issueChallenge(self::PURPOSE_REGISTER, (int) $user['id']);
        return (string) json_encode([
            'challenge' => $challenge,
            'rp'   => ['id' => $this->rpId, 'name' => $this->rpId],
            'user' => [
                // external_uid is the account's stable cross-store id — the same
                // value sessions report as userId, so the credential follows it
                'id'          => Jwt::base64UrlEncode((string) $user['external_uid']),
                'name'        => (string) $user['email'],
                'displayName' => (string) ($user['display_name'] ?? $user['email']),
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]], // ES256 only
            'timeout' => 60000,
        ], JSON_UNESCAPED_SLASHES);
    }

    /** WebAuthn PublicKeyCredentialRequestOptions (anonymous), as `requestJson`. */
    public function assertionOptions(): string
    {
        $challenge = $this->issueChallenge(self::PURPOSE_ASSERT, null);
        return (string) json_encode([
            'challenge' => $challenge,
            'rpId'      => $this->rpId,
            'timeout'   => 60000,
        ], JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------- registration --

    /**
     * Verify a registration response and store the credential for the user.
     *
     * @return string the credential id (base64url), the handle the device may
     *                later delete on sign-out
     * @throws \RuntimeException with the exact reason — the endpoint logs it and
     *                           answers its own neutral problem
     */
    public function register(array $user, string $responseJson): string
    {
        $userId = (int) $user['id'];
        $clientData = self::parseClientData($responseJson, 'webauthn.create');
        $this->consumeChallenge(self::PURPOSE_REGISTER, $clientData['challenge'], $userId);
        $parsed = self::parseRegistration($responseJson, $this->rpId);

        Capsule::table('mod_vpnhood_iap_restore_credentials')->updateOrInsert(
            ['credential_id' => $parsed['credentialId']],
            [
                'user_id'        => $userId,
                'public_key_pem' => $parsed['publicKeyPem'],
                'origin'         => $clientData['origin'],
                'sign_count'     => $parsed['signCount'],
                'created_at'     => date('Y-m-d H:i:s'),
                'last_used_at'   => null,
            ]);

        // cap per user, oldest out first
        $keepIds = Capsule::table('mod_vpnhood_iap_restore_credentials')
            ->where('user_id', $userId)->orderByDesc('id')
            ->limit(self::MAX_CREDENTIALS_PER_USER)->pluck('id')->all();
        Capsule::table('mod_vpnhood_iap_restore_credentials')
            ->where('user_id', $userId)->whereNotIn('id', $keepIds)->delete();

        return $parsed['credentialId'];
    }

    // ---------------------------------------------------------- assertion --

    /**
     * Verify an assertion response and resolve it to its user row — the
     * sign-in proper. Every failure is a \RuntimeException carrying the exact
     * reason; the endpoint logs it and answers ONE neutral 401 — which part
     * failed is for the audit log, never for an unauthenticated caller.
     *
     * @return array the mod_vpnhood_iap_users row
     * @throws \RuntimeException
     */
    public function signInUser(string $responseJson): array
    {
        $clientData = self::parseClientData($responseJson, 'webauthn.get');
        $this->consumeChallenge(self::PURPOSE_ASSERT, $clientData['challenge'], null);

        $credentialId = self::stringField(json_decode($responseJson, true), 'id');
        $row = Capsule::table('mod_vpnhood_iap_restore_credentials')
            ->where('credential_id', $credentialId)->first();
        if ($row === null) {
            throw new \RuntimeException('Unknown credential id.');
        }
        $credential = (array) $row;
        if (!hash_equals((string) $credential['origin'], $clientData['origin'])) {
            throw new \RuntimeException('The assertion origin does not match the registration.');
        }

        $signCount = self::verifyAssertion($responseJson, (string) $credential['public_key_pem'], $this->rpId);

        Capsule::table('mod_vpnhood_iap_restore_credentials')
            ->where('id', $credential['id'])
            ->update(['sign_count' => $signCount, 'last_used_at' => date('Y-m-d H:i:s')]);

        $userRow = Capsule::table('mod_vpnhood_iap_users')->find((int) $credential['user_id']);
        if ($userRow === null) {
            // the account went away under the credential (deletion) — same neutral answer
            throw new \RuntimeException('The credential points at a deleted account.');
        }
        return (array) $userRow;
    }

    // ------------------------------------------------------------ hygiene --

    /** Delete one credential of the signed-in user (device sign-out). Idempotent. */
    public function deleteCredential(array $user, string $credentialId): void
    {
        Capsule::table('mod_vpnhood_iap_restore_credentials')
            ->where('user_id', (int) $user['id'])
            ->where('credential_id', $credentialId)
            ->delete();
    }

    /** Every credential of a user (account deletion / security response). */
    public function deleteAllForUser(int $userId): void
    {
        Capsule::table('mod_vpnhood_iap_restore_credentials')->where('user_id', $userId)->delete();
    }

    // --------------------------------------------------------- challenges --

    /** @return string the base64url challenge handed to the device */
    private function issueChallenge(string $purpose, ?int $userId): string
    {
        $this->purgeStaleChallenges();
        $challenge = Jwt::base64UrlEncode(random_bytes(32));
        Capsule::table('mod_vpnhood_iap_restore_challenges')->insert([
            'purpose'        => $purpose,
            'challenge_hash' => hash('sha256', $challenge),
            'user_id'        => $userId,
            'expires_at'     => date('Y-m-d H:i:s', time() + self::CHALLENGE_TTL_SECONDS),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        return $challenge;
    }

    /**
     * Burn the challenge a response answered: it must exist, be alive, carry
     * the same purpose, and (for registration) belong to the same user.
     *
     * @throws \RuntimeException when it does not — the caller maps this to its
     *                           own neutral ApiException
     */
    private function consumeChallenge(string $purpose, string $challenge, ?int $userId): void
    {
        $updated = Capsule::table('mod_vpnhood_iap_restore_challenges')
            ->where('purpose', $purpose)
            ->where('challenge_hash', hash('sha256', $challenge))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->whereNull('used_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->update(['used_at' => date('Y-m-d H:i:s')]);
        if ($updated !== 1) {
            throw new \RuntimeException('The challenge is unknown, spent or expired.');
        }
    }

    private function purgeStaleChallenges(): void
    {
        Capsule::table('mod_vpnhood_iap_restore_challenges')
            ->where('expires_at', '<', date('Y-m-d H:i:s', time() - 86400))
            ->delete();
    }

    // ------------------------------------------- pure verification (no DB) --

    /**
     * clientDataJSON of either ceremony: enforce the type, surface challenge
     * and origin.
     *
     * @return array{challenge:string, origin:string}
     * @throws \RuntimeException
     */
    public static function parseClientData(string $responseJson, string $expectedType): array
    {
        $response = json_decode($responseJson, true);
        if (!is_array($response)) {
            throw new \RuntimeException('The response is not a JSON object.');
        }
        $clientDataJson = Jwt::base64UrlDecode(self::stringField($response['response'] ?? null, 'clientDataJSON'));
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new \RuntimeException('clientDataJSON is not a JSON object.');
        }
        if (($clientData['type'] ?? '') !== $expectedType) {
            throw new \RuntimeException("clientDataJSON type is not $expectedType.");
        }
        return [
            'challenge' => self::stringField($clientData, 'challenge'),
            'origin'    => self::stringField($clientData, 'origin'),
        ];
    }

    /**
     * The registration response's attestation object: format must be `none`
     * (the only one this credential type produces), authenticator data must
     * name our rp and carry a credential, and the key must be COSE EC2 P-256
     * ES256 — the single algorithm the options offered.
     *
     * @return array{credentialId:string, publicKeyPem:string, signCount:int}
     * @throws \RuntimeException
     */
    public static function parseRegistration(string $responseJson, string $rpId): array
    {
        $response = json_decode($responseJson, true);
        if (!is_array($response)) {
            throw new \RuntimeException('The response is not a JSON object.');
        }
        $attestation = Cbor::decode(Jwt::base64UrlDecode(
            self::stringField($response['response'] ?? null, 'attestationObject')));
        if (!is_array($attestation) || ($attestation['fmt'] ?? '') !== 'none') {
            throw new \RuntimeException('The attestation format is not "none".');
        }
        $authData = $attestation['authData'] ?? '';
        if (!is_string($authData) || strlen($authData) < 55) {
            throw new \RuntimeException('Authenticator data is missing or too short.');
        }

        self::assertRpIdHash($authData, $rpId);
        $flags = ord($authData[32]);
        if (($flags & 0x40) === 0) { // AT: attested credential data present
            throw new \RuntimeException('Authenticator data carries no credential.');
        }
        $signCount = (int) unpack('N', substr($authData, 33, 4))[1];

        // attested credential data: aaguid(16) credIdLen(2) credId key(CBOR)
        $credentialIdLength = (int) unpack('n', substr($authData, 53, 2))[1];
        if ($credentialIdLength < 1 || 55 + $credentialIdLength > strlen($authData)) {
            throw new \RuntimeException('The credential id does not fit the authenticator data.');
        }
        $credentialId = substr($authData, 55, $credentialIdLength);
        [$coseKey] = Cbor::decodeItem($authData, 55 + $credentialIdLength);

        // COSE EC2 (kty 2), P-256 (crv 1), ES256 (alg -7); x/y at -2/-3
        if (!is_array($coseKey) || ($coseKey[1] ?? null) !== 2 || ($coseKey[-1] ?? null) !== 1 ||
            ($coseKey[3] ?? null) !== -7 || !is_string($coseKey[-2] ?? null) || !is_string($coseKey[-3] ?? null)) {
            throw new \RuntimeException('The credential public key is not a COSE ES256 P-256 key.');
        }

        // the wire id must be the same credential the authenticator data attests
        if (self::stringField($response, 'id') !== Jwt::base64UrlEncode($credentialId)) {
            throw new \RuntimeException('The response id does not match the attested credential id.');
        }

        return [
            'credentialId' => Jwt::base64UrlEncode($credentialId),
            'publicKeyPem' => Jwk::ecP256ToPem($coseKey[-2], $coseKey[-3]),
            'signCount'    => $signCount,
        ];
    }

    /**
     * The assertion's cryptographic core: rpId hash, then the ES256 signature
     * over authenticatorData || sha256(clientDataJSON).
     *
     * @return int the authenticator's sign count (stored, never enforced —
     *             restore credentials legitimately reset it)
     * @throws \RuntimeException
     */
    public static function verifyAssertion(string $responseJson, string $publicKeyPem, string $rpId): int
    {
        $response = json_decode($responseJson, true);
        if (!is_array($response)) {
            throw new \RuntimeException('The response is not a JSON object.');
        }
        $inner = $response['response'] ?? null;
        $authData = Jwt::base64UrlDecode(self::stringField($inner, 'authenticatorData'));
        $clientDataJson = Jwt::base64UrlDecode(self::stringField($inner, 'clientDataJSON'));
        $signature = Jwt::base64UrlDecode(self::stringField($inner, 'signature'));
        if (strlen($authData) < 37) {
            throw new \RuntimeException('Authenticator data is too short.');
        }
        self::assertRpIdHash($authData, $rpId);

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            throw new \RuntimeException('The stored public key cannot be loaded.');
        }
        $signedPart = $authData . hash('sha256', $clientDataJson, true);
        if (openssl_verify($signedPart, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('The assertion signature does not verify.');
        }
        return (int) unpack('N', substr($authData, 33, 4))[1];
    }

    /** @throws \RuntimeException when authenticator data names a different rp */
    private static function assertRpIdHash(string $authData, string $rpId): void
    {
        if (!hash_equals(hash('sha256', $rpId, true), substr($authData, 0, 32))) {
            throw new \RuntimeException('The rpId hash does not match this portal.');
        }
    }

    /** @throws \RuntimeException when the field is absent or not a non-empty string */
    private static function stringField(mixed $container, string $key): string
    {
        $value = is_array($container) ? ($container[$key] ?? null) : null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("The response field '$key' is missing.");
        }
        return $value;
    }
}
