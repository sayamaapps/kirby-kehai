<?php

namespace Kehai;

use Closure;
use DateTimeImmutable;

/**
 * Pull mode (`kehai.pull`, off by default): the site fetches by itself,
 * with the key from site/config/kehai.php.
 *
 *  - At most every `pullInterval` minutes (default 15). A marker file next
 *    to the data file records the last attempt — successful or not — so a
 *    failing source is not asked on every request.
 *  - One fetch at a time: an exclusive, non-blocking lock. A second request
 *    that finds the lock taken does nothing.
 *  - Five seconds at most (TIMEOUT).
 *  - On any error the last good file stays; one line goes to the log.
 *  - The page never waits for the source. later() only remembers the job;
 *    it runs at shutdown, after fastcgi_finish_request() (PHP-FPM) or
 *    litespeed_finish_request() has sent the answer. The JSON route
 *    schedules it always (the bubble's script loads that route in the
 *    background); a page render only where such a function exists, since
 *    elsewhere the visitor would wait.
 */
final class Pull
{
    public const TIMEOUT = 5;

    /**
     * Makes the transport for a timeout: Http::transport() unless a test
     * replaces the network. fn (int $timeout): Closure
     */
    public static ?Closure $transports = null;

    /** @var array{0: array, 1: string}|null */
    private static ?array $pending = null;

    private static bool $registered = false;

    public static function marker(string $file): string
    {
        return dirname($file) . '/.kehai-pull';
    }

    public static function lockFile(string $file): string
    {
        return dirname($file) . '/.kehai-pull.lock';
    }

    /** Whether the last attempt is longer ago than `pullInterval`. */
    public static function due(array $config, int $now): bool
    {
        $marker = self::marker($config['file']);
        clearstatcache(true, $marker);
        return !is_file($marker) || (int) filemtime($marker) <= $now - $config['pullInterval'] * 60;
    }

    /** Whether a page render can hand the answer over before the pull runs. */
    public static function canFinishEarly(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /**
     * One pull, now, if it is due and no other process is at it.
     *
     * @return string `not due`, `locked`, `written` or `failed`
     */
    public static function run(array $config, string $key, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable('now');
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
            $transport = self::$transports !== null ? (self::$transports)(self::TIMEOUT) : Http::transport(self::TIMEOUT);
            $source = Fetch::source($config, $key, $transport);
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

    /** Remembers a pull for the end of the request (once per request). */
    public static function later(array $config, string $key): void
    {
        self::$pending ??= [$config, $key];
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    public static function pending(): bool
    {
        return self::$pending !== null;
    }

    /** Runs the remembered pull after handing the answer to the visitor. */
    public static function flush(): ?string
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
        return self::run($config, $key);
    }
}
