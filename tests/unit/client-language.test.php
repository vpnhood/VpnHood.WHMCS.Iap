<?php
/**
 * client-language.test.php — ClientProvisioner::matchLanguage: an app's Accept-Language against the
 * languages an install has, as WHMCS lists them. The locales are the dev install's, as
 * ClientLanguage::getLocales() answers them: its Chinese is zh_TW, its English en_001.
 */

require_once IAP_LIB . '/Provisioning/ClientProvisioner.php';

use WHMCS\Module\Addon\VpnHoodIap\Provisioning\ClientProvisioner;

$locales = array_map(
    fn (array $row) => ['locale' => $row[0], 'language' => $row[1], 'languageCode' => explode('_', $row[0])[0]],
    [
        ['ar_AR', 'arabic'], ['zh_TW', 'chinese'], ['en_001', 'english'], ['fa_IR', 'farsi'], ['fr_FR', 'french'],
        ['de_DE', 'german'], ['pt_BR', 'portuguese-br'], ['pt_PT', 'portuguese'], ['ru_RU', 'russian'],
    ]
);

test('a language alone finds its locale', function () use ($locales) {
    assertSame('farsi', ClientProvisioner::matchLanguage('fa', $locales));
    assertSame('russian', ClientProvisioner::matchLanguage('ru', $locales));
    // two locales of one language: the first WHMCS lists
    assertSame('portuguese-br', ClientProvisioner::matchLanguage('pt', $locales));
});

test('a region picks its own locale, else its language', function () use ($locales) {
    assertSame('portuguese', ClientProvisioner::matchLanguage('pt-PT', $locales));
    assertSame('portuguese-br', ClientProvisioner::matchLanguage('pt-BR', $locales));
    assertSame('english', ClientProvisioner::matchLanguage('en-US', $locales));
    assertSame('chinese', ClientProvisioner::matchLanguage('zh-CN', $locales));
    assertSame('chinese', ClientProvisioner::matchLanguage('zh-Hans', $locales));
});

test('case and underscores do not matter', function () use ($locales) {
    assertSame('portuguese', ClientProvisioner::matchLanguage('PT_pt', $locales));
});

test('the first tag the install serves wins; q=0 refuses one', function () use ($locales) {
    assertSame('german', ClientProvisioner::matchLanguage('xx, de-AT;q=0.8, fr;q=0.5', $locales));
    assertSame('french', ClientProvisioner::matchLanguage('de;q=0, fr', $locales));
});

test('nothing the install serves, or nothing asked: null', function () use ($locales) {
    assertSame(null, ClientProvisioner::matchLanguage('xx', $locales));
    assertSame(null, ClientProvisioner::matchLanguage('*', $locales));
    assertSame(null, ClientProvisioner::matchLanguage('', $locales));
    assertSame(null, ClientProvisioner::matchLanguage('fa', []));
});
