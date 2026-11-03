<?php
/**
 * provisioning-failure.test.php — placeOrder when provisioning fails, as when the access manager
 * is down. The code is created before the store's payment is recorded, so a failed provisioning
 * removes the order unpaid and leaves no payment behind (no API removes one); a create that
 * failed after recording its code keeps the order, loudly, when that code cannot be ended; the
 * purchase's retry, with the same store transaction id, delivers one paid order on the store's
 * term, and the ledger learns it as soon as the code exists. A payment that cannot be recorded
 * once the code exists keeps the delivered order and says so.
 *
 * Runs ON the dev server. Hooks registered in this process act for the test buyer only: one
 * aborts every module create while armed (recording a made-up token id first, when asked), one
 * cancels the order's invoice before its payment. Delivers TWO real codes on the access manager,
 * terminated and their orders deleted in cleanup. Product: env IAP_TEST_PID, default the first
 * vpnhoodstore product.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'IapRepository.php', 'Provisioning/TermSync.php', 'Provisioning/OrderProvisioner.php',
    'Provisioning/DeliveryReader.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\DeliveryReader;
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

$createFails = false;
$recordMadeUpToken = false;
add_hook('PreModuleCreate', 1, function ($vars) use (&$createFails, &$recordMadeUpToken, $buyerId) {
    if (!$createFails || (int) ($vars['params']['userid'] ?? 0) !== $buyerId) {
        return [];
    }
    if ($recordMadeUpToken) {
        // a create that made its code, recorded its id and then failed (as when reading the code back fails)
        \WHMCS\Service\Service::find((int) $vars['params']['serviceid'])?->serviceProperties
            ->save(['accessTokenId' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffff))]);
    }
    return ['abortcmd' => true];
});
$cancelInvoice = false;
add_hook('AfterModuleCreate', 1, function ($vars) use (&$cancelInvoice, $buyerId) {
    $serviceId = (int) ($vars['params']['serviceid'] ?? 0);
    if ($cancelInvoice && (int) ($vars['params']['userid'] ?? 0) === $buyerId) {
        $cancelInvoice = false;
        $invoiceId = (int) Capsule::table('tblinvoiceitems')->where('type', 'Hosting')->where('relid', $serviceId)->value('invoiceid');
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
    }
});

$orders = new OrderProvisioner(new IapRepository());
$dueDate = date('Y-m-d', strtotime('+40 days'));
$transactionId = 'ITEST.FAIL.' . strtoupper(bin2hex(random_bytes(5)));
$lastOrder = (int) Capsule::table('tblorders')->max('id');
$lastInvoice = (int) Capsule::table('tblinvoices')->max('id');
$lastLog = (int) Capsule::table('mod_vpnhood_iap_log')->max('id');
$payments = fn (string $transid) => Capsule::table('tblaccounts')->where('transid', $transid)->count();
$delivered = [];

try {
    // 1. the code cannot be created: the order goes, unpaid
    $createFails = true;
    try {
        $orders->placeOrder($buyerId, $pid, 1, $transactionId, $dueDate);
        bad('placeOrder succeeded although no code could be created');
    } catch (ApiException $e) {
        str_contains($e->getMessage(), 'AcceptOrder failed')
            ? ok('placeOrder fails when the code cannot be created')
            : bad('placeOrder failed with another error: ' . $e->getMessage());
    } finally {
        $createFails = false;
    }
    Capsule::table('tblorders')->where('id', '>', $lastOrder)->where('userid', $buyerId)->doesntExist()
        ? ok('the failed order is removed')
        : bad('the failed order survived: ' . json_encode(Capsule::table('tblorders')->where('id', '>', $lastOrder)->where('userid', $buyerId)->get(['id', 'status'])));
    $billed = Capsule::table('tblinvoices')->where('id', '>', $lastInvoice)->where('userid', $buyerId)
        ->whereIn('status', ['Paid', 'Unpaid'])->pluck('status', 'id')->all();
    ($billed === [] && $payments($transactionId) === 0)
        ? ok('nothing is paid or billed: no invoice open, no payment recorded')
        : bad('the failure left billing behind: ' . json_encode(['invoices' => $billed, 'payments' => $payments($transactionId)]));
    $rollback = Capsule::table('mod_vpnhood_iap_log')->where('id', '>', $lastLog)->where('action', 'order.rollback')->value('response');
    ($rollback !== null && str_contains($rollback, '"delete":"success"'))
        ? ok('the rollback is logged: ' . $rollback)
        : bad('no complete rollback logged: ' . json_encode($rollback));

    // 1b. the create recorded a code before it failed, and that code cannot be ended: the order stays, loudly
    $createFails = true;
    $recordMadeUpToken = true;
    $lastLog = (int) Capsule::table('mod_vpnhood_iap_log')->max('id');
    try {
        $orders->placeOrder($buyerId, $pid, 1, $transactionId . '-REC', $dueDate);
        bad('placeOrder succeeded although the create failed');
    } catch (ApiException) {
    } finally {
        $createFails = false;
        $recordMadeUpToken = false;
    }
    $kept = Capsule::table('tblorders')->where('id', '>', $lastOrder)->where('userid', $buyerId)->first(['id', 'status']);
    $keptServiceId = $kept ? (int) Capsule::table('tblhosting')->where('orderid', $kept->id)->value('id') : 0;
    ($kept !== null && $kept->status === 'Cancelled' && (new DeliveryReader())->holdsCode($keptServiceId))
        ? ok("an order whose recorded code could not be ended is kept (#{$kept->id}, Cancelled), its code's record with it")
        : bad('the order with a recorded code: ' . json_encode($kept));
    $alert = (string) Capsule::table('mod_vpnhood_iap_log')->where('id', '>', $lastLog)->where('action', 'alert')
        ->where('response', 'like', '%could not be removed%')->value('response');
    str_contains($alert, "service #$keptServiceId")
        ? ok('and says so: ' . $alert)
        : bad('no alert names the live code');
    if ($kept !== null) {
        localAPI('DeleteOrder', ['orderid' => (int) $kept->id]);
    }

    // 2. the purchase's retry, with the same store transaction id, delivers one paid order on the store's term;
    // the ledger learns the order as soon as the code exists, its status left to the caller
    $purchaseId = (int) Capsule::table('mod_vpnhood_iap_purchases')->insertGetId([
        'app_id' => (int) Capsule::table('mod_vpnhood_iap_apps')->min('id'), 'store' => 'googleplay',
        'purchase_key' => 'itest-fail-' . bin2hex(random_bytes(6)), 'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $order = $orders->placeOrder($buyerId, $pid, 1, $transactionId, $dueDate, $purchaseId);
    $delivered[] = $order;
    $ledger = Capsule::table('mod_vpnhood_iap_purchases')->where('id', $purchaseId)->first(['service_id', 'whmcs_order_id', 'status']);
    ((int) $ledger->service_id === $order['serviceId'] && (int) $ledger->whmcs_order_id === $order['orderId'] && $ledger->status === 'pending')
        ? ok('the ledger row names the delivered order and service, its status untouched')
        : bad('the ledger row after delivery: ' . json_encode($ledger));
    Capsule::table('mod_vpnhood_iap_purchases')->where('id', $purchaseId)->delete();
    $service = Capsule::table('tblhosting')->where('id', $order['serviceId'])->first(['domainstatus', 'nextduedate']);
    $status = (string) Capsule::table('tblinvoices')->where('id', $order['invoiceId'])->value('status');
    ($service->domainstatus === 'Active' && $status === 'Paid' && $payments($transactionId) === 1)
        ? ok('the retry delivers: service Active, invoice Paid, one payment recorded')
        : bad('the retry: ' . json_encode(['service' => $service, 'invoice' => $status, 'payments' => $payments($transactionId)]));
    ((string) $service->nextduedate === $dueDate)
        ? ok("the service carries the store's term after its payment ($dueDate)")
        : bad("the service's term is {$service->nextduedate}, the store's is $dueDate");
    ((string) (new DeliveryReader())->readAccessCode($order['serviceId']) !== '')
        ? ok('its access code is readable')
        : bad('no access code on the delivered service');

    // 3. a payment that cannot be recorded once the code exists: the order stays delivered, loudly
    $unpaidTransactionId = $transactionId . '-UNPAID';
    $cancelInvoice = true;
    $lastLog = (int) Capsule::table('mod_vpnhood_iap_log')->max('id');
    try {
        $order = $orders->placeOrder($buyerId, $pid, 1, $unpaidTransactionId, $dueDate);
        $delivered[] = $order;
        $service = (string) Capsule::table('tblhosting')->where('id', $order['serviceId'])->value('domainstatus');
        $status = (string) Capsule::table('tblinvoices')->where('id', $order['invoiceId'])->value('status');
        ($service === 'Active' && $status !== 'Paid')
            ? ok("an unrecordable payment keeps the delivered order (service Active, invoice $status)")
            : bad('the payment step: ' . json_encode(['service' => $service, 'invoice' => $status]));
        $alert = (string) Capsule::table('mod_vpnhood_iap_log')->where('id', '>', $lastLog)->where('action', 'alert')
            ->where('response', 'like', "%invoice #{$order['invoiceId']}%")->value('response');
        (str_contains($alert, 'Record it by hand') && str_contains($alert, "next due date back to $dueDate"))
            ? ok('and says so: ' . $alert)
            : bad('no alert for the unrecorded payment');
    } catch (\Throwable $e) {
        bad('an unrecordable payment failed the delivered order: ' . $e->getMessage());
    } finally {
        $cancelInvoice = false;
    }
} finally {
    $createFails = false;
    $cancelInvoice = false;
    foreach ($delivered as $order) {
        localAPI('ModuleTerminate', ['serviceid' => $order['serviceId']]);
        localAPI('PendingOrder', ['orderid' => $order['orderId']]);
        localAPI('CancelOrder', ['orderid' => $order['orderId'], 'cancelsub' => false]);
        localAPI('DeleteOrder', ['orderid' => $order['orderId']]);
    }
    Capsule::table('tblorders')->where('id', '>', $lastOrder)->where('userid', $buyerId)->doesntExist()
        ? ok('cleanup: the delivered orders are gone (their payments stay: no API removes one)')
        : bad('cleanup left an order behind');
}

finish();
