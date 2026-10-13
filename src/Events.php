<?php

namespace Kehai;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The data file: format, the minimal fields, atomic writing, reading and
 * the freshness check (Kirby-free).
 *
 *   {
 *     "format": 1,
 *     "generated": "2026-10-13T09:00:12Z",
 *     "source": "digistore24",
 *     "events": [
 *       {"type": "purchase", "product": "jahreskurs", "at": "2026-10-13T07:41:00Z", "country": "AT"}
 *     ]
 *   }
 *
 * An event has exactly these fields. `type` is one of TYPES, `product` a
 * product key from the site's configuration (never the source's id or
 * name), `at` the time in UTC rounded down to the minute, `country` a
 * two-letter code and only present when `kehai.country` is on. clean() is
 * the one gate every event passes, when it is written and when it is read:
 * whatever else a source adapter or a hand-edited file carries is dropped.
 *
 * Every fetch replaces the file completely (no merge with earlier runs), so
 * a purchase that is refunded later disappears with the next run, and
 * nothing that is not in the source's current answer is ever shown.
 */
final class Events
{
    public const FORMAT = 1;

    /** Event types this version knows. `signup` (Brevo) is planned. */
    public const TYPES = ['purchase'];

    /** At most this many events are kept, newest first. */
    public const MAX_EVENTS = 2000;

    /** An event further in the future than this (seconds) is a clock error and is dropped. */
    public const FUTURE_TOLERANCE = 300;

    private const AT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z\z/';

    /** UTC, rounded down to the minute, as stored. */
    public static function minute(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:00\Z');
    }

    /**
     * The event with only the allowed fields, or null when it is not a
     * valid event.
     *
     * @param list<string>|null $keys allowed product keys; null = any well-formed key
     * @return array{type:string, product:string, at:string, country?:string}|null
     */
    public static function clean(mixed $event, bool $country, ?array $keys = null): ?array
    {
        if (!is_array($event)) {
            return null;
        }
        $type = $event['type'] ?? null;
        $product = $event['product'] ?? null;
        $at = $event['at'] ?? null;
        if (!in_array($type, self::TYPES, true)
            || !is_string($product) || !preg_match(Config::KEY_PATTERN, $product)
            || ($keys !== null && !in_array($product, $keys, true))
            || !is_string($at) || !preg_match(self::AT_PATTERN, $at) || self::parse($at) === null) {
            return null;
        }
        $clean = ['type' => $type, 'product' => $product, 'at' => $at];
        $code = $event['country'] ?? null;
        if ($country && is_string($code) && preg_match('/^[A-Z]{2}\z/', $code)) {
            $clean['country'] = $code;
        }
        return $clean;
    }

    /**
     * The content of a new data file: the cleaned events, newest first.
     *
     * @param list<array> $events
     */
    public static function build(array $events, DateTimeImmutable $generated, string $source, bool $country, ?array $keys = null): array
    {
        $clean = [];
        foreach ($events as $event) {
            if (($event = self::clean($event, $country, $keys)) !== null) {
                $clean[] = $event;
            }
        }
        usort($clean, fn (array $a, array $b) => strcmp($b['at'], $a['at']));
        return [
            'format'    => self::FORMAT,
            'generated' => $generated->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'source'    => $source,
            'events'    => array_slice($clean, 0, self::MAX_EVENTS),
        ];
    }

    /**
     * Writes the file atomically: a temporary file in the same directory,
     * then rename(). A page that reads at the same moment sees the old file
     * or the new one, never half of it, and a failed write leaves the old
     * file as it was.
     *
     * @throws \RuntimeException when the directory or file cannot be written
     */
    public static function write(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory ' . $dir);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Cannot encode the data file');
        }
        $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") === false) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write ' . $tmp);
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot replace ' . $file);
        }
    }

    /**
     * Reads the file. `error` says why there is nothing to use: missing,
     * not JSON, an unknown format. Events that do not pass clean() are
     * dropped silently (and counted in `dropped`).
     *
     * @return array{error: ?string, generated: ?DateTimeImmutable, source: ?string, events: list<array>, dropped: int}
     */
    public static function read(string $file, bool $country = true, ?array $keys = null): array
    {
        $empty = ['error' => null, 'generated' => null, 'source' => null, 'events' => [], 'dropped' => 0];
        if ($file === '' || !is_file($file)) {
            return ['error' => 'missing'] + $empty;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            return ['error' => 'not JSON'] + $empty;
        }
        if (($data['format'] ?? null) !== self::FORMAT) {
            return ['error' => 'unknown format ' . Text::plain(json_encode($data['format'] ?? null), 20) . ' (this version reads format ' . self::FORMAT . ')'] + $empty;
        }
        $generated = self::parse($data['generated'] ?? null);
        if ($generated === null || !is_array($data['events'] ?? null)) {
            return ['error' => 'no valid "generated" time or "events" list'] + $empty;
        }
        $events = [];
        $dropped = 0;
        foreach ($data['events'] as $event) {
            $clean = self::clean($event, $country, $keys);
            $clean === null ? $dropped++ : $events[] = $clean;
        }
        usort($events, fn (array $a, array $b) => strcmp($b['at'], $a['at']));
        return [
            'error'     => null,
            'generated' => $generated,
            'source'    => is_string($data['source'] ?? null) ? Text::plain($data['source'], 40) : null,
            'events'    => $events,
            'dropped'   => $dropped,
        ];
    }

    /**
     * The events a page may use: from a file that exists, is readable and
     * not older than `maxStale` hours, restricted to configured products,
     * without events in the future. When there is nothing to use the reason
     * is logged (once per process) and the list is empty — the page then
     * shows nothing, never an old or made-up entry.
     *
     * @return list<array{type:string, product:string, at:string, country?:string}>
     */
    public static function usable(array $config, DateTimeImmutable $now): array
    {
        $read = self::read($config['file'], $config['country'], Config::keys($config));
        if ($read['error'] !== null) {
            Log::error('Kehai: nothing shown, the data file ' . $config['file'] . ' is ' . ($read['error'] === 'missing' ? 'missing' : 'unusable: ' . $read['error']));
            return [];
        }
        $age = $now->getTimestamp() - $read['generated']->getTimestamp();
        if ($age > $config['maxStale'] * 3600) {
            Log::error('Kehai: nothing shown, the data file ' . $config['file'] . ' was last updated ' . intdiv($age, 3600)
                . ' hours ago (kehai.maxStale is ' . $config['maxStale'] . ')');
            return [];
        }
        $limit = $now->getTimestamp() + self::FUTURE_TOLERANCE;
        return array_values(array_filter($read['events'], fn (array $e) => self::time($e)->getTimestamp() <= $limit));
    }

    /** "2026-10-13T07:41:00Z" as a time, or null — also for dates that do not exist (2026-02-31). */
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value)) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        return $time === false || ($errors !== false && $errors['warning_count'] > 0) ? null : $time;
    }

    public static function time(array $event): DateTimeImmutable
    {
        return new DateTimeImmutable($event['at'], new DateTimeZone('UTC'));
    }
}
