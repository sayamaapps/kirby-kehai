<?php
/**
 * The Digistore24 adapter and `kehai fetch` against recorded answers,
 * without a network: the request, both answer shapes, every failure
 * (HTTP error, time-out, broken JSON, result other than success), the
 * allow list, refunds, chargebacks, cancellations, test orders and
 * rebillings, rounding, the country switch — and that nothing personal
 * reaches the data file or the output.
 *   php tests/DigistoreTest.php
 */
require_once __DIR__ . '/helpers.php';

use Kehai\Config;
use Kehai\Digistore24;
use Kehai\Events;
use Kehai\Fetch;
use Kehai\SourceException;

$fixture = file_get_contents(__DIR__ . '/fixtures/digistore-purchases.json');
$purchases = json_decode($fixture, true)['data']['purchase_list'];
$key = 'ds24-not-a-real-key-0000';
$tmp = tempDir('digistore');
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('2026-10-13T10:00:00Z');
$from = $now->modify('-14 days');
$config = testConfig($tmp);
$allow = Config::allowList($config);
$calls = [];
$ok = fn (?string $body = null) => ['status' => 200, 'body' => $body ?? $fixture];
$adapter = function (array $script, bool $country = false) use (&$calls, $key, $allow): Digistore24 {
    return new Digistore24(scripted($script, $calls), $key, $allow, $country, 'Europe/Berlin');
};
$fail = function (array $script) use ($adapter, $from, $now): string {
    try {
        $adapter($script)->events($from, $now);
    } catch (SourceException $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('no SourceException');
};

try {
    // ---- The request ---------------------------------------------------------
    $calls = [];
    $result = $adapter([$ok()])->events($from, $now);
    expect(1, count($calls), 'one page (fewer than PAGE_SIZE entries)');
    $url = parse_url($calls[0]['url']);
    parse_str($url['query'], $query);
    expect('https://www.digistore24.com/api/call/listPurchases/json', $url['scheme'] . '://' . $url['host'] . $url['path'], 'endpoint');
    expect(['from' => '2026-09-29', 'to' => '2026-10-14', 'search' => ['order_type' => 'live', 'product_id' => '111111,111112,222222'], 'sort_by' => 'date', 'sort_order' => 'desc', 'page_no' => '1', 'page_size' => '500'], $query,
        'from = window start, to = tomorrow (today complete), live orders of the allow list only, newest first, paged');
    expect($from->format('c'), $result['from']->format('c'), 'an answer that is not cut covers the period asked for');
    holds(!str_contains(implode("\n", $result['notes']), 'cut its answer'), 'no note about a cut');
    $calls = [];
    (new Digistore24(scripted([$ok()], $calls), $key, [], false, 'Europe/Berlin'))->events($from, $now);
    parse_str((string) parse_url($calls[0]['url'], PHP_URL_QUERY), $open);
    expect(['order_type' => 'live'], $open['search'], 'empty allow list: no product filter in the request');
    expect(['date', 'desc'], [$open['sort_by'], $open['sort_order']], 'empty allow list: still newest first');
    holds(in_array('X-DS-API-KEY: ' . $key, $calls[0]['headers'], true), 'key as X-DS-API-KEY header');
    holds(!str_contains($calls[0]['url'], $key), 'key never in the URL');

    // ---- What counts --------------------------------------------------------
    $events = $result['events'];
    expect([
        ['type' => 'purchase', 'product' => 'jahreskurs', 'at' => '2026-10-13T07:41:00Z'],
        ['type' => 'purchase', 'product' => 'jahreskurs', 'at' => '2026-10-12T16:05:00Z'],
        ['type' => 'purchase', 'product' => 'wochenendkurs', 'at' => '2026-10-10T05:30:00Z'],
        ['type' => 'purchase', 'product' => 'jahreskurs', 'at' => '2026-10-09T18:00:00Z'],
    ], $events, 'four purchases count: single payment, first instalment, other product, second id of the same key; time Berlin → UTC, seconds cut');
    $notes = implode("\n", $result['notes']);
    holds(str_contains($notes, '13 purchases received, 4 counted'), 'summary note');
    foreach ([
        '1 × billing_status aborted', '1 × billing_status unpaid', '1 × cancelled (is_canceled_now)', '1 × test order',
        '1 × transaction_type refund', '1 × transaction_type chargeback', '1 × listed twice',
        '1 × created before the window (a later payment of an older order)', '1 × product not in kehai.products',
    ] as $reason) {
        holds(str_contains($notes, 'not counted: ' . $reason), "reason reported: $reason");
    }
    holds(str_contains($notes, 'product 999999 is not in kehai.products (1 purchases)'), 'unknown product id named for the configuration');

    // Each rule on its own, on a purchase that counts otherwise.
    $base = $purchases[0];
    $one = fn (array $change) => (new Digistore24(fn () => [], $key, $allow, false))->convert([array_merge($base, $change)], $from, $now)['events'];
    expect(1, count($one([])), 'baseline counts');
    expect(1, count($one(['transaction_type' => 'PAYMENT'])), 'transaction_type is compared case-insensitively');
    expect(1, count($one(['transaction_type' => null])), 'no transaction_type: billing_status decides');
    foreach (['refund', 'chargeback', 'payment_missed', 'payment_denial'] as $type) {
        expect([], $one(['transaction_type' => $type]), "transaction_type $type does not count");
    }
    foreach (['aborted', 'unpaid', 'reminding', '', null] as $status) {
        expect([], $one(['billing_status' => $status]), 'billing_status ' . var_export($status, true) . ' does not count');
    }
    expect(1, count($one(['billing_status' => 'paying'])), 'billing_status paying counts');
    expect([], $one(['is_canceled_now' => 'Y']), 'is_canceled_now Y');
    expect([], $one(['is_canceled_now' => null, 'is_cancelled_now' => 'Y']), 'is_cancelled_now Y (other spelling)');
    expect([], $one(['order_type' => 'test']), 'order_type test');
    expect([], $one(['is_test_payment' => 'Y']), 'is_test_payment Y');
    expect([], $one(['main_product_id' => '333333']), 'not on the allow list');
    expect([], $one(['main_product_id' => null]), 'no product id');
    expect([], $one(['created_at' => 'yesterday']), 'unparsable created_at');
    expect([], $one(['created_at' => '2026-02-31 10:00:00']), 'impossible date');
    expect([], $one(['created_at' => '2026-10-13 12:10:00']), 'created more than five minutes in the future (Berlin 12:10 = 10:10 UTC)');
    expect([], $one(['created_at' => '2026-09-29 11:59:00']), 'one minute before the 14-day window');
    expect(1, count($one(['created_at' => '2026-09-29 12:00:00'])), 'exactly at the window start');
    expect('2026-10-13T07:41:00Z', $one(['created_at' => '2026-10-13T09:41:59+02:00'])[0]['at'], 'ISO time with offset, rounded down');
    expect('2026-10-13T07:41:00Z', $one(['created_at' => '2026-10-13 09:41'])[0]['at'], 'time without seconds');
    // Rebilling: the order date counts, never the payment date.
    expect([], $one(['created_at' => '2026-07-01 10:00:00', 'last_payment_at' => '2026-10-13 09:00:00', 'pay_sequence_no' => 4]), 'later instalment of an old order is not a new purchase');
    expect(2, count((new Digistore24(fn () => [], $key, $allow))->convert([$base, $base, array_merge($base, ['id' => 'OTHER'])], $from, $now)['events']), 'the same order id twice counts once, another id counts');
    // Rounding: down to the minute, in UTC.
    expect('2026-10-13T07:41:00Z', Kehai\Events::minute(new DateTimeImmutable('2026-10-13 09:41:59.999', new DateTimeZone('Europe/Berlin'))), 'rounded down, never up');

    // ---- Country on and off ---------------------------------------------------
    $calls = [];
    $withCountry = $adapter([$ok()], true)->events($from, $now)['events'];
    expect(['AT', 'DE', 'CH', 'NL'], array_column($withCountry, 'country'), 'country on: vat_country, upper case');
    holds(!array_key_exists('country', $events[0]), 'country off: no country field at all');
    expect([], array_column($adapter([$ok()], true)->convert([array_merge($base, ['vat_country' => 'Österreich'])], $from, $now)['events'], 'country'), 'only two-letter codes');

    // ---- Answer shapes and failures -------------------------------------------
    $list = json_encode(['result' => 'success', 'data' => array_slice($purchases, 0, 2)]);
    expect(2, count($adapter([$ok($list)])->events($from, $now)['events']), '`data` directly as the list');
    expect([], $adapter([$ok('{"result":"success","data":[]}')])->events($from, $now)['events'], 'empty list');
    expect('Digistore24 answered HTTP 500', $fail([['status' => 500, 'body' => '<html>Internal Server Error</html>']]), 'HTTP error');
    expect('Digistore24 answered HTTP 403: Access denied for key [key]', $fail([['status' => 403, 'body' => json_encode(['result' => 'error', 'message' => "Access denied for key $key"])]]), 'HTTP error with message, key scrubbed');
    expect('Digistore24 answered with a body that is not JSON', $fail([$ok('{"result":"success","data":[')]), 'broken JSON');
    expect('Digistore24 answered result "error": Purchase list of [e-mail] not allowed', $fail([$ok(json_encode(['result' => 'error', 'message' => 'Purchase list of annegret.beispielhuber@example.org not allowed']))]), 'result error, e-mail address removed from the message');
    expect('Digistore24 answered result "?"', $fail([$ok('{"data":{"purchase_list":[]}}')]), 'no result field');
    expect('Digistore24 answered without a purchase list', $fail([$ok('{"result":"success","data":{"other":1}}')]), 'no purchase list');
    expect('Digistore24 not reachable: timed out after 30 seconds', $fail([new RuntimeException('timed out after 30 seconds')]), 'time-out');
    expect('Digistore24 not reachable: Could not resolve host [2J [key]', $fail([new RuntimeException("Could not resolve host\x1b[2J $key")]), 'network error: key scrubbed, escape sequence removed');
    expect('Digistore24 answered result "err [31m"', $fail([$ok(json_encode(['result' => "err\x1b[31m"]))]), 'control characters from the answer never printed');

    // Paging: a full page asks for the next one; a repeated page does not count twice.
    $calls = [];
    $page = array_fill(0, Digistore24::PAGE_SIZE, $base);
    foreach ($page as $i => &$entry) {
        $entry['id'] = 'P' . $i;
    }
    unset($entry);
    $paged = $adapter([$ok(json_encode(['result' => 'success', 'data' => ['purchase_list' => $page]])), $ok(json_encode(['result' => 'success', 'data' => ['purchase_list' => [$purchases[1]]]]))])->events($from, $now);
    expect(2, count($calls), 'a full page asks for page 2');
    holds(str_contains($calls[1]['url'], 'page_no=2'), 'page 2 requested');
    expect(Digistore24::PAGE_SIZE + 1, count($paged['events']), 'events of both pages');
    expect($from->format('c'), $paged['from']->format('c'), 'two pages, the last one not full: not cut');

    // ---- An answer cut at MAX_PAGES: the newest orders come first, the oldest fall off -----
    $berlin = new DateTimeZone('Europe/Berlin');
    $fullPages = function (int $counted) use ($base, $now, $berlin, $ok): array {
        $script = [];
        for ($p = 1; $p <= Digistore24::MAX_PAGES; $p++) {
            $at = $now->modify('-' . ($p * 16) . ' hours')->setTimezone($berlin)->format('Y-m-d H:i:s');
            $list = [];
            for ($i = 0; $i < Digistore24::PAGE_SIZE; $i++) {
                $list[] = array_merge($base, ['id' => "P$p-$i", 'created_at' => $at] + ($i < $counted ? [] : ['main_product_id' => '333333']));
            }
            $script[] = $ok(json_encode(['result' => 'success', 'data' => ['purchase_list' => $list]]));
        }
        return $script;
    };
    $calls = [];
    $cut = $adapter($fullPages(Digistore24::PAGE_SIZE))->events($from, $now);
    expect(Digistore24::MAX_PAGES, count($calls), 'twenty full pages: exactly MAX_PAGES requests, a 21st page is never asked for');
    holds(str_contains($calls[Digistore24::MAX_PAGES - 1]['url'], 'page_no=20') && !str_contains(implode(' ', array_column($calls, 'url')), 'page_no=21'), 'page 20 is the last request');
    expect('2026-09-30T02:01:00Z', $cut['from']->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'cut: "from" is the minute after the oldest order received (page 20, 320 hours before now)');
    holds($cut['from'] instanceof DateTimeImmutable && $cut['from'] > $from, 'cut: "from" is later than asked');
    holds(in_array('the source cut its answer after 10000 orders (MAX_PAGES × PAGE_SIZE): the file covers the last 13 days only', $cut['notes'], true), 'cut: noted, with the days left (' . implode(' | ', $cut['notes']) . ')');
    expect(Digistore24::MAX_PAGES * Digistore24::PAGE_SIZE, count($cut['events']), 'cut: every received order is a counted event here');
    $calls = [];
    $stop = $adapter(array_slice($fullPages(1), 0, Digistore24::MAX_PAGES - 1) + [Digistore24::MAX_PAGES - 1 => $ok(json_encode(['result' => 'success', 'data' => ['purchase_list' => [$base]]]))])->events($from, $now);
    expect([Digistore24::MAX_PAGES, $from->format('c')], [count($calls), $stop['from']->format('c')], 'page 20 not full: the answer was not cut, "from" as asked');
    $unreadable = $fullPages(1);
    $unreadable[Digistore24::MAX_PAGES - 1] = $ok(json_encode(['result' => 'success', 'data' => ['purchase_list' => array_fill(0, Digistore24::PAGE_SIZE, ['id' => 'x', 'created_at' => 'soon'])]]));
    expect('2026-09-30T18:01:00Z', $adapter($unreadable)->events($from, $now)['from']->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'cut: an entry without a readable created_at is ignored when looking for the oldest');

    // ---- kehai fetch -----------------------------------------------------------
    $file = $config['file'];
    $run = fn (array $config, ?string $key, ?Closure $transport, ?string $target = null) =>
        captured(fn ($out, $err) => Fetch::run($config, $key, $target, $transport, $tmp, $out, $err, $now));

    [$code, $out, $err] = $run($config, null, null);
    expect(Fetch::EXIT_NO_KEY, $code, 'no key: exit 3');
    holds(str_contains($out, 'No DIGISTORE24_API_KEY set'), 'no key: message');
    holds(!is_file($file), 'no key: nothing written');

    $calls = [];
    [$code, , $err] = $run(testConfig($tmp, ['products' => []]), $key, scripted([$ok()], $calls));
    expect([Fetch::EXIT_ERROR, 0], [$code, count($calls)], 'no products: exit 1 before any request');
    holds(str_contains($err, 'kehai.products is empty'), 'no products: message');

    $calls = [];
    [$code, $out, $err] = $run($config, $key, scripted([$ok()], $calls));
    expect(Fetch::EXIT_OK, $code, 'success: exit 0 (' . $err . ')');
    parse_str((string) parse_url($calls[0]['url'], PHP_URL_QUERY), $query);
    expect('2026-09-29', $query['from'], 'window = max(maxAge 14, summaryDays 7) days');
    $written = file_get_contents($file);
    $data = json_decode($written, true);
    expect(['format', 'generated', 'from', 'source', 'events'], array_keys($data), 'file: exactly these top-level fields');
    expect([1, '2026-10-13T10:00:00Z', '2026-09-29T10:00:00Z', 'digistore24'], [$data['format'], $data['generated'], $data['from'], $data['source']], 'format, generation time, covered since, source');
    holds(!str_contains($out, 'window:'), 'fetch: an uncut answer has no note about the window');
    expect($events, $data['events'], 'file: the four events, newest first');
    foreach ($data['events'] as $event) {
        expect(['type', 'product', 'at'], array_keys($event), 'file: an event has only type, product and at (country off)');
    }
    holds(str_contains($out, 'jahreskurs: 3') && str_contains($out, 'wochenendkurs: 1') && str_contains($out, 'Written: ' . $file . ' (4 events)'), 'success: counts per product and the written line');

    // Data minimisation: nothing personal, commercial or identifying in the file or the output.
    foreach (PERSONAL as $secret) {
        holds(!str_contains($written, $secret), "file never contains \"$secret\"");
        holds(!str_contains($out . $err, $secret), "output never contains \"$secret\"");
    }
    holds(!str_contains($written . $out . $err, $key), 'key never written or printed');
    holds(Kehai\Text::isPlain($out . $err), 'output is plain text');

    // A cut answer: the source's cut (20 pages), the MAX_EVENTS cut, or both — the file says what it covers, the output says so.
    $calls = [];
    [$code, $out, $err] = $run($config, $key, scripted($fullPages(1), $calls));
    $data = json_decode(file_get_contents($file), true);
    expect([Fetch::EXIT_OK, Digistore24::MAX_PAGES, 20, '2026-09-30T02:01:00Z'], [$code, count($calls), count($data['events']), $data['from']], 'fetch, source cut only: file covers since the oldest order received (' . $err . ')');
    holds(str_contains($out, 'the source cut its answer after 10000 orders') && str_contains($out, "window: 14 days asked, 13 days covered\n"), 'fetch, source cut only: both notes (' . $out . ')');
    $calls = [];
    [$code, $out] = $run($config, $key, scripted($fullPages(Digistore24::PAGE_SIZE), $calls));
    $data = json_decode(file_get_contents($file), true);
    expect([Events::MAX_EVENTS, '2026-10-10T18:01:00Z'], [count($data['events']), $data['from']], 'fetch, both cuts: the later "from" wins (the 2000 cut: minute after page 4)');
    holds(str_contains($out, 'the source cut its answer') && str_contains($out, 'window: 14 days asked, 2 days covered'), 'fetch, both cuts: the source note and one window note (' . $out . ')');
    expect(1, substr_count($out, 'window:'), 'fetch: one window note');
    $calls = [];
    $q = array_map(fn ($i) => array_merge($base, ['id' => "Q$i", 'created_at' => $now->modify("-$i minutes")->setTimezone($berlin)->format('Y-m-d H:i:s')]), range(1, Events::MAX_EVENTS + 5));
    [$code, $out] = $run($config, $key, scripted(array_map(fn ($chunk) => $ok(json_encode(['result' => 'success', 'data' => ['purchase_list' => $chunk]])), array_chunk($q, Digistore24::PAGE_SIZE)), $calls));
    $data = json_decode(file_get_contents($file), true);
    expect([5, Events::MAX_EVENTS, '2026-10-12T00:41:00Z'], [count($calls), count($data['events']), $data['from']], 'fetch, 2005 purchases in the window: 2000 kept, "from" after the oldest kept');
    holds(str_contains($out, 'window: 14 days asked, 1 days covered') && !str_contains($out, 'cut its answer'), 'fetch, MAX_EVENTS cut only: the window note alone (' . $out . ')');

    // Country on: the code is stored, nothing more.
    [$code] = $run(testConfig($tmp, ['country' => true]), $key, scripted([$ok()], $calls));
    $data = json_decode(file_get_contents($file), true);
    expect(['type', 'product', 'at', 'country'], array_keys($data['events'][0]), 'country on: four fields');
    expect('AT', $data['events'][0]['country'], 'country on: code');

    // Every failure: exit 1, a message on stderr, the last good file untouched.
    $before = file_get_contents($file);
    foreach ([
        'HTTP 500' => [['status' => 500, 'body' => '']],
        'broken JSON' => [$ok('{"result":')],
        'result error' => [$ok('{"result":"error","message":"API key invalid"}')],
        'time-out' => [new RuntimeException('timed out after 30 seconds')],
    ] as $what => $script) {
        [$code, $out, $err] = $run($config, $key, scripted($script, $calls));
        expect(Fetch::EXIT_ERROR, $code, "$what: exit 1");
        holds(str_starts_with($err, 'Digistore24 '), "$what: message on stderr ($err)");
        expect($before, file_get_contents($file), "$what: file untouched");
        holds(!str_contains($out . $err, $key), "$what: key not printed");
    }

    // Nothing counts any more (all refunded): the file is replaced by an empty list, not kept.
    [$code] = $run($config, $key, scripted([$ok('{"result":"success","data":{"purchase_list":[]}}')], $calls));
    expect([0, []], [$code, json_decode(file_get_contents($file), true)['events']], 'empty answer replaces the file: an old list would not be the truth');

    // --file / KEHAI_FILE relative to the working directory.
    [$code] = $run($config, $key, scripted([$ok()], $calls), 'upload/events.json');
    holds($code === 0 && is_file($tmp . '/upload/events.json'), '--file relative to the working directory');

    // Paths and messages are printed as plain text, also when they carry escape sequences.
    file_put_contents($tmp . '/afile', 'x');
    [$code, $out, $err] = $run($config, $key, scripted([$ok()], $calls), "afile/sub\x1b[2J\x1b]0;owned\x07/events.json");
    holds($code === Fetch::EXIT_ERROR && str_contains($err, 'Cannot create directory'), 'unwritable target: exit 1 with a message');
    holds(Kehai\Text::isPlain($out . $err), 'error output is plain text: ' . json_encode($err));
    [$code, $out, $err] = $run($config, $key, scripted([$ok()], $calls), "out\x1b[31m.json");
    holds($code === Fetch::EXIT_OK && Kehai\Text::isPlain($out . $err), 'written line is plain text: ' . json_encode($out));

    echo "Kehai Digistore24 and fetch: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kehai Digistore24 and fetch: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
} finally {
    removeTree($tmp);
}
