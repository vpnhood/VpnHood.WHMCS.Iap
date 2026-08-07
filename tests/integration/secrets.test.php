<?php
/**
 * secrets.test.php — encryptSecret/decryptSecret round-trips through the REAL
 * WHMCS EncryptPassword/DecryptPassword, byte-for-byte.
 *
 * Regression: WHMCS HTML-escapes localAPI output, so a JSON credential came back
 * with &quot; for every quote, json_decode failed, and every live purchase redemption
 * died with "stored Google credentials are not valid service-account JSON" —
 * found by the first real Google purchase (2026-08-06), invisible to the fake
 * adapter because only real store adapters decrypt credentials.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('IapRepository.php');

use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}

$repo = new IapRepository();

// shaped like a service-account key: quotes, colons, escaped newlines in a PEM
$serviceAccountJson = json_encode([
    'type'         => 'service_account',
    'project_id'   => 'itest-project',
    'private_key'  => "-----BEGIN PRIVATE KEY-----\nMIIEvItest+Fixture/Key==\n-----END PRIVATE KEY-----\n",
    'client_email' => 'itest@itest-project.iam.gserviceaccount.com',
], JSON_UNESCAPED_SLASHES);

$cases = [
    'service-account JSON'         => $serviceAccountJson,
    'every HTML-escapable char'    => 'pass"with\'quotes&<tags>and=signs',
    'apple credentials JSON'       => '{"issuerId":"00000000-0000-0000-0000-000000000000","keyId":"ABC123DEFG","privateKey":"-----BEGIN PRIVATE KEY-----\nitest\n-----END PRIVATE KEY-----"}',
];

foreach ($cases as $name => $plain) {
    $encrypted = $repo->encryptSecret($plain);
    if ($encrypted === '' || $encrypted === $plain) {
        bad("$name: encryption produced nothing");
        continue;
    }
    $decrypted = $repo->decryptSecret($encrypted);
    if ($decrypted === $plain) {
        ok("$name round-trips byte-for-byte");
    } else {
        bad("$name corrupted: " . substr($decrypted, 0, 80));
    }
}

// the JSON must actually parse after the round-trip — the property the bug broke
$decrypted = $repo->decryptSecret($repo->encryptSecret($serviceAccountJson));
$parsed = json_decode($decrypted, true);
(is_array($parsed) && ($parsed['type'] ?? '') === 'service_account')
    ? ok('decrypted credential parses as service-account JSON')
    : bad('decrypted credential does not parse: ' . json_last_error_msg());

// empty stays empty, and a legacy unencrypted value falls through unchanged
$repo->decryptSecret('') === ''
    ? ok('empty credential stays empty')
    : bad('empty credential mangled');

finish();
