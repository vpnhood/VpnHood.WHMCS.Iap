<?php
/**
 * half-made-order.test.php — AddOrder failing after it created the order, its service and its
 * invoice, before the order names that invoice (what WHMCS 9.1.0's AddOrder can do). placeOrder
 * fails as on any AddOrder error, and the store buyer is never asked to pay that invoice: it still
 * carries the bookkeeping gateway at that point, so the suppress-emails hook aborts WHMCS's overdue
 * notice for it. If a WHMCS update moved the gateway swap ahead of the invoice, this test fails
 * and the half-made order needs handling of its own.
 *
 * Runs ON the dev server. An InvoiceCreated hook registered in this process throws once, for the
 * test buyer only. The half-made order is removed in cleanup (its invoice cancelled, the order
 * cancelled and deleted). Product: env IAP_TEST_PID, default the first vpnhoodstore product.
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
add_hook('InvoiceCreated', 1, function ($vars) use (&$armed, $buyerId) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($armed && (int) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid') === $buyerId) {
        $armed = false;
        throw new \RuntimeException("itest: AddOrder failed after creating invoice #$invoiceId");
    }
});

$lastOrder = (int) Capsule::table('tblorders')->max('id');
$armed = true;
try {
    (new OrderProvisioner(new IapRepository()))->placeOrder($buyerId, $pid, 1, 'ITEST.HALF.' . strtoupper(bin2hex(random_bytes(5))), null);
    bad('placeOrder succeeded although AddOrder failed');
} catch (ApiException $e) {
    str_contains($e->getMessage(), 'itest: AddOrder failed')
        ? ok('placeOrder fails with AddOrder\'s error, as on any AddOrder error')
        : bad('placeOrder failed with another error: ' . $e->getMessage());
} finally {
    $armed = false;
}

$half = Capsule::table('tblorders')->where('id', '>', $lastOrder)->where('userid', $buyerId)->get(['id', 'status', 'invoiceid'])->all();
$service = $half !== [] ? Capsule::table('tblhosting')->where('orderid', $half[0]->id)->first(['id']) : null;
$invoiceId = $service ? (int) Capsule::table('tblinvoiceitems')->where('type', 'Hosting')->where('relid', $service->id)->value('invoiceid') : 0;
$invoice = $invoiceId > 0 ? Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['status', 'paymentmethod']) : null;
try {
    (count($half) === 1 && $half[0]->status === 'Pending' && (int) $half[0]->invoiceid === 0 && $invoice?->status === 'Unpaid')
        ? ok("WHMCS left half-made order #{$half[0]->id}: Pending, its Unpaid invoice #$invoiceId not named by it")
        : bad('the hook did not leave the half-made state: ' . json_encode(['orders' => $half, 'invoice' => $invoice]));
    ($invoice?->paymentmethod === OrderProvisioner::GATEWAY)
        ? ok('that invoice still carries the bookkeeping gateway')
        : bad('that invoice carries ' . json_encode($invoice?->paymentmethod) . ', not the bookkeeping gateway');
    $aborted = false;
    foreach ((array) run_hook('EmailPreSend', ['messagename' => 'First Invoice Overdue Notice', 'relid' => $invoiceId]) as $result) {
        $aborted = $aborted || (is_array($result) && !empty($result['abortsend']));
    }
    $aborted
        ? ok('so its overdue notice is aborted: the store buyer is never asked to pay it')
        : bad('the overdue notice for the half-made invoice would be sent');
} finally {
    if ($invoiceId > 0) {
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
    }
    foreach ($half as $order) {
        localAPI('CancelOrder', ['orderid' => (int) $order->id, 'cancelsub' => false]);
        localAPI('DeleteOrder', ['orderid' => (int) $order->id]);
    }
    Capsule::table('tblorders')->where('id', '>', $lastOrder)->where('userid', $buyerId)->doesntExist()
        ? ok('cleanup: the half-made order is gone')
        : bad('cleanup left an order behind');
}

finish();
