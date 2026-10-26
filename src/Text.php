<?php

namespace Kehai;

/**
 * Text that is printed to a terminal or written to a log (Kirby-free).
 *
 * Everything Kehai prints comes from somewhere it does not control: the
 * answer of the source, the data file on the server, the site's own
 * configuration. A value with an escape sequence could rewrite the
 * terminal of whoever runs `kehai check` or reads the log (move the
 * cursor, hide a line, change the window title), a bidi override could
 * make "ok" read as something else. So every such value goes through
 * plain() before it is printed:
 *
 *  - not a string or number → empty;
 *  - invalid UTF-8 is replaced (mb_scrub), never passed on;
 *  - control characters (C0, C1, DEL — that includes ESC) and line and
 *    paragraph separators become one space, invisible format characters
 *    (bidi overrides, zero-width) are removed;
 *  - at most $max characters, then "…".
 */
final class Text
{
	/** True if $text contains nothing plain() would remove (tests and the check use it). */
	public static function isPlain(string $text): bool
	{
		return preg_match('//u', $text) === 1 && preg_match('/[\p{Cf}\p{Zl}\p{Zp}]|[^\P{Cc}\n]/u', $text) === 0;
	}

	public static function plain(mixed $value, int $max = 200): string
	{
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			return '';
		}
		$text = mb_scrub((string)$value, 'UTF-8');
		$text = (string)preg_replace(['/\p{Cf}+/u', '/[\p{Cc}\p{Zl}\p{Zp}]+/u'], ['', ' '], $text);
		$text = trim((string)preg_replace('/ {2,}/', ' ', $text));
		if ($max > 0 && mb_strlen($text) > $max) {
			$text = rtrim(mb_substr($text, 0, $max - 1)) . '…';
		}
		return $text;
	}
}
