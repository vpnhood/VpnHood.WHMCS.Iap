<?php
/**
 * api-exception.test.php — ApiException: the code is the contract, the message is what the
 * client reads, and a cause kept behind it (WHMCS's own error text) reaches only the logs.
 */

require_once IAP_LIB . '/ApiException.php';

use WHMCS\Module\Addon\VpnHoodIap\ApiException;

test('a hidden cause reaches the log, never the message', function () {
    $e = new ApiException('Provisioning failed.', 502, 'provisioning_failed',
        new \RuntimeException('AcceptOrder failed: Module Create Failed'));
    assertSame('Provisioning failed.', $e->getMessage());
    assertSame('provisioning_failed', $e->getErrorCode());
    assertSame(502, $e->getHttpStatus());
    assertSame('Provisioning failed. (AcceptOrder failed: Module Create Failed)', ApiException::logMessage($e));
});

test('with no cause, the log keeps the message as it is', function () {
    $e = new ApiException('The code is wrong.', 401, 'invalid_code');
    assertSame('The code is wrong.', ApiException::logMessage($e));
    assertSame('plain', ApiException::logMessage(new \RuntimeException('plain')));
});

test('a throw site that names no code gets its status class', function () {
    assertSame('upstream_error', (new ApiException('x', 502))->getErrorCode());
    assertSame('unavailable', (new ApiException('x', 503))->getErrorCode());
});
