<?php
/**
 * delete-account.test.php — account deletion inside the deployed dev WHMCS.
 *
 * Covers the whole contract: the person is erased everywhere, the paid service
 * and the invoice history are NOT, an active web service no longer blocks —
 * its billing is cancelled at the END of the paid period and journalled with
 * the agreement reference — every invoice is FROZEN with the buyer's real
 * identity before the client row is anonymized, the action is re-runnable,
 * and the real DELETE /account endpoint does all of it over HTTP with a
 * session token. There is deliberately NO deletion-preview endpoint: the
 * confirmation screen warns without listing; nothing is mailed on the way out.
 *
 * Writes go through localAPI (clients, orders, services) or the module's own
 * mod_vpnhood_iap_* tables — never a raw INSERT/UPDATE on WHMCS core (the one
 * exception: cleanup deletes this test's own cancellation-request rows, which
 * no localAPI command can remove).
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
if (!tableExists($db, 'mod_vpnhood_iap_frozen_invoices')) {
    bad('mod_vpnhood_iap_frozen_invoices missing — open the addon page so _upgrade() runs (1.0.13)');
    finish();
}

const API_URL = 'https://whmcs-dev.vpnhood.com/modules/addons/vpnhoodiap/api.php/v1';

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

    // == 3. an active web service NO LONGER blocks — billing stops instead ====
    // (lifecycle §8, decided 2026-08-13: cancel at the END of the paid period,
    // keep the key running, journal the agreement reference.)
    $productId = (int) (one($db, "SELECT id FROM tblproducts WHERE paytype = 'recurring' ORDER BY id LIMIT 1")['id'] ?? 0);
    $webServiceId = 0;
    if ($productId === 0) {
        bad('no recurring product on this install — cannot exercise the cancel-at-period-end path');
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
            $webServiceId = (int) (one($db, 'SELECT id FROM tblhosting WHERE orderid = ? LIMIT 1', [$orderIds['web']])['id'] ?? 0);
            localAPI('UpdateClientProduct', ['serviceid' => $webServiceId, 'status' => 'Active']);

            (new AccountDeletionService())->deleteClient($clientIds['web'], null);
            ok('deletion proceeded despite an active web service — nothing blocks it');

            $cancelRow = one($db, 'SELECT id, type FROM tblcancelrequests WHERE relid = ? ORDER BY id DESC', [$webServiceId]);
            ($cancelRow !== null && stripos((string) $cancelRow['type'], 'End of Billing') !== false)
                ? ok('billing is cancelled at the END of the paid period (cancellation request recorded)')
                : bad('no end-of-period cancellation request: ' . json_encode($cancelRow));

            (one($db, 'SELECT domainstatus FROM tblhosting WHERE id = ?', [$webServiceId])['domainstatus'] ?? '?') === 'Active'
                ? ok('the service keeps running — the key still runs out the time already bought')
                : bad('the service was terminated instead of cancelled at period end');

            $webClient = (array) Capsule::table('tblclients')->where('id', $clientIds['web'])->first();
            // Inactive, not Closed: closing terminates products, and the person's
            // paid-for key must keep running to the end of what was bought
            ($webClient['email'] === "deleted-{$clientIds['web']}@anonymized.invalid" && $webClient['status'] === 'Inactive')
                ? ok('the customer behind the running service is erased (Inactive — closing would kill the key)')
                : bad('web customer not erased: ' . json_encode(['email' => $webClient['email'], 'status' => $webClient['status']]));

            $journal = one($db, 'SELECT details FROM mod_vpnhood_iap_deletions WHERE client_id = ? ORDER BY id DESC', [$clientIds['web']]);
            $details = json_decode((string) ($journal['details'] ?? ''), true);
            (is_array($details) && !empty($details['cancelledAtPeriodEnd'])
                && (int) ($details['cancelledAtPeriodEnd'][0]['service'] ?? 0) === $webServiceId)
                ? ok('the journal keeps the cancelled service + agreement reference (details)')
                : bad('journal details missing the agreement reference: ' . json_encode($journal));

            // == 3b. the invoices are frozen with the buyer's real identity =====
            // The order above generated an invoice; the client row is placeholders
            // by now, so the artifact is the only place the buyer's name survives.
            $frozen = one($db, 'SELECT invoice_id, artifact, sha256 FROM mod_vpnhood_iap_frozen_invoices
                WHERE client_id = ? ORDER BY id LIMIT 1', [$clientIds['web']]);
            if ($frozen === null) {
                bad('no frozen invoice artifact was written for the web client');
            } else {
                $artifact = json_decode((string) $frozen['artifact'], true);
                (($artifact['client']['email'] ?? '') === "$marker-web@vpnhood.test"
                    && ($artifact['client']['firstName'] ?? '') === 'Delete')
                    ? ok('the frozen artifact keeps the buyer\'s real name and address — anonymization never reached it')
                    : bad('frozen artifact lost the buyer identity: ' . substr((string) $frozen['artifact'], 0, 200));
                hash('sha256', (string) $frozen['artifact']) === (string) $frozen['sha256']
                    ? ok('the artifact hash verifies (tamper-evidence)')
                    : bad('artifact sha256 mismatch');
                (is_array($details) && !empty($details['frozenInvoices'])
                    && (int) ($details['frozenInvoices'][0]['id'] ?? 0) === (int) $frozen['invoice_id'])
                    ? ok('the journal carries the frozen-invoice refs')
                    : bad('journal details missing frozenInvoices: ' . json_encode($details));
            }
        }
    }

    // == 4. the real endpoint, end to end =====================================
    $clientIds['http'] = makeClient("$marker-http@vpnhood.test");
    $userIds['http'] = makeUser("$marker-http", $clientIds['http']);
    $httpToken = (new SessionService())->issue($userIds['http'])['token'];

    // NO preview endpoint, deliberately (lifecycle §5/§10): the confirmation shows
    // no codes and no counts — the warning is the whole story
    $curl = curl_init(API_URL . '/account/deletion-preview');
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $httpToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $previewBody = (string) curl_exec($curl);
    $previewStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $previewStatus === 404
        ? ok('GET /account/deletion-preview no longer exists (404) — the screen warns, the mail delivers')
        : bad("deletion-preview should be gone, answered $previewStatus: " . substr($previewBody, 0, 200));

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
        Capsule::table('mod_vpnhood_iap_frozen_invoices')->where('client_id', $clientId)->delete();
    }
    foreach ($orderIds as $orderId) {
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    foreach ($clientIds as $clientId) {
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    }
    if (!empty($webServiceId)) {
        // no localAPI command removes cancellation requests — cleanup-only raw delete
        $db->prepare('DELETE FROM tblcancelrequests WHERE relid = ?')->execute([$webServiceId]);
    }
    ok('fixtures removed');
}

finish();
