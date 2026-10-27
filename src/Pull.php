<?php

namespace Sayamaapps\Kehai;

use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * Pull mode (`kehai.pull`, off by default): the site fetches by itself,
 * with the key from site/config/kehai.php.
 *
 *  - At most every `pullInterval` minutes (default 15). A marker file next
 *    to the data file records the last attempt — successful or not — so a
 *    failing source is not asked on every request.
 *  - One fetch at a time: an exclusive, non-blocking lock. A second request
 *    that finds the lock taken does nothing.
 *  - Five seconds for the whole pull (TIMEOUT), however many pages the
 *    source needs: each request gets the time that is left, and none is
 *    made once it is used up.
 *  - On any error the last good file stays; one line goes to the log.
 *  - The page never waits for the source. later() only remembers the job;
 *    it runs at shutdown, after fastcgi_finish_request() (PHP-FPM) or
 *    litespeed_finish_request() has sent the answer. The JSON route
 *    schedules it always (the bubble's script loads that route in the
 *    background); a page render only where such a function exists, since
 *    elsewhere the visitor would wait. In pull mode the bubble snippet prints
 *    its markup even with nothing to show, so that the route gets called.
 *
 * @package   Kirby Kehai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Pull
{
	public const TIMEOUT = 5;

	/** @var array{0: array, 1: string}|null */
	private static array|null $pending = null;
	private static bool $registered = false;

	/** Whether a page render can hand the answer over before the pull runs. */
	public static function canFinishEarly(): bool
	{
		return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
	}

	/** Whether the last attempt is longer ago than `pullInterval`. */
	public static function due(array $config, int $now): bool
	{
		$marker = self::marker($config['file']);
		clearstatcache(true, $marker);
		return !is_file($marker) || (int)filemtime($marker) <= $now - $config['pullInterval'] * 60;
	}

	/**
	 * Runs the remembered pull after handing the answer to the visitor.
	 *
	 * @param $transport see run(); null uses the network
	 * @param $clock see run(); null uses the monotonic clock
	 */
	public static function flush(Closure|null $transport = null, Closure|null $clock = null): string|null
	{
		if (self::$pending === null) {
			return null;
		}
		[$config, $key] = self::$pending;
		self::$pending = null;
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} elseif (function_exists('litespeed_finish_request')) {
			litespeed_finish_request();
		}
		return self::run($config, $key, null, $transport, $clock);
	}

	/** Remembers a pull for the end of the request (once per request). */
	public static function later(array $config, string $key): void
	{
		self::$pending ??= [$config, $key];
		if (!self::$registered) {
			self::$registered = true;
			register_shutdown_function([self::class, 'flush']);
		}
	}

	public static function lockFile(string $file): string
	{
		return dirname($file) . '/.kehai-pull.lock';
	}

	public static function marker(string $file): string
	{
		return dirname($file) . '/.kehai-pull';
	}

	public static function pending(): bool
	{
		return self::$pending !== null;
	}

	/**
	 * One pull, now, if it is due and no other process is at it.
	 *
	 * @param $transport Makes the transport for a timeout, fn (int $timeout): Closure.
	 *                  Called once per request, with the whole seconds that are left. Null uses the network.
	 * @param $clock Seconds on a monotonic clock, fn (): float. Null uses hrtime().
	 * @return string `not due`, `locked`, `written` or `failed`
	 */
	public static function run(
		array $config,
		string $key,
		DateTimeImmutable|null $now = null,
		Closure|null $transport = null,
		Closure|null $clock = null,
	): string {
		$now ??= new DateTimeImmutable('now');
		$clock ??= static fn (): float => (float)hrtime(true) / 1e9;
		if (!self::due($config, $now->getTimestamp())) {
			return 'not due';
		}
		$dir = dirname($config['file']);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			Log::error('Kehai: pull mode cannot create ' . $dir);
			return 'failed';
		}
		$lock = @fopen(self::lockFile($config['file']), 'c');
		if ($lock === false) {
			Log::error('Kehai: pull mode cannot open its lock file in ' . $dir);
			return 'failed';
		}
		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			fclose($lock);
			return 'locked';
		}
		try {
			// Another process may have finished a pull while this one waited for the lock.
			if (!self::due($config, $now->getTimestamp())) {
				return 'not due';
			}
			touch(self::marker($config['file']), $now->getTimestamp());
			$deadline = self::withDeadline((float)$clock(), $clock, $transport);
			$source = Fetch::source($config, $key, $deadline);
			Events::write($config['file'], Fetch::collect($source, $config, $now)['data']);
			return 'written';
		} catch (\Throwable $e) {
			Log::error('Kehai: pull failed, the last data file stays: ' . str_replace($key, '[key]', $e->getMessage()));
			return 'failed';
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/**
	 * A transport for the whole pull: every request gets only the whole
	 * seconds that are left of TIMEOUT; once they are used up it throws
	 * without a request. The overshoot is under one second with curl (the
	 * stream fallback's timeout is per read).
	 */
	private static function withDeadline(float $start, Closure $clock, Closure|null $transport): Closure
	{
		return function (string $url, array $headers) use ($start, $clock, $transport): array {
			$left = (float)self::TIMEOUT - ((float)$clock() - $start);
			if ($left <= 0) {
				throw new RuntimeException('the pull reached its time limit of ' . self::TIMEOUT . ' seconds');
			}
			$timeout = (int)ceil($left);
			$request = $transport !== null ? $transport($timeout) : Http::transport($timeout);
			return $request($url, $headers);
		};
	}
}
