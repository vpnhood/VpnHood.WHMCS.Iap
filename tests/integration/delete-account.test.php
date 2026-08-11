<?php
/**
 * delete-account.test.php — "forget me" inside the deployed dev WHMCS.
 *
 * Covers the whole contract: the person is erased everywhere, the paid service
 * and the invoice history are NOT, an active web service refuses the deletion
 * before anything is touched, the action is re-runnable, and the real
 * DELETE /account endpoint does all of it over HTTP with a session token.
 *
 * Writes go through localAPI (clients, orders, services) or the module's own
 * mod_vpnhood_iap_* tables — never a raw INSERT/UPDATE on WHMCS core.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'Auth/SessionService.php', 'Provisioning/AccountDeletionService.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
if (!tableExists($db, 'mod_vpnhood_iap_deletions')) {
    bad('mod_vpnhood_iap_deletions missing — WHMCS has not run the module upgrade yet');
    finish();
}

const API_URL = 'https://whmcs-dev.vpnhood.com/modules/addons/vpnhoodiap/api.php';

$marker = 'deltest-' . bin2hex(random_bytes(4));
$clientIds = [];
$userIds = [];
$orderIds = [];

/** A throwaway WHMCS client. */
function makeClient(string $email): int
{
    $result = localAPI('AddClient', [
        'firstname'      => 'Delete',
        'lastname'       => 'Test',
        'email'          => $email,
        'password2'      => bin2hex(random_bytes(12)),
        'country'        => 'US',
        'skipvalidation' => true,
        'noemail'        => true,
    ]);
    if (($result['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddClient failed: ' . json_encode($result));
    }
    return (int) $result['clientid'];
}

/** A module account, optionally attached to a WHMCS client. */
function makeUser(string $marker, ?int $clientId): int
{
    return (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider'             => 'google',
        'provider_subject'     => $marker,
        'email'                => "$marker@vpnhood.test",
        'email_verified_claim' => 1,
        'client_id'            => $clientId,
        'external_uid'         => sprintf('%s-0000-4000-8000-%s', substr(md5($marker), 0, 8), substr(md5($marker), 0, 12)),
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ]);
}

function userRow(int $userId): ?array
{
    $row = Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->first();
    return $row === null ? null : (array) $row;
}

try {
    // == 1. the full erasure ==================================================
    $clientIds['main'] = makeClient("$marker@vpnhood.test");
    $userIds['main'] = makeUser($marker, $clientIds['main']);
    ok("fixture client #{$clientIds['main']} + module user #{$userIds['main']} created");

    Capsule::table('mod_vpnhood_iap_identities')->insert([
        'user_id'    => $userIds['main'],
        'provider'   => 'google',
        'provider_subject' => $marker,
        'email'      => "$marker@vpnhood.test",
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $sessions = new SessionService();
    $tokenA = $sessions->issue($userIds['main'])['token'];
    $tokenB = $sessions->issue($userIds['main'])['token'];

    // a provisioned purchase: it must survive the deletion, minus its owner
    $purchaseId = (int) Capsule::table('mod_vpnhood_iap_purchases')->insertGetId([
        'app_id'       => 0,
        'store'        => 'googleplay',
        'purchase_key' => "$marker-purchase",
        'user_id'      => $userIds['main'],
        'client_id'    => $clientIds['main'],
        'status'       => 'provisioned',
        'expiry_time'  => date('Y-m-d H:i:s', time() + 30 * 86400),
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    (new AccountDeletionService())->deleteUser(userRow($userIds['main']));

    userRow($userIds['main']) === null
        ? ok('the account row is gone')
        : bad('the account row survived the deletion');

    Capsule::table('mod_vpnhood_iap_identities')->where('user_id', $userIds['main'])->count() === 0
        ? ok('every sign-in identity is gone')
        : bad('a sign-in identity survived');

    $liveSessions = 0;
    foreach ([$tokenA, $tokenB] as $token) {
        try {
            $sessions->resolve($token);
            $liveSessions++;
        } catch (ApiException $e) {
            // expected: every device is signed out
        }
    }
    $liveSessions === 0
        ? ok('every session on every device is dead')
        : bad("$liveSessions session(s) still resolve after deletion");

    $purchase = (array) Capsule::table('mod_vpnhood_iap_purchases')->where('id', $purchaseId)->first();
    // the dead pointer is deliberate: the journal keeps the same numeric id, and it is
    // what lets Restore Purchases re-attach the purchase to the person's next account
    ((int) $purchase['user_id'] === $userIds['main'] && $purchase['status'] === 'provisioned')
        ? ok('the purchase survives with a dead owner pointer — the paid gate stays open, restore can re-attach')
        : bad('the purchase was altered: ' . json_encode($purchase));

    $client = (array) Capsule::table('tblclients')->where('id', $clientIds['main'])->first();
    ($client['email'] === "deleted-{$clientIds['main']}@anonymized.invalid"
        && $client['firstname'] === 'Deleted' && $client['lastname'] === 'Account')
        ? ok('the customer record is anonymized with placeholders')
        : bad('anonymization is wrong: ' . json_encode(['email' => $client['email'], 'first' => $client['firstname']]));

    $client['status'] === 'Closed'
        ? ok('the customer record is closed')
        : bad("the customer record is {$client['status']}, expected Closed");

    Capsule::table('mod_vpnhood_iap_deletions')->where('client_id', $clientIds['main'])->count() === 1
        ? ok('the deletion is journalled (numeric ids only)')
        : bad('no journal row was written');

    // == 2. re-running is harmless ============================================
    (new AccountDeletionService())->deleteClient($clientIds['main'], null);
    ok('a second deletion of the same client does not throw');

    // == 3. an active web service refuses, before touching anything ===========
    $productId = (int) (one($db, 'SELECT id FROM tblproducts ORDER BY id LIMIT 1')['id'] ?? 0);
    if ($productId === 0) {
        bad('no product on this install — cannot exercise the active-service guard');
    } else {
        $clientIds['web'] = makeClient("$marker-web@vpnhood.test");
        $order = localAPI('AddOrder', [
            'clientid'      => $clientIds['web'],
            'pid'           => [$productId],
            'billingcycle'  => ['monthly'],
            'paymentmethod' => 'banktransfer',
            'noemail'       => true,
        ]);
        if (($order['result'] ?? '') !== 'success') {
            bad('AddOrder failed: ' . json_encode($order));
        } else {
            $orderIds['web'] = (int) $order['orderid'];
            $serviceId = (int) (one($db, 'SELECT id FROM tblhosting WHERE orderid = ? LIMIT 1', [$orderIds['web']])['id'] ?? 0);
            localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'status' => 'Active']);

            try {
                (new AccountDeletionService())->deleteClient($clientIds['web'], null);
                bad('deletion was allowed despite an active web service');
            } catch (ApiException $e) {
                $e->getHttpStatus() === 409 && $e->getErrorCode() === 'deletion_blocked'
                    ? ok('an active web service blocks the deletion with 409 deletion_blocked')
                    : bad('wrong refusal: ' . $e->getHttpStatus() . ' / ' . $e->getErrorCode());
            }

            $webClient = (array) Capsule::table('tblclients')->where('id', $clientIds['web'])->first();
            ($webClient['email'] === "$marker-web@vpnhood.test" && $webClient['status'] !== 'Closed')
                ? ok('a refused deletion leaves the customer untouched')
                : bad('a refused deletion still modified the customer: ' . json_encode($webClient['email']));
        }
    }

    // == 4. the real endpoint, end to end =====================================
    $clientIds['http'] = makeClient("$marker-http@vpnhood.test");
    $userIds['http'] = makeUser("$marker-http", $clientIds['http']);
    $httpToken = (new SessionService())->issue($userIds['http'])['token'];

    $curl = curl_init(API_URL . '/account');
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $httpToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $status === 204
        ? ok('DELETE /account answers 204 for a signed-in account')
        : bad("DELETE /account answered $status: " . substr($body, 0, 200));

    userRow($userIds['http']) === null
        ? ok('the endpoint erased the account for real')
        : bad('the endpoint answered but the account survived');

    try {
        (new SessionService())->resolve($httpToken);
        bad('the session used to delete still resolves');
    } catch (ApiException $e) {
        ok('the session that performed the deletion is dead');
    }
} finally {
    // == cleanup — test fixtures never linger ================================
    foreach ($userIds as $userId) {
        Capsule::table('mod_vpnhood_iap_sessions')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_identities')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->delete();
    }
    Capsule::table('mod_vpnhood_iap_purchases')->where('purchase_key', "$marker-purchase")->delete();
    foreach ($clientIds as $clientId) {
        Capsule::table('mod_vpnhood_iap_deletions')->where('client_id', $clientId)->delete();
    }
    foreach ($orderIds as $orderId) {
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    foreach ($clientIds as $clientId) {
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    }
    ok('fixtures removed');
}

finish();
