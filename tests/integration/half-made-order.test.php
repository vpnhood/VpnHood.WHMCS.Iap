<?php
/**
 * half-made-order.test.php — AddOrder failing after it created the order, its service and its
 * invoice, before the order names that invoice (what WHMCS 9.1.0's AddOrder can do). placeOrder
 * fails as on any AddOrder error, and removes what that AddOrder made: the invoice is cancelled, so
 * its reminders never ask the store buyer to pay it, and the half-made order is deleted. That
 * invoice carries the store's visible gateway, not the bookkeeping one (AddOrder swaps a hidden
 * gateway for the visible one), so the mail suppression alone would not stop them. A bystander, a
 * Pending order of the same client and product that names no invoice, is left untouched: the
 * cleanup goes by the invoice WHMCS made inside that one AddOrder, never by what an order looks like.
 *
 * Runs ON the dev server. An InvoiceCreated hook registered in this process throws once, for the
 * test buyer only, and notes the invoice and the order it bills. Cleanup removes only those and the
 * bystander. Product: env IAP_TEST_PID, default the first vpnhoodstore product.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'IapRepository.php', 'Provisioning/TermSync.php', 'Provisioning/OrderProvisioner.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\OrderProvisioner;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixture missing: ' . BUYER_EMAIL);
    finish();
}
$buyerId = (int) $buyer['id'];
$pid = (int) (getenv('IAP_TEST_PID') ?: (int) Capsule::table('tblproducts')->where('servertype', 'vpnhoodstore')->orderBy('id')->value('id'));
if ($pid === 0) {
    bad('no vpnhoodstore product exists on this install');
    finish();
}

$armed = false;
$halfInvoiceId = 0;
$halfOrderIds = [];
add_hook('InvoiceCreated', 1, function ($vars) use (&$armed, &$halfInvoiceId, &$halfOrderIds, $buyerId) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($armed && (int) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid') === $buyerId) {
        $armed = false;
        $halfInvoiceId = $invoiceId;
        $serviceIds = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->where('type', 'Hosting')->pluck('relid')->all();
        $halfOrderIds = array_map('intval', Capsule::table('tblhosting')->whereIn('id', $serviceIds)->pluck('orderid')->all());
        throw new \RuntimeException("itest: AddOrder failed after creating invoice #$invoiceId");
    }
});

// a bystander: Pending, same client and product, naming no invoice, as a checkout in flight looks
$bystander = localAPI('AddOrder', [
    'clientid' => $buyerId, 'pid' => $pid, 'billingcycle' => 'monthly',
    'paymentmethod' => OrderProvisioner::GATEWAY, 'noinvoice' => true, 'noemail' => true,
]);
$bystanderId = (int) ($bystander['orderid'] ?? 0);
$bystanderId > 0
    ? ok("bystander order #$bystanderId placed")
    : bad('the bystander order could not be placed: ' . json_encode($bystander));

$armed = true;
try {
    (new OrderProvisioner(new IapRepository()))->placeOrder($buyerId, $pid, 1, 'ITEST.HALF.' . strtoupper(bin2hex(random_bytes(5))), null);
    bad('placeOrder succeeded although AddOrder failed');
} catch (ApiException $e) {
    // AddOrder's own words stay in the log; the client reads provisioning_failed
    ($e->getErrorCode() === 'provisioning_failed' && str_contains(ApiException::logMessage($e), 'itest: AddOrder failed'))
        ? ok('placeOrder fails with AddOrder\'s error, as on any AddOrder error')
        : bad('placeOrder failed with another error: ' . $e->getErrorCode() . ' ' . ApiException::logMessage($e));
} finally {
    $armed = false;
}

$invoice = $halfInvoiceId > 0 ? Capsule::table('tblinvoices')->where('id', $halfInvoiceId)->first(['status', 'paymentmethod']) : null;
try {
    ($halfInvoiceId > 0 && $halfOrderIds !== [])
        ? ok("WHMCS made invoice #$halfInvoiceId for order #" . implode(', #', $halfOrderIds) . ' before AddOrder failed')
        : bad('the hook never saw the half-made invoice and its order: ' . json_encode([$halfInvoiceId, $halfOrderIds]));
    ($invoice?->status === 'Cancelled')
        ? ok('placeOrder cancelled that invoice (it carried ' . json_encode($invoice->paymentmethod) . '), so nothing asks the store buyer to pay it')
        : bad('the half-made invoice was left ' . json_encode($invoice));
    ($halfOrderIds !== [] && Capsule::table('tblorders')->whereIn('id', $halfOrderIds)->doesntExist())
        ? ok('and removed the half-made order')
        : bad('the half-made order survived: ' . json_encode($halfOrderIds));
    Capsule::table('tblorders')->where('id', $bystanderId)->value('status') === 'Pending'
        ? ok('the bystander order is untouched')
        : bad('the bystander order was touched: ' . json_encode(Capsule::table('tblorders')->where('id', $bystanderId)->first()));
    (int) one($db, "SELECT COUNT(*) c FROM mod_vpnhood_iap_log WHERE action='order.halfmade' AND request LIKE ?",
        ['%"invoiceid":' . $halfInvoiceId . ',%'])['c'] === 1
        ? ok('the removal is logged, once')
        : bad('no single order.halfmade line for invoice #' . $halfInvoiceId);
} finally {
    if ($halfInvoiceId > 0 && $invoice?->status !== 'Cancelled') {
        localAPI('UpdateInvoice', ['invoiceid' => $halfInvoiceId, 'status' => 'Cancelled']);
    }
    foreach (array_filter(array_merge($halfOrderIds, [$bystanderId])) as $orderId) {
        if (Capsule::table('tblorders')->where('id', $orderId)->exists()) {
            localAPI('CancelOrder', ['orderid' => (int) $orderId, 'cancelsub' => false]);
            localAPI('DeleteOrder', ['orderid' => (int) $orderId]);
        }
    }
    Capsule::table('tblorders')->whereIn('id', array_filter(array_merge($halfOrderIds, [$bystanderId])))->doesntExist()
        ? ok("cleanup: this test's orders are gone")
        : bad("cleanup left this test's orders behind");
}

finish();
