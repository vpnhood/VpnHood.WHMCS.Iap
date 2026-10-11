<?php
/**
 * client-language.test.php — the app's language on the website account (ClientProvisioner) inside
 * the deployed dev WHMCS: an Accept-Language matched against the languages this install has, a
 * client created with it, and a later sign-in or purchase giving it only to a client that has
 * none, so a choice made on the website stands. The probe client is removed at the end.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'Provisioning/AccountService.php', 'Provisioning/ClientProvisioner.php');

use WHMCS\Module\Addon\VpnHoodIap\Provisioning\ClientProvisioner;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}

// -- the install's own list --------------------------------------------------
$cases = [
    'fa'         => 'farsi',
    'pt-BR'      => 'portuguese-br',
    'en-US'      => 'english',
    'zh-Hans'    => 'chinese',
    'fr;q=0, de' => 'german',
    'xx'         => null,
    ''           => null,
];
foreach ($cases as $header => $expected) {
    $actual = ClientProvisioner::languageFor($header);
    $actual === $expected
        ? ok("Accept-Language '$header' is " . var_export($expected, true))
        : bad("Accept-Language '$header' gave " . var_export($actual, true) . ', expected ' . var_export($expected, true));
}

// -- a client created with it, then synced -----------------------------------
$probeEmail = 'client-language-' . bin2hex(random_bytes(4)) . '@vpnhood.com';
$clientId = 0;
try {
    $clientId = (new ClientProvisioner())->createClient($probeEmail, 'Language Probe', 'farsi');
    ok("module-created client #$clientId");
} catch (\Throwable $e) {
    bad('createClient threw: ' . $e->getMessage());
}

if ($clientId > 0) {
    $language = fn () => (string) (one($db, 'SELECT language FROM tblclients WHERE id = ?', [$clientId])['language'] ?? '');
    $clients = new ClientProvisioner();

    $language() === 'farsi'
        ? ok("the new client has the app's language")
        : bad("the new client's language is " . var_export($language(), true) . ', expected farsi');

    $clients->syncClient($clientId, null, 'french');
    $language() === 'farsi'
        ? ok('a later sign-in leaves the language the client has')
        : bad("the sync replaced the client's language with " . var_export($language(), true));

    $db->prepare("UPDATE tblclients SET language = '' WHERE id = ?")->execute([$clientId]);
    $clients->syncClient($clientId, null, 'french');
    $language() === 'french'
        ? ok("a client without a language takes the app's")
        : bad('a client without a language got ' . var_export($language(), true) . ', expected french');

    $clients->syncClient($clientId, 'Language Probe', null);
    $language() === 'french'
        ? ok('a request without a language changes none')
        : bad('a request without a language left ' . var_export($language(), true));

    $r = localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    ($r['result'] ?? '') === 'success'
        ? ok("probe client #$clientId removed")
        : bad('could not remove the probe client: ' . json_encode($r));
}

finish();
