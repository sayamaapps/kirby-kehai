<?php
/**
 * Pull mode without a network: at most every N minutes, one fetch at a
 * time (lock), five seconds, the last good file stays on errors, and
 * later() only remembers the job — nothing runs before flush().
 *   php tests/PullTest.php
 */
require_once __DIR__ . '/helpers.php';

use Kehai\Events;
use Kehai\Log;
use Kehai\Pull;

$tmp = tempDir('pull');
$key = 'ds24-pull-key-0000';
$now = new DateTimeImmutable('2026-10-13T10:00:00Z');
$config = testConfig($tmp, ['pull' => true]);
$file = $config['file'];
$fixture = file_get_contents(__DIR__ . '/fixtures/digistore-purchases.json');
$calls = [];
$timeouts = [];
$answer = ['status' => 200, 'body' => $fixture];
/** The transport factory Pull uses; records the timeout it is asked for. */
$serve = function (array $script) use (&$calls, &$timeouts): void {
    Pull::$transports = function (int $timeout) use ($script, &$calls, &$timeouts): Closure {
        $timeouts[] = $timeout;
        return scripted($script, $calls);
    };
};
$at = fn (string $modify) => $now->modify($modify);

try {
    expect(5, Pull::TIMEOUT, 'five seconds');
    expect(true, Pull::due($config, $now->getTimestamp()), 'never pulled: due');

    // A pull: written, with the five-second transport, marker set.
    $serve([$answer]);
    expect('written', Pull::run($config, $key, $now), 'first pull writes');
    expect([5], $timeouts, 'the transport is made with the five-second limit');
    expect(4, count(json_decode(file_get_contents($file), true)['events']), 'the anonymous file');
    expect(false, Pull::due($config, $now->getTimestamp()), 'right after: not due');

    // At most every 15 minutes.
    $calls = [];
    expect('not due', Pull::run($config, $key, $at('+14 minutes')), '14 minutes later: not due');
    expect(0, count($calls), 'no request while not due');
    touch(Pull::marker($file), $at('-15 minutes')->getTimestamp());
    expect(true, Pull::due($config, $now->getTimestamp()), '15 minutes after the last attempt: due');
    expect(false, Pull::due(testConfig($tmp, ['pull' => true, 'pullInterval' => 30]), $now->getTimestamp()), 'kehai.pullInterval 30');

    // One at a time: while another process holds the lock, nothing happens.
    $lock = fopen(Pull::lockFile($file), 'c');
    holds(flock($lock, LOCK_EX | LOCK_NB), 'test holds the lock');
    $calls = [];
    $serve([$answer]);
    expect('locked', Pull::run($config, $key, $now), 'lock taken: skipped');
    expect(0, count($calls), 'lock taken: no request');
    flock($lock, LOCK_UN);
    fclose($lock);

    // Errors: the last good file stays, one log line without the key, no retry before the interval.
    foreach ([
        'time-out' => new RuntimeException('timed out after 5 seconds ' . $key),
        'HTTP 500' => ['status' => 500, 'body' => ''],
        'broken JSON' => ['status' => 200, 'body' => '{"result'],
    ] as $what => $failure) {
        touch(Pull::marker($file), $at('-1 hour')->getTimestamp());
        $before = file_get_contents($file);
        $serve([$failure]);
        Log::forget();
        [$outcome, $log] = logged(fn () => Pull::run($config, $key, $now));
        expect('failed', $outcome, "$what: failed");
        expect($before, file_get_contents($file), "$what: last good file untouched");
        holds(str_contains($log, 'Kehai: pull failed, the last data file stays: Digistore24'), "$what: logged ($log)");
        holds(!str_contains($log, $key), "$what: key not logged");
        foreach (PERSONAL as $secret) {
            holds(!str_contains($log, $secret), "$what: nothing personal logged");
        }
        expect(false, Pull::due($config, $now->getTimestamp()), "$what: the failed attempt counts, no retry on the next request");
    }
    expect([], glob(dirname($file) . '/.*.tmp'), 'no temporary files after failures');

    // later(): remembered, not run; flush() runs it (at shutdown, after the answer) with the real clock.
    touch(Pull::marker($file), time() - 3600);
    $calls = [];
    $serve([$answer]);
    Pull::later($config, $key);
    holds(Pull::pending(), 'later() remembers the pull');
    expect(0, count($calls), 'later() makes no request');
    Pull::later($config, $key);
    expect('written', Pull::flush(), 'flush() runs it once');
    expect(1, count($calls), 'one request for two later() calls');
    expect(null, Pull::flush(), 'nothing left to flush');

    // A data file directory that does not exist yet is created.
    $fresh = testConfig($tmp, ['pull' => true, 'file' => 'other/dir/events.json']);
    $serve([$answer]);
    expect('written', Pull::run($fresh, $key, $now), 'directory created');
    holds(is_file($tmp . '/other/dir/events.json') && is_file($tmp . '/other/dir/.kehai-pull'), 'file and marker in the new directory');

    echo "Kehai pull mode: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kehai pull mode: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
} finally {
    Pull::$transports = null;
    removeTree($tmp);
}
