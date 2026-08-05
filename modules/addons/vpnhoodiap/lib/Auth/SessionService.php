<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Auth;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Opaque app session tokens: 64 hex chars handed to the app, sha256 at rest,
 * constant-time lookup by hash, TTL + revocation. Not JWTs on purpose —
 * revocable server-side, no signing keys to manage.
 */
class SessionService
{
    public const TTL_SECONDS = 30 * 86400;

    /** last_used_at is only rewritten when older than this, to keep resolve() cheap. */
    private const TOUCH_INTERVAL_SECONDS = 60;

    /** @return array{token:string, expiresAt:string} expiresAt is ISO 8601 UTC */
    public function issue(int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expiresAt = $now + self::TTL_SECONDS;
        Capsule::table('mod_vpnhood_iap_sessions')->insert([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);
        return ['token' => $token, 'expiresAt' => gmdate('c', $expiresAt)];
    }

    /**
     * Resolve a bearer token to its user row (module user, not WHMCS client).
     *
     * @return array the mod_vpnhood_iap_users row plus 'session_id'
     * @throws ApiException 401 when the token is missing/unknown/expired/revoked
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
            ->whereNull('s.revoked_at')
            ->where('s.expires_at', '>', $now)
            ->first(['u.*', 's.id as session_id', 's.last_used_at']);
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

    /** Revoke one token (sign-out). Unknown tokens are ignored — revoke is idempotent. */
    public function revoke(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }
        Capsule::table('mod_vpnhood_iap_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    /** Revoke every session of a user (account deletion / security response). */
    public function revokeAllForUser(int $userId): void
    {
        Capsule::table('mod_vpnhood_iap_sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    /** Cron hygiene: hard-delete sessions expired/revoked for over a week. */
    public function purgeStale(): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - 7 * 86400);
        return Capsule::table('mod_vpnhood_iap_sessions')
            ->where(function ($q) use ($cutoff) {
                $q->where('expires_at', '<', $cutoff)
                  ->orWhere('revoked_at', '<', $cutoff);
            })
            ->delete();
    }
}
