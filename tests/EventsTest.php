<?php
/**
 * The data file and what a page makes of it, without Kirby: the one gate
 * for fields (clean), atomic writing, reading, the freshness limit, the
 * bubble entries (newest, real, at most N, not older than maxAge) and the
 * summary with its minimum.
 *   php tests/EventsTest.php
 */
require_once __DIR__ . '/helpers.php';

use Kehai\Config;
use Kehai\Display;
use Kehai\Events;
use Kehai\I18n;
use Kehai\Log;

$tmp = tempDir('events');
$now = new DateTimeImmutable('2026-10-13T10:00:00Z');
$config = testConfig($tmp);
$file = $config['file'];
$event = fn (string $product, string $ago, ?string $country = null) => array_filter([
    'type' => 'purchase', 'product' => $product, 'at' => Events::minute($now->modify($ago)), 'country' => $country,
]);
$data = fn (array $events, string $generatedAgo = '-10 minutes') => Events::build($events, $now->modify($generatedAgo), 'digistore24', true);

try {
    expect('storage/kehai/events.json', substr($file, strlen($tmp) + 1), 'default file relative to the project root');

    // ---- clean(): the one gate ---------------------------------------------
    $full = ['type' => 'purchase', 'product' => 'jahreskurs', 'at' => '2026-10-13T07:41:00Z', 'country' => 'AT',
        'name' => 'Annegret', 'email' => 'a@example.org', 'id' => 'AB12CD34', 'amount' => '97.00', 'affiliate' => 'aff', 'campaign' => 'cmp'];
    expect(['type' => 'purchase', 'product' => 'jahreskurs', 'at' => '2026-10-13T07:41:00Z'], Events::clean($full, false), 'clean: only type, product, at — country off');
    expect(['type' => 'purchase', 'product' => 'jahreskurs', 'at' => '2026-10-13T07:41:00Z', 'country' => 'AT'], Events::clean($full, true), 'clean: plus country when on');
    foreach ([
        'unknown type' => ['type' => 'refund'],
        'product with a space' => ['product' => 'Licht kreis'],
        'product with markup' => ['product' => '<b>x</b>'],
        'time with seconds' => ['at' => '2026-10-13T07:41:37Z'],
        'time without zone' => ['at' => '2026-10-13T07:41:00'],
        'impossible time' => ['at' => '2026-13-45T07:41:00Z'],
        'time as number' => ['at' => 1760341260],
    ] as $what => $change) {
        expect(null, Events::clean(array_merge($full, $change), true), "clean rejects: $what");
    }
    expect(null, Events::clean($full, true, ['wochenendkurs']), 'clean rejects a key outside the allow list');
    expect(null, Events::clean('purchase', true), 'clean rejects a non-array');
    holds(!isset(Events::clean(array_merge($full, ['country' => 'Österreich']), true)['country']), 'country must be a two-letter code');

    // ---- build(): sorted, cleaned, limited -------------------------------------
    $built = Events::build([$event('jahreskurs', '-3 days'), $full + ['secret' => 1], $event('jahreskurs', '-1 hour'), ['type' => 'purchase']], $now, 'digistore24', false);
    expect(['2026-10-13T09:00:00Z', '2026-10-13T07:41:00Z', '2026-10-10T10:00:00Z'], array_column($built['events'], 'at'), 'build: newest first, invalid dropped');
    holds(!str_contains(json_encode($built), 'Annegret') && !str_contains(json_encode($built), 'secret'), 'build: extra fields of an adapter never reach the file');
    expect(Events::MAX_EVENTS, count(Events::build(array_fill(0, Events::MAX_EVENTS + 5, $event('jahreskurs', '-1 hour')), $now, 'x', false)['events']), 'build: limited to MAX_EVENTS');

    // ---- write(): atomic ---------------------------------------------------------
    Events::write($file, $data([$event('jahreskurs', '-1 hour')]));
    holds(is_file($file), 'write creates the directory and the file');
    $inode = fileinode($file);
    $reader = fopen($file, 'r');
    $old = file_get_contents($file);
    Events::write($file, $data([$event('jahreskurs', '-2 hours'), $event('wochenendkurs', '-3 hours')]));
    clearstatcache();
    holds(fileinode($file) !== $inode, 'write replaces the file by rename (a new inode), never in place');
    expect($old, stream_get_contents($reader), 'a reader that opened the old file still reads it completely');
    fclose($reader);
    expect([], glob(dirname($file) . '/.*.tmp'), 'no temporary file left behind');
    expect(2, count(json_decode(file_get_contents($file), true)['events']), 'new content in place');
    // A failed write leaves the old file alone: the target is a directory, rename() must fail.
    $blocked = $tmp . '/blocked/events.json';
    mkdir($blocked, 0700, true);
    file_put_contents($blocked . '/keep', 'x');
    try {
        Events::write($blocked, $data([]));
        throw new LogicException('write into a directory must fail');
    } catch (RuntimeException $e) {
        holds(str_starts_with($e->getMessage(), 'Cannot replace'), 'failed rename is reported');
    }
    expect([], glob(dirname($blocked) . '/.*.tmp'), 'failed write removes its temporary file');

    // ---- read() --------------------------------------------------------------------
    expect('missing', Events::read($tmp . '/nope.json')['error'], 'missing file');
    file_put_contents($tmp . '/bad.json', '{"format":1,');
    expect('not JSON', Events::read($tmp . '/bad.json')['error'], 'broken file');
    file_put_contents($tmp . '/v2.json', json_encode(['format' => 2, 'generated' => '2026-10-13T09:00:00Z', 'events' => []]));
    holds(str_starts_with((string) Events::read($tmp . '/v2.json')['error'], 'unknown format 2'), 'unknown format is refused, not guessed');
    file_put_contents($tmp . '/nogen.json', json_encode(['format' => 1, 'events' => []]));
    holds(Events::read($tmp . '/nogen.json')['error'] !== null, 'no generation time is refused');
    file_put_contents($tmp . '/mixed.json', json_encode(['format' => 1, 'generated' => '2026-10-13T09:00:00Z', 'events' => [$full, ['type' => 'purchase'], 'x']]));
    $read = Events::read($tmp . '/mixed.json', false);
    expect([[Events::clean($full, false)], 2], [$read['events'], $read['dropped']], 'read cleans every event and counts the invalid ones');

    // ---- usable(): fresh, configured, not in the future ------------------------
    Log::forget();
    Events::write($file, $data([$event('jahreskurs', '-1 hour'), $event('unbekannt', '-1 hour'), $event('wochenendkurs', '+10 minutes'), $event('wochenendkurs', '+4 minutes')]));
    [$usable, $log] = logged(fn () => Events::usable($config, $now));
    expect(['2026-10-13T10:04:00Z', '2026-10-13T09:00:00Z'], array_column($usable, 'at'), 'usable: unknown product and far future dropped, a few minutes of clock skew tolerated');
    expect('', $log, 'usable: nothing logged when fine');

    // Too old: nothing at all, and a log line.
    Events::write($file, $data([$event('jahreskurs', '-1 hour')], '-49 hours'));
    [$usable, $log] = logged(fn () => Events::usable($config, $now));
    expect([], $usable, 'file older than 48 hours: nothing');
    holds(str_contains($log, 'Kehai: nothing shown, the data file ' . $file . ' was last updated 49 hours ago (kehai.maxStale is 48)'), 'stale file is logged');
    [, $log] = logged(fn () => Events::usable($config, $now));
    expect('', $log, 'the same log line only once per process');
    Events::write($file, $data([$event('jahreskurs', '-1 hour')], '-47 hours'));
    expect(1, count(Events::usable($config, $now)), '47 hours old: still used');
    expect([], logged(fn () => Events::usable(testConfig($tmp, ['maxStale' => 12]), $now))[0], 'kehai.maxStale 12');
    unlink($file);
    Log::forget();
    [$usable, $log] = logged(fn () => Events::usable($config, $now));
    holds($usable === [] && str_contains($log, 'is missing'), 'missing file: nothing, logged');

    // ---- The bubble entries ------------------------------------------------------------
    I18n::setLanguage('de');
    I18n::setOverrides([]);
    $events = [
        $event('jahreskurs', '-2 hours', 'AT'), $event('wochenendkurs', '-5 hours', 'CH'), $event('jahreskurs', '-1 day', 'XX'),
        $event('jahreskurs', '-3 days', 'NL'), $event('jahreskurs', '-13 days -23 hours'), $event('jahreskurs', '-14 days -1 minute'),
    ];
    $bubble = Display::bubble($events, $config, $now, 'de');
    expect([
        ['text' => 'Jemand hat „Jahreskurs“ gebucht', 'at' => '2026-10-13T08:00:00Z'],
        ['text' => 'Jemand hat „Wochenendkurs“ gebucht', 'at' => '2026-10-13T05:00:00Z'],
        ['text' => 'Jemand hat „Jahreskurs“ gebucht', 'at' => '2026-10-12T10:00:00Z'],
    ], $bubble, 'bubble: the three newest real events (kehai.max 3), country off, real times');
    $all = Display::bubble($events, testConfig($tmp, ['max' => 20]), $now, 'de');
    expect(5, count($all), 'bubble: never an event older than maxAge (14 days); each event once');
    expect(count($all), count(array_unique(array_column($all, 'at'))), 'bubble: no event repeated');
    $countryConfig = testConfig($tmp, ['country' => true, 'max' => 4]);
    expect([
        'Jemand aus Österreich hat „Jahreskurs“ gebucht',
        'Jemand aus der Schweiz hat „Wochenendkurs“ gebucht',
        'Jemand hat „Jahreskurs“ gebucht',
        'Jemand aus den Niederlanden hat „Jahreskurs“ gebucht',
    ], array_column(Display::bubble($events, $countryConfig, $now, 'de'), 'text'), 'bubble: country on (dative with article), unknown code without country');
    I18n::setLanguage('en');
    expect('Someone from Austria booked “Annual course”', Display::bubble($events, $countryConfig, $now, 'en')[0]['text'], 'bubble in English with the English label');
    expect(['2026-10-13T05:00:00Z'], array_column(Display::bubble($events, $config, $now, 'en', ['wochenendkurs']), 'at'), 'bubble: only the given product keys');
    expect([], Display::bubble([], $config, $now, 'en'), 'no events: no entries');
    I18n::setOverrides(['en' => ['bubble.purchase' => 'Booked: {product}']]);
    expect('Booked: Annual course', Display::bubble($events, $config, $now, 'en')[0]['text'], 'kehai.texts overrides the sentence');
    I18n::setOverrides([]);

    // ---- The summary --------------------------------------------------------------------
    I18n::setLanguage('de');
    $three = [$event('jahreskurs', '-1 hour'), $event('jahreskurs', '-2 days'), $event('jahreskurs', '-6 days -23 hours'), $event('jahreskurs', '-7 days -1 minute'), $event('wochenendkurs', '-1 day')];
    $summary = Display::summary($three, $config, $now, 'de', 'jahreskurs');
    expect(['count' => 3, 'days' => 7, 'product' => 'Jahreskurs', 'text' => 'In den letzten 7 Tagen wurde „Jahreskurs“ 3-mal gebucht'], $summary, 'summary: three in seven days, the eight-day-old one not counted');
    expect(null, Display::summary(array_slice($three, 1), $config, $now, 'de', 'jahreskurs'), 'summary: two are below the minimum 3 — nothing');
    expect(null, Display::summary([], $config, $now, 'de', 'jahreskurs'), 'summary: none — nothing (never "0")');
    expect(4, Display::summary($three, $config, $now, 'de', 'kurse')['count'], 'summary of a group');
    expect('In den letzten 7 Tagen wurde „unsere Kurse“ 4-mal gebucht', Display::summary($three, $config, $now, 'de', 'kurse')['text'], 'group label');
    expect(4, Display::summary($three, $config, $now, 'de', 'jahreskurs', 30)['count'], 'summary: own period');
    expect(null, Display::summary($three, $config, $now, 'de', 'jahreskurs', null, 4), 'summary: own minimum');
    expect('In den letzten 7 Tagen wurde „Jahreskurs“ einmal gebucht', Display::summary([$event('jahreskurs', '-1 hour')], $config, $now, 'de', 'jahreskurs', null, 1)['text'], 'summary: singular');
    expect('In den letzten 24 Stunden wurde „Jahreskurs“ einmal gebucht', Display::summary($three, $config, $now, 'de', 'jahreskurs', 1, 1)['text'], 'summary: one day reads "24 hours"');
    [$none, $log] = logged(fn () => Display::summary($three, $config, $now, 'de', 'nothing'));
    holds($none === null && str_contains($log, 'neither a product key nor a group'), 'summary: unknown name, nothing, logged');

    // ---- Configuration ------------------------------------------------------------------
    $defaults = Config::defaults($tmp);
    expect([false, false, false, false, false], [$defaults['active'], $defaults['bubble'], $defaults['summary'], $defaults['pull'], $defaults['country']], 'everything off by default');
    expect([8, 3, 14, 48, 7, 3, 15, 'left'], [$defaults['delay'], $defaults['max'], $defaults['maxAge'], $defaults['maxStale'], $defaults['summaryDays'], $defaults['summaryMin'], $defaults['pullInterval'], $defaults['position']], 'defaults');
    expect(false, Config::normalize(['active' => 'yes'], $tmp)['config']['active'], 'only true switches on');
    $odd = Config::normalize(['products' => ['12 34' => ['key' => 'x'], '5' => ['key' => 'Bad Key'], '6' => 'x', '7' => ['key' => 'ok']], 'groups' => ['g' => ['products' => ['ok', 'nope']]], 'position' => 'top', 'timezone' => 'Mars/Base', 'max' => 'many'], $tmp);
    expect(['7'], array_map('strval', array_keys($odd['config']['products'])), 'invalid product entries are dropped');
    expect(['ok'], $odd['config']['groups']['g']['products'], 'unknown group members are dropped');
    expect(7, count($odd['problems']), 'every drop is reported: ' . implode(' | ', $odd['problems']));
    expect(['left', 'Europe/Berlin', 3], [$odd['config']['position'], $odd['config']['timezone'], $odd['config']['max']], 'invalid values fall back to the defaults');
    expect(20, Config::normalize(['max' => 500], $tmp)['config']['max'], 'numbers are clamped');

    echo "Kehai events and display: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kehai events and display: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
} finally {
    removeTree($tmp);
}
