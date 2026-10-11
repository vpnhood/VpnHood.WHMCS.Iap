<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Auth;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Opaque app session tokens: 64 hex chars handed to the app, sha256 at rest,
 * constant-time lookup by hash. Not JWTs on purpose — a sign-out deletes the
 * session server-side, no signing keys to manage.
 *
 * A session has NO time limit: it ends at sign-out or at account deletion, and
 * nothing of it is kept after. The app calls the portal only when something it
 * holds expires — a month or a year apart — and many of its users reach the portal
 * only through the VPN, so a time limit signed people out at renewal and made them
 * type a password again, on a TV as well.
 */
class SessionService
{
    /** last_used_at is only rewritten when older than this, to keep resolve() cheap. */
    private const TOUCH_INTERVAL_SECONDS = 60;

    /**
     * @param string|null $store the device's home store, derived from the package name it signed
     *                           in with. Null where the app is not known to any store — the
     *                           account-wide choice then serves, as it did before this existed.
     * @return array{token:string, expiresAt:null} expiresAt is always null: no time limit
     */
    public function issue(int $userId, ?string $store = null): array
    {
        $token = bin2hex(random_bytes(32));
        Capsule::table('mod_vpnhood_iap_sessions')->insert([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s'),
            'store'      => $store,
        ]);
        return ['token' => $token, 'expiresAt' => null];
    }

    /**
     * Resolve a bearer token to its user row (module user, not WHMCS client).
     *
     * @return array the mod_vpnhood_iap_users row plus 'session_id'
     * @throws ApiException 401 when the token is missing or unknown (a signed-out one is gone)
     */
    public function resolve(?string $token): array
    {
        if ($token === null || strlen($token) < 32) {
            throw new ApiException('Unauthorized.', 401);
        }
        $now = date('Y-m-d H:i:s');
        $row = Capsule::table('mod_vpnhood_iap_sessions as s')
            ->join('mod_vpnhood_iap_users as u', 'u.id', '=', 's.user_id')
            ->where('s.token_hash', hash('sha256', $token))
            ->first(['u.*', 's.id as session_id', 's.last_used_at', 's.store as session_store']);
        if ($row === null) {
            throw new ApiException('Unauthorized.', 401);
        }
        $user = (array) $row;

        $lastUsed = $user['last_used_at'] !== null ? strtotime((string) $user['last_used_at']) : 0;
        if (time() - $lastUsed > self::TOUCH_INTERVAL_SECONDS) {
            Capsule::table('mod_vpnhood_iap_sessions')
                ->where('id', $user['session_id'])
                ->update(['last_used_at' => $now]);
        }
        unset($user['last_used_at']);
        return $user;
    }

    /** Sign-out: the session is deleted. Unknown tokens are ignored — revoke is idempotent. */
    public function revoke(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }
        Capsule::table('mod_vpnhood_iap_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->delete();
    }
}
