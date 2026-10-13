<?php

namespace Kehai;

/**
 * The one place where Kehai writes to the PHP error log (Kirby-free).
 *
 * Every message is written once per process: a page reads the data file
 * for the bubble and for each summary, and a stale file must not fill the
 * log with copies. Messages pass Text::plain() — they may quote a value
 * from the data file or the source. Tests call forget() to see a message
 * again.
 */
final class Log
{
    /** @var array<string, true> */
    private static array $seen = [];

    public static function error(string $message): void
    {
        $message = Text::plain($message, 500);
        if (isset(self::$seen[$message])) {
            return;
        }
        self::$seen[$message] = true;
        error_log($message);
    }

    /** Forgets the messages already written (tests only). */
    public static function forget(): void
    {
        self::$seen = [];
    }
}
