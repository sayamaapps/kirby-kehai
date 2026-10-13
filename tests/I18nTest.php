<?php
/**
 * Translations: every language has the same keys and placeholders as
 * English; `kehai.texts` overrides per language or for all.
 *   php tests/I18nTest.php
 */
require_once __DIR__ . '/helpers.php';

use Kehai\I18n;

$placeholders = function (string $text): array {
    preg_match_all('/\{(\w+)\}/', $text, $m);
    $names = array_unique($m[1]);
    sort($names);
    return $names;
};

try {
    $en = I18n::table('en');
    expect(true, count($en) > 20, 'English table loaded');
    foreach (I18n::LANGUAGES as $language) {
        $table = I18n::table($language);
        expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
        expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
        foreach ($en as $key => $text) {
            expect(true, is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
            expect($placeholders($text), $placeholders($table[$key]), "$language.$key: same placeholders as English");
        }
    }
    foreach (array_keys($en) as $key) {
        if (str_starts_with($key, 'country.')) {
            expect(1, preg_match('/^country\.[A-Z]{2}$/', $key), "$key is a two-letter code");
        }
    }
    expect([], I18n::table('fr'), 'unknown language: no table');

    I18n::setLanguage('fr');
    expect('en', I18n::language(), 'unknown language falls back to English');
    expect('Close', I18n::t('close'), 'English default');
    expect('no.such.key', I18n::t('no.such.key'), 'missing key returns the key');
    expect('3 hours ago', I18n::t('time.hours', ['count' => 3]), 'placeholder replaced');
    holds(I18n::has('country.AT') && !I18n::has('country.XX'), 'has()');

    I18n::setLanguage('de');
    expect('vor 3 Stunden', I18n::t('time.hours', ['count' => 3]), 'German');
    I18n::setOverrides(['close' => 'OK', 'de' => ['time.hours' => 'vor {count} Std.'], 'en' => ['close' => 'Done'], 'country.XX' => 'Testland']);
    expect('OK', I18n::t('close'), 'flat override for every language');
    expect('vor 3 Std.', I18n::t('time.hours', ['count' => 3]), 'override for the current language');
    holds(I18n::has('country.XX'), 'a site can add a country');
    I18n::setLanguage('en');
    I18n::setOverrides(['close' => 'OK', 'en' => ['close' => 'Done']]);
    expect('Done', I18n::t('close'), 'the language table wins over a flat key');
    I18n::setOverrides('nonsense');
    expect('Close', I18n::t('close'), 'invalid texts option: no overrides');

    echo "Kehai I18n: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kehai I18n: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
