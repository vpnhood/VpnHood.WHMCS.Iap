<?php
/**
 * access-code.test.php — the SHAPE rule, driven by the shared vectors.
 *
 * The portal and the apps validate the same strings, and they drifted once: the portal demanded
 * all digits while AccessCodeUtils allows letters after the checksum, so a perfectly good code
 * would have been refused at the door. access-code-vectors.json is the fixture both sides read.
 *
 * Shape is never validity. A well-formed code this portal has never issued still saves — only the
 * access server can say whether a code works, and it says so at use time (keyring plan §5).
 */

require_once IAP_LIB . '/IapRepository.php';

use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

$vectors = json_decode((string) file_get_contents(__DIR__ . '/access-code-vectors.json'), true);
if (!is_array($vectors) || !isset($vectors['valid'], $vectors['invalid'])) {
    throw new \RuntimeException('access-code-vectors.json is missing or unreadable');
}

test('every shared VALID vector is accepted, and comes back normalized', function () use ($vectors) {
    foreach ($vectors['valid'] as $case) {
        $normalized = IapRepository::normalizeAccessCode($case['code']);
        assertTrue($normalized !== null, "refused a valid code ({$case['why']}): {$case['code']}");
        assertSame(20, strlen((string) $normalized), "normalized to the wrong length: {$case['code']}");
        assertSame(preg_replace('/[^a-zA-Z0-9]/', '', trim($case['code'])), $normalized,
            "normalization changed more than the separators: {$case['code']}");
    }
});

test('every shared INVALID vector is refused', function () use ($vectors) {
    foreach ($vectors['invalid'] as $case) {
        assertSame(null, IapRepository::normalizeAccessCode($case['code']),
            "accepted an invalid code ({$case['why']}): {$case['code']}");
    }
});

test('letters after the checksum are legal — the rule the two sides disagreed on', function () {
    assertTrue(IapRepository::normalizeAccessCode('19ABCDEFGHIJKLMNOPQR') !== null,
        'an all-letters random part must be accepted, exactly as the apps accept it');
});

test('the fingerprint is of the normalized code, so separators never make a second identity',
    function () {
        $plain = IapRepository::normalizeAccessCode('12125638402680515648');
        $dashed = IapRepository::normalizeAccessCode('1212-5638-4026-8051-5648');
        assertSame($plain, $dashed);
        assertSame(IapRepository::codeHash((string) $plain), IapRepository::codeHash((string) $dashed),
            'the same code written two ways must reject and un-reject as one');
    });
