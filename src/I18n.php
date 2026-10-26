<?php

namespace Sayamaapps\Kehai;

/**
 * Visitor-facing strings, Kirby-free.
 *
 * The tables live in translations/<language>.php and are also registered as
 * Kirby plugin translations under `kehai.<key>` (index.php), so site
 * snippets can use t('kehai.close'). Kehai itself reads its own tables: a
 * missing Kirby translation must never silently turn a German notice
 * English.
 *
 * `kehai.texts` replaces single strings, per language or for all:
 *
 *   'kehai.texts' => ['de' => ['bubble.purchase' => 'Jemand hat „{product}“ gekauft']]
 *   'kehai.texts' => ['close' => 'OK']
 *
 * The language is set per request (Options::language()) and defaults to
 * English. Missing keys fall back to English, then to the key.
 */
final class I18n
{
	/** Languages Kehai ships. English is the default and the fallback. */
	public const LANGUAGES = ['en', 'de'];

	private static string $language = 'en';

	/** @var array<string,string> */
	private static array $overrides = [];

	/** @var array<string, array<string,string>> */
	private static array $tables = [];

	/** Whether a key has a text (override, current language or English). */
	public static function has(string $key): bool
	{
		return isset(self::$overrides[$key]) || isset(self::table(self::$language)[$key]) || isset(self::table('en')[$key]);
	}

	public static function language(): string
	{
		return self::$language;
	}

	public static function setLanguage(string $language): void
	{
		self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
	}

	/** Strings from `kehai.texts` for the current language; flat keys apply to every language. */
	public static function setOverrides(mixed $texts): void
	{
		self::$overrides = [];
		if (!is_array($texts)) {
			return;
		}
		// Flat keys first (language tables are arrays and skipped), then the
		// current language's table, which wins.
		$perLanguage = is_array($texts[self::$language] ?? null) ? $texts[self::$language] : [];
		foreach ([$texts, $perLanguage] as $table) {
			foreach ($table as $key => $text) {
				if (is_string($key) && is_string($text)) {
					self::$overrides[$key] = $text;
				}
			}
		}
	}

	/** `{name}` placeholders are replaced with the given values, unescaped. */
	public static function t(string $key, array $values = []): string
	{
		$text = self::$overrides[$key] ?? self::table(self::$language)[$key] ?? self::table('en')[$key] ?? $key;
		if ($values === []) {
			return $text;
		}
		$pairs = [];
		foreach ($values as $name => $value) {
			$pairs['{' . $name . '}'] = (string)$value;
		}
		return strtr($text, $pairs);
	}

	/** @return array<string,string> */
	public static function table(string $language): array
	{
		if (!in_array($language, self::LANGUAGES, true)) {
			return [];
		}
		return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
	}
}
