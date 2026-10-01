<?php
/**
 * term-sync.test.php — the WHMCS term, and through it the delivered code, follow the STORE's
 * paid time (TermSync) on every path that moves it: provisioning (the day after the store's
 * expiry, never the order date), a renewal that lands before WHMCS invoiced (the "resynced"
 * path, which must move the code along with the date), a renewal that pays the invoice, a
 * restore carrying a later expiry, and the nightly code repair. And the lifecycle never
 * doubles a service: CANCELED keeps the row provisioned, RESTARTED and a RECOVERED renewal
 * reuse the one service.
 *
 * ⚠ Provisions ONE real order for a DEDICATED client (a real code on the access manager), then
 * deletes everything in cleanup. Needs a hub-shaped install: the code's clock is read live.
 */

require __DIR__ . '/lib/common.php';

requireIapLib(
    'ApiException.php',
    'IapRepository.php',
    'Stores/Dto/PurchaseRecord.php',
    'Stores/Dto/StoreNotification.php',
    'Stores/StoreAdapterInterface.php',
    'Provisioning/AccountService.php',
    'Provisioning/ClientProvisioner.php',
    'Provisioning/OrderProvisioner.php',
    'Provisioning/DeliveryReader.php',
    'Provisioning/TermSync.php',
    'Provisioning/EntitlementService.php',
    'Provisioning/RefundService.php',
    'Provisioning/RenewalService.php',
    'Controllers/NotificationController.php'
);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\Controllers\NotificationController;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\DeliveryReader;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\EntitlementService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\TermSync;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

/** A scripted store: parseNotification and refresh both answer from fields set per step. */
class FakeTermAdapter implements StoreAdapterInterface
{
    public ?StoreNotification $nextNotification = null;
    public ?PurchaseRecord $record = null;

    public function storeId(): string
    {
        return 'googleplay';
    }

    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification
    {
        return $this->nextNotification ?? throw new \RuntimeException('unauthentic');
    }

    public function refresh(array $app, string $purchaseKey, string $storeProductId): PurchaseRecord
    {
        return $this->record ?? throw new \RuntimeException('no record scripted');
    }

    public function verifyPurchase(array $app, array $proof): PurchaseRecord
    {
        return $this->record ?? throw new \RuntimeException('no record scripted');
    }

    public function finalize(array $app, PurchaseRecord $record): void
    {
    }

    public function listVoidedPurchaseKeys(array $app, int $sinceUnix): array
    {
        return [];
    }

    public function stopRenewals(array $app, string $purchaseKey): bool
    {
        return false;
    }
}

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}

// -- pick a provisionable product (same rule as redeem.test.php) --------------
$pid = (int) (getenv('IAP_TEST_PID') ?: 0);
if ($pid === 0) {
    $product = one($db, "SELECT id, name FROM tblproducts WHERE servertype='vpnhoodstore' ORDER BY id LIMIT 1");
    if (!$product) {
        bad('no vpnhoodstore product exists on this install');
        finish();
    }
    $pid = (int) $product['id'];
}

// -- fixtures: app + mapping + a DEDICATED user (fresh mailbox → fresh client) -
$marker = 'ttest-' . bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');
$repo = new IapRepository();
$package = "com.vpnhood.$marker";
$purchaseKey = "$marker-tok";

$appId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
    'store'         => 'googleplay',
    'package_name'  => $package,
    'webhook_token' => bin2hex(random_bytes(24)),
    'status'        => 'active',
    'created_at'    => $now,
    'updated_at'    => $now,
]);
Capsule::table('mod_vpnhood_iap_products')->insert([
    'app_id'               => $appId,
    'store_product_id'     => 'vh_ttest',
    'store_base_plan_id'   => 'monthly',
    'whmcs_product_id'     => $pid,
    'billing_cycle_months' => 1,
    'enabled'              => 1,
]);
$ownerUid = IapRepository::uuidV4();
$ownerUserId = (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
    'provider'             => 'google',
    'provider_subject'     => "$marker-owner",
    'email'                => "$marker@vpnhood.itest",
    'email_verified_claim' => 1,
    'client_id'            => null,
    'external_uid'         => $ownerUid,
    'created_at'           => $now,
    'updated_at'           => $now,
]);
ok("fixtures created (app #$appId, mapping vh_ttest/monthly → pid $pid, user #$ownerUserId)");

$app = $repo->getApp($appId);
$adapter = new FakeTermAdapter();
$controller = new NotificationController($repo);
$reader = new DeliveryReader();
$clientId = 0;
$serviceId = 0;
$orderIds = [];
$messageNo = 0;

$record = fn (string $orderId, int $expiry, string $state = PurchaseRecord::STATE_ACTIVE, bool $autoRenewing = true): PurchaseRecord
    => new PurchaseRecord(
        store: 'googleplay',
        purchaseKey: $purchaseKey,
        storeOrderId: strtoupper($marker) . ".$orderId",
        storeProductId: 'vh_ttest',
        basePlanId: 'monthly',
        obfuscatedUid: $ownerUid,
        state: $state,
        expiryTimeUnix: $expiry,
        autoRenewing: $autoRenewing,
        acknowledged: true,
        linkedPurchaseKey: null,
        isTest: true,
        amount: null,
        currency: null,
        raw: ['fixture' => true],
    );
$notify = function (string $type) use (&$messageNo, $marker, $purchaseKey, $package, $adapter, $controller, $app): string {
    $messageNo++;
    $adapter->nextNotification = new StoreNotification('googleplay', "$marker-m$messageNo", $type, $purchaseKey, null, $package, time(), []);
    $response = $controller->handle($app, $adapter, [], '{}', []);
    return (string) ($response['body']['data']['handled'] ?? json_encode($response));
};
// by reference: the service and client ids are only known after step 1 (an arrow function
// would freeze them at 0)
$dueDate = function () use ($db, &$serviceId): string {
    return (string) one($db, 'SELECT nextduedate FROM tblhosting WHERE id=?', [$serviceId])['nextduedate'];
};
$codeDay = function () use ($reader, &$serviceId): string {
    return substr((string) $reader->readCodeState($serviceId)['expiresAt'], 0, 10);
};
$dayAfter = fn (int $expiry): string => gmdate('Y-m-d', $expiry + 86400);
$serviceCount = function () use ($db, &$clientId): int {
    return (int) one($db, 'SELECT COUNT(*) c FROM tblhosting WHERE userid=?', [$clientId])['c'];
};
$serviceStatus = function () use ($db, &$serviceId): string {
    return (string) one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$serviceId])['domainstatus'];
};
$ledger = fn (): array => one($db, 'SELECT status, auto_renewing, service_id FROM mod_vpnhood_iap_purchases WHERE purchase_key=?', [$purchaseKey]);
$txCount = fn (string $orderId): int => (int) one($db, 'SELECT COUNT(*) c FROM tblaccounts WHERE transid=?', [strtoupper($marker) . ".$orderId"])['c'];
// null when the term ends on/after the day after the store's paid time AND the code matches the
// term; otherwise what is wrong. A renewal that found a WHMCS invoice advances by WHMCS's own
// cycle, which may land past the store's day — forward is fine, behind is the bug.
$covered = function (int $expiry) use ($dueDate, $codeDay, $dayAfter): ?string {
    $want = $dayAfter($expiry);
    if ($dueDate() < $want) {
        return "term {$dueDate()} ends before the paid time ($want)";
    }
    if ($codeDay() !== $dueDate()) {
        return "code {$codeDay()} does not match the term {$dueDate()}";
    }
    return null;
};

// a store expiry at an afternoon hour, 17 days out: a subscription weeks into its cycle, the
// way every migrated or restored one arrives — nowhere near the order date + 1 month
$expiry = gmmktime(15, 44, 0, (int) gmdate('n'), (int) gmdate('j') + 17, (int) gmdate('Y'));

try {
    // ---- 1. provisioning: the term is the day after the store's expiry ----------
    $adapter->record = $record('FIRST', $expiry);
    $result = (new EntitlementService($repo))->redeem($app, $adapter->record, $repo->getUser($ownerUserId), $adapter);
    $result['state'] === 'provisioned' ? ok('purchase provisioned') : bad('initial provisioning failed: ' . json_encode($result));
    $serviceId = (int) ($ledger()['service_id'] ?? 0);
    $service = one($db, 'SELECT userid, orderid FROM tblhosting WHERE id=?', [$serviceId]);
    $clientId = (int) ($service['userid'] ?? 0);
    $orderIds[] = (int) ($service['orderid'] ?? 0);
    ($serviceId > 0 && $clientId > 0)
        ? ok("real client #$clientId and service #$serviceId created")
        : bad('no client/service behind the purchase');
    $dueDate() === $dayAfter($expiry)
        ? ok("the term is the day after the store's expiry ({$dayAfter($expiry)}), not the order date + cycle")
        : bad('term after provisioning: ' . $dueDate() . ', expected ' . $dayAfter($expiry));
    $codeDay() === $dayAfter($expiry)
        ? ok('the code was created with that term')
        : bad('code expiry after provisioning: ' . $codeDay());

    // ---- 2. RENEWED before WHMCS invoiced: the resync moves the term AND the code ----
    $expiry += 30 * 86400;
    $adapter->record = $record('RENEW-1', $expiry);
    $handled = $notify(StoreNotification::RENEWED);
    in_array($handled, ['resynced', 'renewed'], true) ? ok("renewal handled ($handled)") : bad("renewal: $handled");
    ($why = $covered($expiry)) === null
        ? ok('term and code follow the store\'s new paid time')
        : bad("after the renewal: $why");
    $txCount('RENEW-1') === 1 ? ok('the charge is booked once') : bad('renewal charge booked ' . $txCount('RENEW-1') . ' times');

    // ---- 3. the replayed event books nothing twice and moves nothing ----
    $dueBefore = $dueDate();
    $handled = $notify(StoreNotification::RENEWED);
    ($handled === 'skipped-already-paid' && $txCount('RENEW-1') === 1 && $dueDate() === $dueBefore)
        ? ok('replayed renewal: no second booking, term unchanged')
        : bad("renewal replay: $handled, charges " . $txCount('RENEW-1') . ", term $dueBefore → " . $dueDate());

    // ---- 4. CANCELED keeps the row provisioned ----
    $adapter->record = $record('RENEW-1', $expiry, PurchaseRecord::STATE_CANCELED, false);
    $handled = $notify(StoreNotification::CANCELED);
    $row = $ledger();
    ($handled === 'canceled' && $row['status'] === 'provisioned' && (int) $row['auto_renewing'] === 0)
        ? ok('CANCELED: auto-renew off, the row stays provisioned')
        : bad('canceled: ' . json_encode([$handled, $row]));

    // ---- 5. RESTARTED reuses the service — never a second order ----
    $adapter->record = $record('RENEW-1', $expiry);
    $handled = $notify(StoreNotification::RESTARTED);
    $row = $ledger();
    ($handled === 'restarted' && (int) $row['service_id'] === $serviceId && $serviceCount() === 1 && (int) $row['auto_renewing'] === 1)
        ? ok('RESTARTED after a cancel: the same service, no second order')
        : bad('restarted: ' . json_encode([$handled, $row, 'services' => $serviceCount()]));

    // ---- 6. ON_HOLD suspends; RECOVERED is a renewal onto the same service ----
    $handled = $notify(StoreNotification::ON_HOLD);
    ($handled === 'on_hold' && $serviceStatus() === 'Suspended' && $ledger()['status'] === 'on_hold')
        ? ok('ON_HOLD suspended the service')
        : bad('on_hold: ' . json_encode([$handled, $serviceStatus(), $ledger()]));
    $expiry += 30 * 86400;
    $adapter->record = $record('RECOVER-1', $expiry);
    $handled = $notify(StoreNotification::RECOVERED);
    $row = $ledger();
    (str_starts_with($handled, 'recovered-') && (int) $row['service_id'] === $serviceId && $serviceCount() === 1
        && $serviceStatus() === 'Active' && $row['status'] === 'provisioned')
        ? ok("RECOVERED: a renewal onto the held service, re-enabled, no second order ($handled)")
        : bad('recovered: ' . json_encode([$handled, $row, $serviceStatus(), 'services' => $serviceCount()]));
    ($why = $covered($expiry)) === null
        ? ok('…with term and code at the recovered paid time')
        : bad("after the recovery: $why");

    // ---- 7. a restore carrying a later expiry moves the term too (the replay guard) ----
    $expiry += 30 * 86400;
    $adapter->record = $record('RECOVER-1', $expiry);
    $restored = (new EntitlementService($repo))->redeem($app, $adapter->record, $repo->getUser($ownerUserId), $adapter);
    $row = $ledger();
    (($restored['state'] ?? '') === 'provisioned' && (int) $row['service_id'] === $serviceId && $serviceCount() === 1
        && ($why = $covered($expiry)) === null)
        ? ok('a restore with a later store expiry: same service, term and code moved')
        : bad('restore: ' . json_encode([$restored['state'] ?? null, $row, 'services' => $serviceCount(), $why ?? null]));

    // ---- 8. the nightly repair catches a code left behind the term ----
    $sync = new TermSync($repo);
    ($outcome = $sync->sync($serviceId, $adapter->record)) === 'current'
        ? ok('sync is a no-op when the term is current')
        : bad("sync on a current term: $outcome");
    $behind = gmdate('Y-m-d', strtotime($dueDate()) - 10 * 86400);
    localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $behind]);
    localAPI('ModuleCustom', ['serviceid' => $serviceId, 'func_name' => 'Renew']); // the code now ends early
    $termDay = $dayAfter($expiry);
    localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $termDay]); // the term is right again, the code is not
    $codeDay() === $behind ? ok('fixture: the code is 10 days behind the term') : bad('fixture code day: ' . $codeDay() . " (wanted $behind)");
    $repair = $sync->repairCode($serviceId);
    ($repair === 'repaired' && $codeDay() === $termDay)
        ? ok('repairCode re-ran Renew and the code caught up')
        : bad("repairCode: $repair, code " . $codeDay());
    ($outcome = $sync->repairCode($serviceId)) === 'current'
        ? ok('repairCode is a no-op once current')
        : bad("second repair: $outcome");

    // ---- 9. a renewal that pays the invoice: WHMCS advances, the term still ends after the store's hour ----
    // an invoice exists once the term is due: pull the term to tomorrow and let WHMCS generate
    // it — the same GenInvoices call renew() makes on its own when none is outstanding. Not
    // today: WHMCS does not invoice a service on its registration day (observed on 9.0.7).
    localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => gmdate('Y-m-d', time() + 86400)]);
    $dates = one($db, 'SELECT regdate, nextduedate, nextinvoicedate FROM tblhosting WHERE id=?', [$serviceId]);
    $generated = localAPI('GenInvoices', ['clientid' => $clientId]);
    ((int) ($generated['numcreated'] ?? 0) >= 1)
        ? ok('WHMCS generated the renewal invoice for a term due tomorrow')
        : bad('GenInvoices created nothing: ' . json_encode([$generated, $dates]));
    $expiry += 30 * 86400;
    $adapter->record = $record('RENEW-2', $expiry);
    $handled = $notify(StoreNotification::RENEWED);
    $handled === 'renewed' ? ok('the renewal paid the WHMCS renewal invoice') : bad("invoice-paid renewal: $handled");
    $payment = one($db, 'SELECT invoiceid FROM tblaccounts WHERE transid=?', [strtoupper($marker) . '.RENEW-2']);
    $invoiceStatus = $payment ? (string) one($db, 'SELECT status FROM tblinvoices WHERE id=?', [(int) $payment['invoiceid']])['status'] : 'none';
    $invoiceStatus === 'Paid' ? ok('…the invoice is Paid with the store order id') : bad("renewal invoice: $invoiceStatus");
    ($why = $covered($expiry)) === null
        ? ok('…and term and code end after the store\'s paid time, not where WHMCS\'s own cycle landed')
        : bad("after the paid renewal: $why");
} finally {
    // == cleanup — the dedicated client and everything under it ================
    foreach ($orderIds as $orderId) {
        $sid = (int) (one($db, 'SELECT id FROM tblhosting WHERE orderid=?', [$orderId])['id'] ?? 0);
        if ($sid > 0) {
            localAPI('ModuleTerminate', ['serviceid' => $sid]);
        }
        localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    Capsule::table('mod_vpnhood_iap_events')->where('message_id', 'like', "$marker-%")->delete();
    Capsule::table('mod_vpnhood_iap_purchases')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_users')->where('provider_subject', 'like', "$marker%")->delete();
    Capsule::table('mod_vpnhood_iap_products')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    if ($clientId > 0) {
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    }
    ok('fixtures removed (order + dedicated client deleted)');
}

finish();
