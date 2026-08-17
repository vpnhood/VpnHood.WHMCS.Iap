<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Auth;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\User\User;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * The password grant: sign in with the WHMCS client-area credentials, second
 * factor included — entirely on this API, never via a WHMCS page.
 *
 * Never creates an account. Google sign-in deliberately creates one for a new
 * email; this grant only ever signs into something that already exists — a
 * module account, or (for a pure web customer) the WHMCS client itself, whose
 * app-side row is then created already bound to that client. A login that owns
 * no client and matches no account is refused.
 *
 * Anti-enumeration is a hard requirement: unknown email and wrong password are
 * the SAME 401 (`invalid_credentials`, one message naming both possibilities),
 * unknown emails burn the same bcrypt time as real ones, and the per-address
 * cooldown fires for nonexistent addresses exactly as for real ones — nothing
 * in status, body or timing says whether an email exists here.
 *
 * The second factor rides WHMCS's own machinery (TwoFactorAuthentication):
 * verification inherits their TOTP replay guard and ±1-window tolerance.
 * WHMCS's ValidateLogin API is deliberately NOT used — it skips the second
 * factor, which would make this grant a weaker door than the client area.
 * Backup codes are accepted on the same step; WHMCS's check is a pure compare,
 * so a used backup code is rotated here and the replacement returned once.
 */
class PasswordSignInService
{
    public const CHALLENGE_TTL_SECONDS = 300;
    public const CHALLENGE_MAX_ATTEMPTS = 5;
    public const COOLDOWN_MAX_FAILURES = 5;

    /** Admin-configurable ('PasswordCooldownMinutes'); this is the fallback when unset/nonsense. */
    public const COOLDOWN_DEFAULT_MINUTES = 10;

    /**
     * A real bcrypt hash of an unknowable random string: unknown emails verify
     * against it so they cost the same time as a wrong password on a real
     * account. An invalid-format dummy would return instantly and leak.
     */
    private const TIMING_DUMMY_HASH = '$2y$10$gbzhaWAUW.BSYdTsDXgK3eR86Gr4McqQvONosPeyrrmiWGIYw.SLW';

    private const CREDENTIALS_MESSAGE =
        'The email or password is wrong — or this account has never set a password. '
        . 'Set or recover the password on the account website, then sign in here.';

    public function __construct(private readonly IapRepository $repo)
    {
    }

    /**
     * Step 1: verify the WHMCS password.
     *
     * @return array{whmcsUser: User}|array{challenge: array{token:string, type:string, expiresAt:string}}
     * @throws ApiException 401 invalid_credentials (one answer for unknown email AND wrong password),
     *                      429 too_many_attempts
     */
    public function signInWithPassword(string $email, string $password, string $packageName): array
    {
        $emailHash = self::emailHash($email);
        $this->assertNotCoolingDown($emailHash);

        $whmcsUser = User::where('email', trim($email))->first();
        if ($whmcsUser === null) {
            password_verify($password, self::TIMING_DUMMY_HASH);
            $this->recordFailure($emailHash);
            throw new ApiException(self::CREDENTIALS_MESSAGE, 401, 'invalid_credentials');
        }
        if (!$whmcsUser->verifyPassword($password)) {
            $this->recordFailure($emailHash);
            throw new ApiException(self::CREDENTIALS_MESSAGE, 401, 'invalid_credentials');
        }
        $this->clearFailures($emailHash);

        if (!$whmcsUser->hasTwoFactorAuthEnabled()) {
            return ['whmcsUser' => $whmcsUser];
        }
        return ['challenge' => $this->issueChallenge($whmcsUser, $packageName)];
    }

    /**
     * Step 2 (only when step 1 answered a challenge): verify the second factor.
     * Accepts the authenticator code or the WHMCS backup code; a used backup
     * code is rotated and the replacement returned for one-time display.
     *
     * @return array{whmcsUser: User, newBackupCode: ?string}
     * @throws ApiException 401 invalid_code (attempts remain) / invalid_challenge (restart from password)
     */
    public function completeChallenge(string $challengeToken, string $code, string $packageName): array
    {
        $now = time();
        $row = Capsule::table('mod_vpnhood_iap_login_challenges')
            ->where('token_hash', hash('sha256', $challengeToken))
            ->first();
        if ($row === null
            || $row->used_at !== null
            || strtotime((string) $row->expires_at) <= $now
            || (string) $row->package_name !== $packageName
            || (int) $row->attempts >= self::CHALLENGE_MAX_ATTEMPTS) {
            throw new ApiException('The sign-in challenge is expired or spent. Start over from the password.',
                401, 'invalid_challenge');
        }

        // count the attempt before verifying, so a crash cannot grant a free retry
        Capsule::table('mod_vpnhood_iap_login_challenges')->where('id', $row->id)->increment('attempts');

        $whmcsUser = User::find((int) $row->whmcs_user_id);
        if ($whmcsUser === null) {
            throw new ApiException('The sign-in challenge is expired or spent. Start over from the password.',
                401, 'invalid_challenge');
        }

        [$verified, $newBackupCode] = $this->verifySecondFactor($whmcsUser, trim($code));
        if (!$verified) {
            if ((int) $row->attempts + 1 >= self::CHALLENGE_MAX_ATTEMPTS) {
                // burn it: the next try answers invalid_challenge and restarts from the password
                Capsule::table('mod_vpnhood_iap_login_challenges')->where('id', $row->id)
                    ->update(['used_at' => date('Y-m-d H:i:s', $now)]);
            }
            throw new ApiException('The code is wrong.', 401, 'invalid_code');
        }

        Capsule::table('mod_vpnhood_iap_login_challenges')->where('id', $row->id)
            ->update(['used_at' => date('Y-m-d H:i:s', $now)]);
        return ['whmcsUser' => $whmcsUser, 'newBackupCode' => $newBackupCode];
    }

    /**
     * Sign the verified WHMCS login into its EXISTING account (lifecycle: this
     * grant creates nothing new):
     *
     *   1. the module account already carrying the 'whmcs' identity;
     *   2. the one module account bound to a client this login owns — ownership
     *      is a stronger proof than any email match;
     *   3. the one module account whose sign-in methods currently report this
     *      email — only when WHMCS itself has verified the address (an
     *      unverified address must never join, or squatting a victim's email in
     *      a WHMCS signup would open their app account);
     *   4. no match but the login owns a client: the app-side row for THAT
     *      client is created, bound from birth — the WHMCS client IS the
     *      pre-existing account, this row is only its handle here;
     *   5. nothing exists → refused. No fresh empty account, ever.
     *
     * @return array the mod_vpnhood_iap_users row
     * @throws ApiException 403 no_account / account_ambiguous
     */
    public function signInToModuleAccount(User $whmcsUser): array
    {
        $subject = (string) $whmcsUser->id;
        $email = (string) $whmcsUser->email;
        $emailVerified = $whmcsUser->getAttribute('email_verified_at') !== null;
        $displayName = trim(trim((string) $whmcsUser->getAttribute('first_name')) . ' '
            . trim((string) $whmcsUser->getAttribute('last_name')));
        $displayName = $displayName === '' ? null : $displayName;
        $ownedClientIds = array_map('intval', $whmcsUser->ownedClients()->pluck('id')->all());

        try {
            $user = $this->repo->findUserForWhmcsSignIn($subject, $ownedClientIds, $email, $emailVerified, $displayName);
        } catch (\RuntimeException $e) {
            // several module accounts on this login's own clients: guessing would
            // hand one person's codes to another; merging is a human decision
            throw new ApiException('More than one app account belongs to this login. Contact support to merge them.',
                403, 'account_ambiguous');
        }

        if ($user === null) {
            if ($ownedClientIds === []) {
                // a manager invited to someone else's client: the account is the
                // owner's, and this grant refuses rather than creating anything
                throw new ApiException('This login has no account of its own here.', 403, 'no_account');
            }
            $user = $this->repo->createUserForWhmcsClient($subject, $email, $emailVerified, $displayName,
                $ownedClientIds[0]);
        } elseif ($user['client_id'] === null && $ownedClientIds !== []) {
            // the password proved ownership — a certain link, unlike the email guess
            $this->repo->linkUserClient((int) $user['id'], $ownedClientIds[0]);
            $user['client_id'] = $ownedClientIds[0];
        }
        return $user;
    }

    // ---------------------------------------------------------------- 2FA --

    /** @return array{token:string, type:string, expiresAt:string} */
    private function issueChallenge(User $whmcsUser, string $packageName): array
    {
        $this->purgeStale();
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expiresAt = $now + self::CHALLENGE_TTL_SECONDS;
        Capsule::table('mod_vpnhood_iap_login_challenges')->insert([
            'token_hash'    => hash('sha256', $token),
            'whmcs_user_id' => (int) $whmcsUser->id,
            'package_name'  => $packageName,
            'attempts'      => 0,
            'expires_at'    => date('Y-m-d H:i:s', $expiresAt),
            'created_at'    => date('Y-m-d H:i:s', $now),
        ]);
        return [
            'token'     => $token,
            'type'      => (string) ($whmcsUser->getAttribute('second_factor') ?: 'totp'),
            'expiresAt' => gmdate('c', $expiresAt),
        ];
    }

    /**
     * WHMCS's own verification, driven statelessly: validateChallenge() reads
     * the submitted value from $_POST['key'] (the field its challenge form
     * posts), so that is set for the call and restored after. Their TOTP check
     * carries the used-code replay guard and the ±1-window tolerance. A code
     * their module rejects is retried as the backup code, which their check
     * treats as a pure compare — hence the rotation on success.
     *
     * @return array{0: bool, 1: ?string} verified + the replacement backup code when one was spent
     */
    private function verifySecondFactor(User $whmcsUser, string $code): array
    {
        $tfa = new \WHMCS\TwoFactorAuthentication();
        $tfa->setUser($whmcsUser);

        $postBackup = $_POST;
        $_POST = ['key' => $code];
        try {
            $verified = (bool) $tfa->validateChallenge();
        } catch (\Throwable) {
            // a module whose challenge wants fields we did not guess — the
            // backup code below still works for it
            $verified = false;
        } finally {
            $_POST = $postBackup;
        }
        if ($verified) {
            return [true, null];
        }

        if ($code !== '' && $tfa->verifyBackupCode($code)) {
            // their compare is pure: without rotation the spent code would stay
            // valid forever. The replacement is returned for one-time display.
            return [true, (string) $tfa->generateNewBackupCode()];
        }
        return [false, null];
    }

    // ------------------------------------------------------------ cooldown --

    private static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /** The configured cooldown, minutes → seconds; the default when unset or nonsense. */
    private function cooldownSeconds(): int
    {
        $minutes = (int) $this->repo->setting('PasswordCooldownMinutes');
        return ($minutes > 0 ? $minutes : self::COOLDOWN_DEFAULT_MINUTES) * 60;
    }

    /**
     * Not a lock: after COOLDOWN_MAX_FAILURES failures the address simply waits
     * out the configured minutes and works again by itself — refused requests
     * are NOT counted, so the wait is bounded, never extended by hammering.
     * Fires for nonexistent addresses exactly as for real ones.
     *
     * @throws ApiException 429
     */
    private function assertNotCoolingDown(string $emailHash): void
    {
        $count = (int) Capsule::table('mod_vpnhood_iap_login_attempts')
            ->where('email_hash', $emailHash)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - $this->cooldownSeconds()))
            ->count();
        if ($count >= self::COOLDOWN_MAX_FAILURES) {
            throw new ApiException('Too many sign-in attempts. Wait a few minutes, then try again.',
                429, 'too_many_attempts');
        }
    }

    private function recordFailure(string $emailHash): void
    {
        Capsule::table('mod_vpnhood_iap_login_attempts')->insert([
            'email_hash' => $emailHash,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function clearFailures(string $emailHash): void
    {
        Capsule::table('mod_vpnhood_iap_login_attempts')->where('email_hash', $emailHash)->delete();
    }

    /** Opportunistic hygiene on the issue path — no cron dependency. */
    private function purgeStale(): void
    {
        Capsule::table('mod_vpnhood_iap_login_challenges')
            ->where('expires_at', '<', date('Y-m-d H:i:s', time() - 86400))
            ->delete();
        Capsule::table('mod_vpnhood_iap_login_attempts')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - 4 * $this->cooldownSeconds()))
            ->delete();
    }
}
