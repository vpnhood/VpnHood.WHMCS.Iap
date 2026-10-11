<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Module\Addon\VpnHoodIap\ApiException;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Creates the WHMCS client for a brand-new store customer (email unknown to
 * WHMCS). Only ever called after AccountService reported no existing client —
 * duplicate clients are never created, and the module never emails panel
 * passwords: the customer bought in a store; the WHMCS account is
 * bookkeeping until they ever choose to use the web portal.
 */
class ClientProvisioner
{
    /**
     * The address currently being created, for as long as that takes. `AddClient` fires
     * WHMCS's "Email Address Verification" SYNCHRONOUSLY and `noemail` does not cover it
     * (verified on dev 2026-08-29), so without this every store buyer received a link
     * asking them to confirm an address their identity provider had already proved —
     * sign-in refuses any address the IdP reports as unverified, and the row is marked
     * verified the instant AddClient returns. The mail-suppression hook reads this
     * through isMailboxProvenByIdp(); it is armed only across that one call, so no other
     * verification mail on the install can be caught by it.
     */
    private static ?string $mailboxProvenByIdp = null;

    /** Is WHMCS asking this address to confirm what the identity provider already proved? */
    public static function isMailboxProvenByIdp(string $email): bool
    {
        return self::$mailboxProvenByIdp !== null
            && strcasecmp(self::$mailboxProvenByIdp, trim($email)) === 0;
    }

    /**
     * The website language for an app's Accept-Language, or null when this install has none for
     * it. Matched against WHMCS's own list of the installed languages, so an install with more of
     * them simply matches more.
     */
    public static function languageFor(string $acceptLanguage): ?string
    {
        if (trim($acceptLanguage) === '') {
            return null;
        }
        try {
            $locales = \WHMCS\Language\ClientLanguage::getLocales();
        } catch (\Throwable $e) {
            // a language is cosmetic: it must never fail a sign-in or a purchase
            logModuleCall('vpnhoodiap', 'clientLanguage', $acceptLanguage, $e->getMessage(), '');
            return null;
        }
        return self::matchLanguage($acceptLanguage, $locales);
    }

    /**
     * The WHMCS language of the first Accept-Language tag an installed locale serves: the locale
     * itself (pt-BR → pt_BR), else its language alone (en-US → en_001, zh-Hans → zh_TW).
     *
     * @param array[] $locales WHMCS's: [{locale: 'pt_BR', language: 'portuguese-br', languageCode: 'pt', ...}]
     */
    public static function matchLanguage(string $acceptLanguage, array $locales): ?string
    {
        foreach (explode(',', $acceptLanguage) as $range) {
            $parts = explode(';', $range);
            $tag = strtolower(str_replace('_', '-', trim($parts[0])));
            // q=0 says "not this one"
            if ($tag === '' || $tag === '*' || preg_match('/^\s*q\s*=\s*0(\.0*)?\s*$/i', $parts[1] ?? '') === 1) {
                continue;
            }
            $languageCode = explode('-', $tag)[0];
            $sameLanguage = null;
            foreach ($locales as $locale) {
                if (strtolower(str_replace('_', '-', (string) $locale['locale'])) === $tag) {
                    return (string) $locale['language'];
                }
                if ($sameLanguage === null && strtolower((string) $locale['languageCode']) === $languageCode) {
                    $sameLanguage = (string) $locale['language'];
                }
            }
            if ($sameLanguage !== null) {
                return $sameLanguage;
            }
        }
        return null;
    }

    /**
     * @param ?string $language the website language of the app the person uses (languageFor), which
     *                          the mail WHMCS sends them follows; null leaves the install's default
     * @return int the new client id
     * @throws ApiException
     */
    public function createClient(string $email, ?string $displayName, ?string $language): int
    {
        [$firstName, $lastName] = self::splitName($displayName);
        $client = [
            'firstname'       => $firstName,
            'lastname'        => $lastName,
            'email'           => $email,
            'password2'       => bin2hex(random_bytes(16)),
            'country'         => 'US',
            'skipvalidation'  => true,
            'noemail'         => true,
        ];
        if ($language !== null) {
            $client['language'] = $language;
        }
        self::$mailboxProvenByIdp = trim($email);
        try {
            $result = localAPI('AddClient', $client);
        } finally {
            self::$mailboxProvenByIdp = null;
        }
        if (($result['result'] ?? '') !== 'success' || (int) ($result['clientid'] ?? 0) <= 0) {
            throw new ApiException('Could not create the customer account: ' . ($result['message'] ?? 'unknown error'), 502);
        }
        $clientId = (int) $result['clientid'];

        // The IdP already proved mailbox ownership (email_verified claim gates
        // sign-in), so mark the WHMCS-side verification complete.
        try {
            $user = \WHMCS\User\User::where('email', $email)->first();
            $user?->setEmailVerificationCompleted();
        } catch (\Throwable $e) {
            // tolerated — the fallback below is what makes it safe
        }

        // If it did not take, the suppressed mail is sent after all: an account that can
        // never confirm its address would be locked out of its own client area, which is
        // a worse outcome than one redundant mail.
        $accounts = new AccountService();
        if (!$accounts->isEmailVerified($email)) {
            $accounts->sendVerificationEmail($email);
        }

        return $clientId;
    }

    /**
     * Keep the WHMCS client in step with what a sign-in or purchase just carried: the identity
     * provider's name — the IdP is the source of truth for who the person is, and every sign-in
     * or purchase carries the freshest value — and the app's language, only where the client has
     * none, so a choice made on the website stands. No-op without either (Apple sends a name only
     * once) or when the client already matches. Best-effort: both are cosmetic and must never
     * fail a sign-in or a purchase.
     */
    public function syncClient(int $clientId, ?string $displayName, ?string $language): void
    {
        $hasName = trim((string) $displayName) !== '';
        if (!$hasName && $language === null) {
            return;
        }
        try {
            $client = \WHMCS\Database\Capsule::table('tblclients')
                ->where('id', $clientId)->first(['firstname', 'lastname', 'language']);
            if ($client === null) {
                return;
            }
            $changes = [];
            if ($hasName) {
                [$firstName, $lastName] = self::splitName($displayName);
                if ((string) $client->firstname !== $firstName || (string) $client->lastname !== $lastName) {
                    $changes = ['firstname' => $firstName, 'lastname' => $lastName];
                }
            }
            if ($language !== null && (string) $client->language === '') {
                $changes['language'] = $language;
            }
            if ($changes !== []) {
                localAPI('UpdateClient', ['clientid' => $clientId, 'skipvalidation' => true] + $changes);
            }
        } catch (\Throwable $e) {
            // tolerated — see above
        }
    }

    /** @return array{string, string} WHMCS requires both parts non-empty. */
    private static function splitName(?string $displayName): array
    {
        $nameParts = preg_split('/\s+/', trim((string) $displayName), 2) ?: [];
        $firstName = isset($nameParts[0]) && $nameParts[0] !== '' ? $nameParts[0] : 'VpnHood';
        $lastName = isset($nameParts[1]) && $nameParts[1] !== '' ? $nameParts[1] : 'Customer';
        return [$firstName, $lastName];
    }
}
