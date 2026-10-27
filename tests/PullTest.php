<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kehai\Digistore24;
use Sayamaapps\Kehai\Log;
use Sayamaapps\Kehai\Pull;

/**
 * Pull mode without a network: at most every N minutes, one fetch at a
 * time (lock), five seconds, the last good file stays on errors, and
 * later() only remembers the job — nothing runs before flush().
 *   php tests/PullTest.php
 */
#[CoversClass(Pull::class)]
final class PullTest extends TestCase
{
	public function testPullMode(): void
	{


		$tmp = tempDir('pull');
		$key = 'ds24-pull-key-0000';
		$now = new DateTimeImmutable('2026-10-13T10:00:00Z');
		$config = testConfig($tmp, ['pull' => true]);
		$file = $config['file'];
		$fixture = file_get_contents(__DIR__ . '/fixtures/digistore-purchases.json');
		$calls = [];
		$timeouts = [];
		$clockFn = null;
		$answer = ['status' => 200, 'body' => $fixture];
		/** The transport factory Pull uses; records the timeout it is asked for. */
		$transport = null;
		$serve = function (array $script) use (&$transport, &$calls, &$timeouts): void {
			$transport = function (int $timeout) use ($script, &$calls, &$timeouts): Closure {
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
			expect('written', Pull::run($config, $key, $now, $transport, $clockFn), 'first pull writes');
			expect([5], $timeouts, 'the transport is made with the five-second limit');
			expect(4, count(json_decode(file_get_contents($file), true)['events']), 'the anonymous file');
			expect(false, Pull::due($config, $now->getTimestamp()), 'right after: not due');

			// At most every 15 minutes.
			$calls = [];
			expect('not due', Pull::run($config, $key, $at('+14 minutes'), $transport, $clockFn), '14 minutes later: not due');
			expect(0, count($calls), 'no request while not due');
			touch(Pull::marker($file), $at('-15 minutes')->getTimestamp());
			expect(true, Pull::due($config, $now->getTimestamp()), '15 minutes after the last attempt: due');
			expect(false, Pull::due(testConfig($tmp, ['pull' => true, 'pullInterval' => 30]), $now->getTimestamp()), 'kehai.pullInterval 30');

			// One at a time: while another process holds the lock, nothing happens.
			$lock = fopen(Pull::lockFile($file), 'c');
			holds(flock($lock, LOCK_EX | LOCK_NB), 'test holds the lock');
			$calls = [];
			$serve([$answer]);
			expect('locked', Pull::run($config, $key, $now, $transport, $clockFn), 'lock taken: skipped');
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
				[$outcome, $log] = logged(fn () => Pull::run($config, $key, $now, $transport, $clockFn));
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

			// One five-second deadline for the whole pull, however many pages: each request gets what is left.
			$base = json_decode($fixture, true)['data']['purchase_list'][0];
			$fullPage = array_fill(0, Digistore24::PAGE_SIZE, $base);
			foreach ($fullPage as $i => &$entry) {
				$entry['id'] = 'P' . $i;
			}
			unset($entry);
			$pageBody = fn (array $list) => json_encode(['result' => 'success', 'data' => ['purchase_list' => $list]]);
			$tick = 0.0;
			$slowCalls = 0;
			$slowTimeouts = [];
			$clockFn = function () use (&$tick): float {
				return $tick;
			};
			// Every request takes two seconds on the fake clock; the answers are served in order.
			$slow = function (array $queue, float $step = 2.0) use (&$tick, &$slowCalls, &$slowTimeouts, &$transport): void {
				$answers = $queue;
				$transport = function (int $timeout) use (&$answers, &$tick, &$slowCalls, &$slowTimeouts, $step): Closure {
					$slowTimeouts[] = $timeout;
					return function () use (&$answers, &$tick, &$slowCalls, $step): array {
						$slowCalls++;
						$tick += $step;
						return array_shift($answers);
					};
				};
			};

			touch(Pull::marker($file), $at('-1 hour')->getTimestamp());
			$before = file_get_contents($file);
			$tick = 0.0;
			$slowCalls = 0;
			$slowTimeouts = [];
			$slow(array_fill(0, 5, ['status' => 200, 'body' => $pageBody($fullPage)]));
			[$outcome, $log] = logged(fn () => Pull::run($config, $key, $now, $transport, $clockFn));
			expect('failed', $outcome, 'slow pages: the deadline ends the pull');
			expect([5, 3, 1], $slowTimeouts, 'slow pages: each request gets only the time that is left');
			expect(3, $slowCalls, 'slow pages: no request once the five seconds are used up');
			holds(str_contains($log, 'reached its time limit of 5 seconds'), "slow pages: logged ($log)");
			holds(str_contains($log, 'Kehai: pull failed, the last data file stays: Digistore24 not reachable'), 'slow pages: logged as a failed pull');
			expect($before, file_get_contents($file), 'slow pages: last good file untouched');
			expect(false, Pull::due($config, $now->getTimestamp()), 'slow pages: the attempt counts, no retry on the next request');

			touch(Pull::marker($file), $at('-1 hour')->getTimestamp());
			$tick = 100.0;
			$slowCalls = 0;
			$slowTimeouts = [];
			$slow([['status' => 200, 'body' => $pageBody($fullPage)], ['status' => 200, 'body' => $pageBody([$base])]]);
			[$outcome, $log] = logged(fn () => Pull::run($config, $key, $now, $transport, $clockFn));
			expect('written', $outcome, "two pages of two seconds each fit into the deadline ($log)");
			expect([5, 3], $slowTimeouts, 'two pages: the second request gets the three seconds that are left');
			expect(2, $slowCalls, 'two pages: two requests');

			touch(Pull::marker($file), $at('-1 hour')->getTimestamp());
			$tick = 200.0;
			$slowCalls = 0;
			$slowTimeouts = [];
			$slow(array_fill(0, 3, ['status' => 200, 'body' => $pageBody($fullPage)]), 4.5);
			[$outcome] = logged(fn () => Pull::run($config, $key, $now, $transport, $clockFn));
			expect(['failed', [5, 1], 2], [$outcome, $slowTimeouts, $slowCalls], 'half a second left is rounded up to one (never 0 = no limit); the third page is refused');
			$clockFn = null;

			// later(): remembered, not run; flush() runs it (at shutdown, after the answer) with the real clock.
			touch(Pull::marker($file), time() - 3600);
			$calls = [];
			$serve([$answer]);
			Pull::later($config, $key);
			holds(Pull::pending(), 'later() remembers the pull');
			expect(0, count($calls), 'later() makes no request');
			Pull::later($config, $key);
			expect('written', Pull::flush($transport), 'flush() runs it once');
			expect(1, count($calls), 'one request for two later() calls');
			expect(null, Pull::flush($transport), 'nothing left to flush');

			// A data file directory that does not exist yet is created.
			$fresh = testConfig($tmp, ['pull' => true, 'file' => 'other/dir/events.json']);
			$serve([$answer]);
			expect('written', Pull::run($fresh, $key, $now, $transport, $clockFn), 'directory created');
			holds(is_file($tmp . '/other/dir/events.json') && is_file($tmp . '/other/dir/.kehai-pull'), 'file and marker in the new directory');

		} finally {
			removeTree($tmp);
		}
	}
}
