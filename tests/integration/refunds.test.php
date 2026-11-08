<?php
/**
 * refunds.test.php — store refunds (RefundService) inside the real dev WHMCS, sent through the
 * webhook as a store would, with a fake adapter: the money follows the charge the store names and
 * is booked once, automatically only for a whole refund of one invoiced payment; a partial refund, a
 * charge on no invoice, two payments, a refund made by hand or a refund grown after a dismissal
 * waits for an admin, who books or dismisses it as shown (a row changed since is refused); the
 * service ends only when the store no longer grants the purchase; a refund WHMCS refuses stays due,
 * alerts once and is booked by the nightly retry, which never touches a purchase renewed since; a
 * charge paid later is booked when its payment appears; a revocation before the install has its
 * refund ledger is answered 503 and recorded nowhere, so the store's redelivery is processed.
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
    'Provisioning/EntitlementService.php',
    'Provisioning/RefundService.php',
    'Provisioning/RenewalService.php',
    'Provisioning/TermSync.php',
    'Controllers/NotificationController.php'
);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\Controllers\NotificationController;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\RefundService;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\StoreNotification;
use WHMCS\Module\Addon\VpnHoodIap\Stores\StoreAdapterInterface;

/** Fake adapter: a scripted notification (auth assumed done) and a scripted store answer. */
class FakeRevokeAdapter implements StoreAdapterInterface
{
    public ?StoreNotification $next = null;
    public ?PurchaseRecord $record = null;

    public function storeId(): string
    {
        return 'googleplay';
    }

    public function parseNotification(array $app, array $headers, string $rawBody, array $query): StoreNotification
    {
        return $this->next ?? throw new \RuntimeException('unauthentic');
    }

    public function refresh(array $app, string $purchaseKey, string $storeProductId): PurchaseRecord
    {
        return $this->record ?? throw new \RuntimeException('refresh deliberately failing');
    }

    public function verifyPurchase(array $app, array $proof): PurchaseRecord
    {
        throw new \RuntimeException('not used');
    }

    public function finalize(array $app, PurchaseRecord $record): void
    {
    }

    public function listVoidedPurchases(array $app, int $sinceUnix): array
    {
        return [];
    }
}

function storeAnswer(string $purchaseKey, string $state, ?string $orderId, ?string $refundedOrderId = null,
    ?int $refundShare = null): PurchaseRecord
{
    return new PurchaseRecord(
        store: 'googleplay', purchaseKey: $purchaseKey, storeOrderId: $orderId, storeProductId: 'vh_test', basePlanId: '',
        obfuscatedUid: null, state: $state,
        expiryTimeUnix: $state === PurchaseRecord::STATE_ACTIVE ? time() + 86400 : time() - 60,
        autoRenewing: false, acknowledged: true, linkedPurchaseKey: null, isTest: true, amount: null, currency: null, raw: [],
        refundedOrderId: $refundedOrderId, refundShare: $refundShare,
    );
}

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixture missing: ' . BUYER_EMAIL);
    finish();
}

$clientId = (int) $buyer['id'];
$marker = 'rtest-' . bin2hex(random_bytes(4));
$M = strtoupper($marker);
$now = date('Y-m-d H:i:s');
$repo = new IapRepository();
$package = "com.vpnhood.$marker";
$appId = (int) Capsule::table('mod_vpnhood_iap_apps')->insertGetId([
    'store' => 'googleplay', 'package_name' => $package, 'webhook_token' => bin2hex(random_bytes(24)),
    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
]);
$app = $repo->getApp($appId);
$controller = new NotificationController($repo);
$adapter = new FakeRevokeAdapter();
$refunds = new RefundService($repo);
$invoices = [];
$keys = 0;
$messages = 0;

/** A purchase row with its charge recorded as the module records it: an invoice paid with the store order id, or a plain transaction. */
$purchase = function (string $orderId, ?string $amount, bool $invoiced = true) use (&$invoices, &$keys, $marker, $appId, $clientId, $now): array {
    $invoiceId = 0;
    if ($amount !== null && $invoiced) {
        $invoiceId = (int) (localAPI('CreateInvoice', [
            'userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => '0', 'paymentmethod' => 'vpnhoodiappay',
            'itemdescription1' => "refund fixture $orderId", 'itemamount1' => $amount, 'itemtaxed1' => '0',
        ])['invoiceid'] ?? 0);
        localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => $orderId, 'gateway' => 'vpnhoodiappay', 'noemail' => true]);
        $invoices[] = $invoiceId;
    } elseif ($amount !== null) {
        localAPI('AddTransaction', ['userid' => $clientId, 'paymentmethod' => 'vpnhoodiappay', 'transid' => $orderId,
            'amountin' => $amount, 'date' => date('Y-m-d'), 'description' => "refund fixture $orderId"]);
    }
    $id = (int) Capsule::table('mod_vpnhood_iap_purchases')->insertGetId([
        'app_id' => $appId, 'store' => 'googleplay', 'purchase_key' => "$marker-tok-" . (++$keys), 'status' => 'provisioned',
        'auto_renewing' => 0, 'store_order_id' => $orderId, 'client_id' => $clientId, 'created_at' => $now, 'updated_at' => $now,
    ]);
    return (array) Capsule::table('mod_vpnhood_iap_purchases')->where('id', $id)->first() + ['invoice' => $invoiceId];
};

/** A store revocation through the webhook: the charge it names (or none), the share refunded, and the store's state then. */
$revoke = function (array $purchase, ?string $orderId, ?int $share, string $storeState, ?string $messageId = null)
    use ($controller, $adapter, $app, $package, $marker, &$messages): array {
    $adapter->next = new StoreNotification('googleplay', $messageId ?? "$marker-m" . (++$messages), StoreNotification::REVOKED,
        $purchase['purchase_key'], null, $package, time(), ['marker' => $marker], $orderId, $share);
    $adapter->record = storeAnswer($purchase['purchase_key'], $storeState, $purchase['store_order_id']);
    return $controller->handle($app, $adapter, [], '{}', []);
};
$ledger = fn (string $charge) => one($db, 'SELECT * FROM mod_vpnhood_iap_refunds WHERE charge_id=?', [$charge]);
$markers = fn (string $charge) => (int) one($db, 'SELECT COUNT(*) c FROM tblaccounts WHERE transid=?', ["$charge-refund"])['c'];
$reversed = fn (string $charge) => (float) (one($db, 'SELECT SUM(amountout) s FROM tblaccounts WHERE transid=?', ["$charge-refund"])['s'] ?? 0);
$invoiceStatus = fn (int $id) => (string) (one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$id])['status'] ?? '');
$status = fn (array $p) => (string) one($db, 'SELECT status FROM mod_vpnhood_iap_purchases WHERE id=?', [$p['id']])['status'];
$handled = fn (array $r) => (string) ($r['body']['data']['handled'] ?? ('HTTP ' . $r['status']));
$alerts = fn (string $charge) => (int) one($db, "SELECT COUNT(*) c FROM mod_vpnhood_iap_log WHERE action='alert' AND response LIKE ?", ["%$charge%"])['c'];

try {
    // ---- before the install has its ledger: 503, nothing recorded, so the store's redelivery counts
    $p0 = $purchase("$M-C0", '1.00');
    Capsule::statement('RENAME TABLE mod_vpnhood_iap_refunds TO mod_vpnhood_iap_refunds_hidden');
    try {
        $r = $revoke($p0, "$M-C0", RefundService::FULL, PurchaseRecord::STATE_EXPIRED, "$marker-redelivered");
        $inbox = (int) one($db, 'SELECT COUNT(*) c FROM mod_vpnhood_iap_events WHERE message_id=?', ["$marker-redelivered"])['c'];
        ($r['status'] === 503 && $inbox === 0 && $status($p0) === 'provisioned')
            ? ok('before the install has its refund ledger, a revocation is answered 503 and recorded nowhere')
            : bad('revocation without a ledger: ' . json_encode([$r, $inbox, $status($p0)]));
    } finally {
        Capsule::statement('RENAME TABLE mod_vpnhood_iap_refunds_hidden TO mod_vpnhood_iap_refunds');
    }
    $r = $revoke($p0, "$M-C0", RefundService::FULL, PurchaseRecord::STATE_EXPIRED, "$marker-redelivered");
    ($handled($r) === 'revoked-booked-ended' && $markers("$M-C0") === 1 && $status($p0) === 'refunded')
        ? ok('…and the redelivery of the same message is processed, not taken for a duplicate')
        : bad('redelivery: ' . json_encode([$handled($r), $markers("$M-C0"), $status($p0)]));

    // ---- a whole refund the store names: booked once, invoice Refunded; the service follows the store
    $p1 = $purchase("$M-C1", '7.90');
    $r = $revoke($p1, "$M-C1", RefundService::FULL, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C1");
    ($handled($r) === 'revoked-booked-kept' && ($row['state'] ?? '') === 'booked' && $markers("$M-C1") === 1
        && abs($reversed("$M-C1") - 7.90) < 0.001 && $invoiceStatus($p1['invoice']) === 'Refunded' && $status($p1) === 'provisioned')
        ? ok('a whole refund the store names is booked once for that charge, its invoice set Refunded, and a purchase the store still grants keeps its service')
        : bad('named refund: ' . json_encode([$handled($r), $row, $markers("$M-C1"), $invoiceStatus($p1['invoice']), $status($p1)]));
    $r = $revoke($p1, "$M-C1", RefundService::FULL, PurchaseRecord::STATE_ACTIVE);
    ($handled($r) === 'revoked-booked-kept' && $markers("$M-C1") === 1)
        ? ok('…a second notification of the same refund books nothing twice')
        : bad('replayed refund: ' . json_encode([$handled($r), $markers("$M-C1")]));

    // ---- a revocation naming no charge books nothing; the store says it is gone, so the service ends
    $p3 = $purchase("$M-C3", '4.90');
    $r = $revoke($p3, null, null, PurchaseRecord::STATE_EXPIRED);
    ($handled($r) === 'revoked-unnamed-ended' && $status($p3) === 'refunded' && $markers("$M-C3") === 0 && $ledger("$M-C3") === null)
        ? ok('a revocation that names no charge books nothing, and ends the purchase the store no longer grants')
        : bad('unnamed revocation: ' . json_encode([$handled($r), $status($p3), $markers("$M-C3")]));
    // the trailing EXPIRED Google sends after a revocation must not relabel it
    $adapter->next = new StoreNotification('googleplay', "$marker-expired", StoreNotification::EXPIRED, $p3['purchase_key'],
        null, $package, time(), []);
    $controller->handle($app, $adapter, [], '{}', []);
    $status($p3) === 'refunded'
        ? ok('…and the EXPIRED that follows keeps it refunded')
        : bad('trailing EXPIRED relabeled the purchase: ' . $status($p3));
    // a store refund is the store's decision: it leaves no record of the account here
    (!tableExists($db, 'mod_vpnhood_iap_refund_marks')
        && (int) one($db, "SELECT COUNT(*) c FROM mod_vpnhood_iap_log WHERE action='refund.repeat'")['c'] === 0)
        ? ok('no refund marks: the account is not remembered for a store refund')
        : bad('refund marks are back');

    // ---- part of a charge: waits for an admin, who books exactly the share shown
    $p5 = $purchase("$M-C5", '8.00');
    $r = $revoke($p5, "$M-C5", 25000, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C5");
    ($handled($r) === 'revoked-review-kept' && $row['reason'] === 'partial' && $markers("$M-C5") === 0)
        ? ok('a partial refund is not booked unasked: it waits for an admin')
        : bad('partial refund: ' . json_encode([$handled($r), $row]));
    $stale = $refunds->adminAction((int) $row['id'], (int) $row['version'] - 1, 'book');
    ($stale['outcome'] === 'changed' && $markers("$M-C5") === 0)
        ? ok('…an admin action on a version of the row that changed since is refused')
        : bad('stale admin action: ' . json_encode($stale));
    $booked = $refunds->adminAction((int) $row['id'], (int) $row['version'], 'book');
    ($booked['outcome'] === 'booked' && abs($reversed("$M-C5") - 2.00) < 0.001 && $invoiceStatus($p5['invoice']) === 'Paid')
        ? ok('…"Book" reverses exactly the 25% shown, and the invoice stays Paid')
        : bad('partial booking: ' . json_encode([$booked, $reversed("$M-C5"), $invoiceStatus($p5['invoice'])]));

    // ---- revoked without a refund (Apple Family Sharing): nothing to reverse
    $p6 = $purchase("$M-C6", '3.00');
    $revoke($p6, "$M-C6", 0, PurchaseRecord::STATE_EXPIRED);
    $row = $ledger("$M-C6");
    (($row['state'] ?? '') === 'none' && $row['reason'] === 'no-refund' && $markers("$M-C6") === 0 && $status($p6) === 'refunded')
        ? ok('a revocation without a refund books nothing, and the service ends')
        : bad('revocation without refund: ' . json_encode([$row, $status($p6)]));

    // ---- WHMCS refuses the booking: due, one alert, retried nightly; the retry never touches a renewal since
    $p7 = $purchase("$M-C7", '5.90');
    Capsule::table('tblaccounts')->where('transid', "$M-C7")->update(['userid' => 999999999]); // AddTransaction: "Client ID Not Found"
    $r = $revoke($p7, "$M-C7", RefundService::FULL, PurchaseRecord::STATE_EXPIRED);
    $row = $ledger("$M-C7");
    $event = one($db, 'SELECT status FROM mod_vpnhood_iap_events WHERE message_id=?', ["$marker-m$messages"]);
    ($handled($r) === 'failed-recorded' && ($event['status'] ?? '') === 'failed' && $row['state'] === 'due'
        && (int) $row['attempts'] === 1 && $markers("$M-C7") === 0 && $alerts("$M-C7") === 1 && $status($p7) === 'refunded')
        ? ok('a refund WHMCS refuses stays due with one alert, the event fails, and the service still ends')
        : bad('refused refund: ' . json_encode([$handled($r), $event, $row, $alerts("$M-C7"), $status($p7)]));
    $listed = array_values(array_filter($refunds->checkList(), fn ($l) => $l['charge'] === "$M-C7"));
    (($listed[0]['actions'] ?? []) === ['retry', 'dismiss'])
        ? ok('…it is listed for an admin, to retry or dismiss')
        : bad('refused refund not listed: ' . json_encode($listed));
    $refunds->retryDue();
    $row = $ledger("$M-C7");
    ($row['state'] === 'due' && (int) $row['attempts'] === 2 && $alerts("$M-C7") === 1)
        ? ok('…a nightly retry WHMCS still refuses counts it again, without another alert')
        : bad('refused retry: ' . json_encode([$row, $alerts("$M-C7")]));
    // the purchase renews meanwhile: a new charge and status; the owed refund is still the old charge's
    Capsule::table('mod_vpnhood_iap_purchases')->where('id', $p7['id'])->update(['store_order_id' => "$M-C7B", 'status' => 'provisioned']);
    Capsule::table('tblaccounts')->where('transid', "$M-C7")->update(['userid' => $clientId]);
    $refunds->retryDue();
    ($ledger("$M-C7")['state'] === 'booked' && $markers("$M-C7") === 1 && $markers("$M-C7B") === 0 && $status($p7) === 'provisioned')
        ? ok('…and books it once WHMCS takes it: the refunded charge only, the renewed purchase untouched')
        : bad('owed retry: ' . json_encode([$ledger("$M-C7"), $markers("$M-C7B"), $status($p7)]));

    // ---- a charge on no invoice: only a person can tell it was not refunded by hand
    $p8 = $purchase("$M-C8", '3.30', invoiced: false);
    $revoke($p8, "$M-C8", RefundService::FULL, PurchaseRecord::STATE_EXPIRED);
    $row = $ledger("$M-C8");
    (($row['state'] ?? '') === 'review' && $row['reason'] === 'uninvoiced' && $markers("$M-C8") === 0)
        ? ok('a refund of a charge on no invoice waits for an admin')
        : bad('uninvoiced refund: ' . json_encode($row));
    $booked = $refunds->adminAction((int) $row['id'], (int) $row['version'], 'book');
    ($booked['outcome'] === 'booked' && abs($reversed("$M-C8") - 3.30) < 0.001)
        ? ok('…and "Book" books it whole')
        : bad('uninvoiced booking: ' . json_encode([$booked, $reversed("$M-C8")]));

    // ---- an invoice already refunded by hand: never booked on top; Dismiss, and a refund grown since comes back
    $p9 = $purchase("$M-C9", '2.50');
    localAPI('AddTransaction', ['userid' => $clientId, 'invoiceid' => $p9['invoice'], 'paymentmethod' => 'vpnhoodiappay',
        'transid' => "$M-C9-MANUAL", 'amountout' => '2.50', 'date' => date('Y-m-d'), 'description' => 'an admin\'s refund by hand']);
    $revoke($p9, "$M-C9", RefundService::FULL, PurchaseRecord::STATE_EXPIRED);
    $row = $ledger("$M-C9");
    (($row['state'] ?? '') === 'review' && $row['reason'] === 'manual' && $markers("$M-C9") === 0
        && ($refunds->checkList()[0] ?? null) !== null)
        ? ok('a refund on an invoice already refunded by hand is never booked on top: it waits for an admin')
        : bad('refund made by hand: ' . json_encode($row));
    $dismissed = $refunds->adminAction((int) $row['id'], (int) $row['version'], 'dismiss');
    $revoke($p9, "$M-C9", RefundService::FULL, PurchaseRecord::STATE_EXPIRED);
    ($dismissed['outcome'] === 'dismissed' && $ledger("$M-C9")['state'] === 'skipped' && $markers("$M-C9") === 0)
        ? ok('…"Dismiss" books nothing, and the same refund reported again stays dismissed')
        : bad('dismissed refund: ' . json_encode([$dismissed, $ledger("$M-C9")]));
    $p9b = $purchase("$M-C9B", '4.00');
    $revoke($p9b, "$M-C9B", null, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C9B");
    $refunds->adminAction((int) $row['id'], (int) $row['version'], 'dismiss');
    $revoke($p9b, "$M-C9B", RefundService::FULL, PurchaseRecord::STATE_EXPIRED);
    $row = $ledger("$M-C9B");
    ($row['state'] === 'review' && $row['reason'] === 'increase' && $markers("$M-C9B") === 0)
        ? ok('…but a refund the store later reports larger than what was dismissed comes back for review')
        : bad('grown refund after a dismissal: ' . json_encode($row));

    // ---- two payments carry the charge id: never guessed. WHMCS's API refuses a second transaction
    // with the same id, so the copy is written directly, as only an edit outside the API could make it
    $p10 = $purchase("$M-C10", '1.10', invoiced: false);
    $copy = (array) Capsule::table('tblaccounts')->where('transid', "$M-C10")->first();
    unset($copy['id']);
    Capsule::table('tblaccounts')->insert($copy);
    $revoke($p10, "$M-C10", RefundService::FULL, PurchaseRecord::STATE_EXPIRED);
    $row = $ledger("$M-C10");
    (($row['state'] ?? '') === 'review' && $row['reason'] === 'ambiguous' && $markers("$M-C10") === 0)
        ? ok('a charge id two payments carry waits for an admin')
        : bad('ambiguous payment: ' . json_encode($row));

    // ---- no payment recorded yet: nothing to reverse, until the payment appears
    $p11 = $purchase("$M-C11", null);
    $revoke($p11, "$M-C11", RefundService::FULL, PurchaseRecord::STATE_EXPIRED);
    $revoke($p11, "$M-C11", 25000, PurchaseRecord::STATE_EXPIRED); // a delayed, smaller report never lowers it
    $before = $ledger("$M-C11");
    ((int) $before['share'] === RefundService::FULL)
        ? ok('a row keeps the largest refund the store reported: a later, smaller report never lowers it')
        : bad('share lowered: ' . json_encode($before));
    $late = (int) (localAPI('CreateInvoice', [
        'userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => '0', 'paymentmethod' => 'vpnhoodiappay',
        'itemdescription1' => "refund fixture $M-C11", 'itemamount1' => '6.60', 'itemtaxed1' => '0',
    ])['invoiceid'] ?? 0);
    $invoices[] = $late;
    localAPI('AddInvoicePayment', ['invoiceid' => $late, 'transid' => "$M-C11", 'gateway' => 'vpnhoodiappay', 'noemail' => true]);
    $found = $refunds->recheckNoPayment();
    (($before['state'] ?? '') === 'none' && $before['reason'] === 'no-payment' && $found >= 1
        && $ledger("$M-C11")['state'] === 'booked' && abs($reversed("$M-C11") - 6.60) < 0.001)
        ? ok('a refund of a charge not paid yet is booked once its payment is recorded')
        : bad('late payment: ' . json_encode([$before, $found, $ledger("$M-C11")]));

    // ---- the service: never ended for a purchase that changed since the store was read
    $p12 = $purchase("$M-C12", '1.00');
    Capsule::table('mod_vpnhood_iap_purchases')->where('id', $p12['id'])->update(['store_order_id' => "$M-C12B"]);
    $asked = false;
    $outcome = $refunds->endEntitlement($p12, function () use (&$asked, $p12) {
        $asked = true;
        return storeAnswer($p12['purchase_key'], PurchaseRecord::STATE_EXPIRED, null);
    }, 'test');
    ($outcome === 'changed' && !$asked && $status($p12) === 'provisioned')
        ? ok('a purchase that recorded a new charge since the store was read is left for the next refresh')
        : bad('changed purchase: ' . json_encode([$outcome, $asked, $status($p12)]));

    // ---- EXPIRED: ended under the same lock, and only while the store, read again, still says so
    $p13 = $purchase("$M-C13", '1.00');
    $expire = function (array $p, string $storeState, string $id) use ($adapter, $controller, $app, $package): array {
        $adapter->next = new StoreNotification('googleplay', $id, StoreNotification::EXPIRED, $p['purchase_key'], null,
            $package, time(), []);
        $adapter->record = storeAnswer($p['purchase_key'], $storeState, $p['store_order_id']);
        return $controller->handle($app, $adapter, [], '{}', []);
    };
    $kept = $expire($p13, PurchaseRecord::STATE_ACTIVE, "$marker-x1");
    $ended = $expire($p13, PurchaseRecord::STATE_EXPIRED, "$marker-x2");
    ($handled($kept) === 'expired-kept' && $handled($ended) === 'expired-ended' && $status($p13) === 'expired')
        ? ok('an EXPIRED the store contradicts (renewed meanwhile) keeps the service; one it confirms ends it')
        : bad('expired: ' . json_encode([$handled($kept), $handled($ended), $status($p13)]));
    // its refund notification lost, an EXPIRED whose fresh store answer is a revocation records the refund first
    $p13b = $purchase("$M-C13B", '2.20');
    $adapter->next = new StoreNotification('googleplay', "$marker-x3", StoreNotification::EXPIRED, $p13b['purchase_key'], null,
        $package, time(), []);
    $adapter->record = storeAnswer($p13b['purchase_key'], PurchaseRecord::STATE_REVOKED, "$M-C13B", "$M-C13B", RefundService::FULL);
    $r = $controller->handle($app, $adapter, [], '{}', []);
    ($handled($r) === 'expired-ended' && $status($p13b) === 'refunded' && ($ledger("$M-C13B")['state'] ?? '') === 'booked'
        && $markers("$M-C13B") === 1)
        ? ok('…and one whose fresh answer is a revocation books the refund it names, and closes the purchase as refunded')
        : bad('expired, revoked: ' . json_encode([$handled($r), $status($p13b), $ledger("$M-C13B")]));

    // ---- a payment hold: suspended only while the store still says so
    $p19 = $purchase("$M-C19", '1.00');
    $hold = function (array $p, string $storeState, string $id) use ($adapter, $controller, $app, $package): array {
        $adapter->next = new StoreNotification('googleplay', $id, StoreNotification::ON_HOLD, $p['purchase_key'], null,
            $package, time(), []);
        $adapter->record = storeAnswer($p['purchase_key'], $storeState, $p['store_order_id']);
        return $controller->handle($app, $adapter, [], '{}', []);
    };
    $late = $hold($p19, PurchaseRecord::STATE_ACTIVE, "$marker-h1");
    $statusAfterLate = $status($p19);
    $held = $hold($p19, PurchaseRecord::STATE_ON_HOLD, "$marker-h2");
    ($handled($late) === 'on_hold-kept' && $statusAfterLate === 'provisioned' && $handled($held) === 'on_hold' && $status($p19) === 'on_hold')
        ? ok('a hold notification the store contradicts (recovered meanwhile) keeps the service; one it confirms holds it')
        : bad('hold: ' . json_encode([$handled($late), $statusAfterLate, $handled($held), $status($p19)]));
    $p20 = $purchase("$M-C20", '1.00');
    $lapsed = $hold($p20, PurchaseRecord::STATE_EXPIRED, "$marker-h3");
    ($handled($lapsed) === 'on_hold-ended' && $status($p20) === 'expired')
        ? ok('…and a late one for a purchase the store has let expire ends it, never parks it on hold')
        : bad('late hold of an expired purchase: ' . json_encode([$handled($lapsed), $status($p20)]));

    // ---- a refund booked before for less than the store refunded is never taken as settled
    $p14 = $purchase("$M-C14", '4.00');
    localAPI('AddTransaction', ['userid' => $clientId, 'invoiceid' => $p14['invoice'], 'paymentmethod' => 'vpnhoodiappay',
        'transid' => "$M-C14-refund", 'amountout' => '1.00', 'date' => date('Y-m-d'), 'description' => 'a refund of part, by hand']);
    $revoke($p14, "$M-C14", RefundService::FULL, PurchaseRecord::STATE_EXPIRED);
    $row = $ledger("$M-C14");
    ($row['state'] === 'review' && $row['reason'] === 'increase' && (int) $row['booked_share'] === 25000
        && $markers("$M-C14") === 1 && $invoiceStatus($p14['invoice']) === 'Paid')
        ? ok('a refund booked before for 25% of a charge the store refunded whole goes to review, its invoice left Paid')
        : bad('short booking: ' . json_encode([$row, $invoiceStatus($p14['invoice'])]));

    // ---- a refund in review grows: what the admin sees moves with it
    $p15 = $purchase("$M-C15", '10.00');
    $revoke($p15, "$M-C15", 25000, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C15");
    $refunds->adminAction((int) $row['id'], (int) $row['version'], 'book');
    $revoke($p15, "$M-C15", 30000, PurchaseRecord::STATE_ACTIVE);
    $at30 = $ledger("$M-C15");
    $revoke($p15, "$M-C15", RefundService::FULL, PurchaseRecord::STATE_ACTIVE);
    $at100 = $ledger("$M-C15");
    ($at30['reason'] === 'increase' && (int) $at30['share'] === 30000 && (int) $at100['share'] === RefundService::FULL
        && (int) $at100['version'] > (int) $at30['version'] && str_contains((string) $at100['note'], '100%') && $markers("$M-C15") === 1)
        ? ok('a booked refund the store reports larger goes to review, and each larger report updates it there')
        : bad('growing review: ' . json_encode([$at30, $at100]));

    // ---- a fresh refund of unknown size reopens a dismissed one (it may be a further refund)
    $p16 = $purchase("$M-C16", '3.00');
    $revoke($p16, "$M-C16", null, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C16");
    $refunds->adminAction((int) $row['id'], (int) $row['version'], 'dismiss');
    $revoke($p16, "$M-C16", null, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C16");
    ($row['state'] === 'review' && $row['reason'] === 'increase')
        ? ok('a new notification of a refund of unknown size reopens a dismissed one for review')
        : bad('unknown further refund: ' . json_encode($row));
    $p16b = $purchase("$M-C16B", '6.00');
    $revoke($p16b, "$M-C16B", 25000, PurchaseRecord::STATE_ACTIVE);
    $revoke($p16b, "$M-C16B", null, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C16B");
    $listed = array_values(array_filter($refunds->checkList(), fn ($l) => $l['charge'] === "$M-C16B"));
    ($row['reason'] === 'increase' && ($listed[0]['actions'] ?? []) === ['dismiss'] && $markers("$M-C16B") === 0)
        ? ok('…and a partial refund followed by one of unknown size is no longer bookable as if the known part were all')
        : bad('partial then unknown: ' . json_encode([$row, $listed]));

    // ---- a partial refund whose payment appears later goes back to review, never booked unasked
    $p17 = $purchase("$M-C17", null);
    $revoke($p17, "$M-C17", 50000, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C17");
    $refunds->adminAction((int) $row['id'], (int) $row['version'], 'book'); // no payment yet: nothing to book
    $waiting = $ledger("$M-C17");
    $late17 = (int) (localAPI('CreateInvoice', [
        'userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => '0', 'paymentmethod' => 'vpnhoodiappay',
        'itemdescription1' => "refund fixture $M-C17", 'itemamount1' => '2.00', 'itemtaxed1' => '0',
    ])['invoiceid'] ?? 0);
    $invoices[] = $late17;
    localAPI('AddInvoicePayment', ['invoiceid' => $late17, 'transid' => "$M-C17", 'gateway' => 'vpnhoodiappay', 'noemail' => true]);
    $refunds->recheckNoPayment();
    $row = $ledger("$M-C17");
    ($waiting['state'] === 'none' && $row['state'] === 'review' && $row['reason'] === 'partial' && $markers("$M-C17") === 0)
        ? ok('a partial refund whose payment appears later goes back to review, not booked unasked')
        : bad('late payment of a partial: ' . json_encode([$waiting, $row]));
    $p17b = $purchase("$M-C17B", null);
    $revoke($p17b, "$M-C17B", 50000, PurchaseRecord::STATE_ACTIVE);
    $row = $ledger("$M-C17B");
    $refunds->adminAction((int) $row['id'], (int) $row['version'], 'book'); // no payment: waits for it
    $revoke($p17b, "$M-C17B", null, PurchaseRecord::STATE_ACTIVE); // and a further refund of unknown size
    $row = $ledger("$M-C17B");
    ($row['state'] === 'review' && $row['reason'] === 'increase')
        ? ok('…and a further refund of unknown size while it waits for its payment is kept for review, not lost')
        : bad('unknown refund while waiting: ' . json_encode($row));

    // ---- a delivery seen before but unfinished: 503, never "duplicate"; taken over once abandoned
    $p18 = $purchase("$M-C18", '1.50');
    Capsule::table('mod_vpnhood_iap_events')->insert(['store' => 'googleplay', 'message_id' => "$marker-unfinished",
        'event_type' => StoreNotification::REVOKED, 'purchase_key' => $p18['purchase_key'], 'status' => 'received',
        'created_at' => date('Y-m-d H:i:s')]);
    $busy = $revoke($p18, "$M-C18", RefundService::FULL, PurchaseRecord::STATE_EXPIRED, "$marker-unfinished");
    Capsule::table('mod_vpnhood_iap_events')->where('message_id', "$marker-unfinished")
        ->update(['created_at' => date('Y-m-d H:i:s', time() - 3600)]);
    $taken = $revoke($p18, "$M-C18", RefundService::FULL, PurchaseRecord::STATE_EXPIRED, "$marker-unfinished");
    $again = $revoke($p18, "$M-C18", RefundService::FULL, PurchaseRecord::STATE_EXPIRED, "$marker-unfinished");
    ($busy['status'] === 503 && $handled($taken) === 'revoked-booked-ended' && $handled($again) === 'duplicate' && $markers("$M-C18") === 1)
        ? ok('a delivery whose first copy is unfinished gets 503; an abandoned one is taken over; a finished one is a duplicate')
        : bad('unfinished delivery: ' . json_encode([$busy['status'], $handled($taken), $handled($again)]));
} finally {
    if (!tableExists($db, 'mod_vpnhood_iap_refunds') && tableExists($db, 'mod_vpnhood_iap_refunds_hidden')) {
        Capsule::statement('RENAME TABLE mod_vpnhood_iap_refunds_hidden TO mod_vpnhood_iap_refunds');
    }
    Capsule::table('mod_vpnhood_iap_events')->where('message_id', 'like', "$marker-%")->delete();
    Capsule::table('mod_vpnhood_iap_refunds')->where('charge_id', 'like', "$M-%")->delete();
    Capsule::table('mod_vpnhood_iap_log')->where('response', 'like', "%$M-%")->delete();
    Capsule::table('mod_vpnhood_iap_log')->where('request', 'like', "%$M-%")->delete();
    Capsule::table('mod_vpnhood_iap_purchases')->where('app_id', $appId)->delete();
    Capsule::table('mod_vpnhood_iap_apps')->where('id', $appId)->delete();
    foreach ($invoices as $invoiceId) {
        if ($invoiceId > 0 && in_array($invoiceStatus($invoiceId), ['Paid', 'Unpaid'], true)) {
            localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        }
    }
    Capsule::table('tblaccounts')->where('transid', 'like', "$M-%")->delete();
    ok('fixtures cleaned up');
}

finish();
