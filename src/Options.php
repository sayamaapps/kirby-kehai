<?php

namespace Sayamaapps\Kehai;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Helpers;
use WeakMap;

/**
 * Reads `kehai.*` options from Kirby and hands them to Config.
 *
 * `null` counts as "not set", like Kirby's own option(), and both
 * spellings work, flat (`'kehai.active' => true`) and nested
 * (`'kehai' => ['active' => true]`).
 *
 * @package   Kirby Kehai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Options
{
	/** Option prefix of 1.x, read as a fallback until the next major version. */
	public const LEGACY_PREFIX = 'kehai.';

	/** Option prefix since 2.0 (OPT-1). */
	public const PREFIX = 'sayamaapps.kehai.';

	/** The file with the API key for pull mode, in Kirby's config root. Keep it out of git. */
	public const SECRETS = 'kehai.php';

	/** Project root: Kirby's `base` root, otherwise the directory above `site/`. */
	public static function base(App $kirby): string
	{
		return $kirby->root('base') ?? dirname((string)$kirby->root('site'));
	}

	/**
	 * The checked configuration, once per Kirby instance. Problems are
	 * logged once (Log), so a typo in the config shows up in the error log
	 * without filling it.
	 *
	 * @return array{config: array<string,mixed>, problems: list<string>}
	 */
	public static function config(App $kirby): array
	{
		static $cache = null;
		$cache ??= new WeakMap();
		if (isset($cache[$kirby])) {
			return $cache[$kirby];
		}
		$raw = [];
		foreach (Config::KEYS as $key) {
			$value = self::get($kirby, $key);
			$raw[$key] = $key === 'file' && $value instanceof Closure ? $value() : $value;
		}
		$result = Config::normalize($raw, self::base($kirby));
		if ($result['config']['active']) {
			foreach ($result['problems'] as $problem) {
				Log::error('Kehai: ' . $problem);
			}
		}
		return $cache[$kirby] = $result;
	}

	/**
	 * `sayamaapps.kehai.<key>`, falling back to the 1.x spelling `kehai.<key>`
	 * (reported as a deprecation in debug mode), then the default.
	 */
	public static function get(App $kirby, string $key, mixed $default = null): mixed
	{
		$value = $kirby->option(self::PREFIX . $key);
		if ($value !== null) {
			return $value;
		}

		$legacy = $kirby->option(self::LEGACY_PREFIX . $key);
		if ($legacy !== null) {
			Helpers::deprecated(
				'Kehai: kehai.' . $key . ' is deprecated, use sayamaapps.kehai.' . $key,
				'option-prefix'
			);
		}

		return $legacy ?? $default;
	}

	/**
	 * The API key from `site/config/kehai.php` (`<?php return ['key' => '…'];`),
	 * or null. Only pull mode and a local `kehai fetch` read it; in the
	 * recommended setup the file does not exist on the web server.
	 */
	public static function key(string $file): string|null
	{
		if (!is_file($file)) {
			return null;
		}
		$data = (static fn () => include $file)();
		$key = is_array($data) ? ($data['key'] ?? null) : null;
		return is_string($key) && trim($key) !== '' ? trim($key) : null;
	}

	/**
	 * Language of the visitor-facing text: `kehai.language`, otherwise the
	 * language of the current request (multi-language sites), the default
	 * language, the first two letters of Kirby's `locale` option, English.
	 */
	public static function language(App $kirby): string
	{
		$candidates = [self::get($kirby, 'language')];
		try {
			$candidates[] = $kirby->language()?->code();
			$candidates[] = $kirby->defaultLanguage()?->code();
		} catch (\Throwable) {
			// No languages configured.
		}
		$locale = $kirby->option('locale');
		if (is_array($locale)) {
			$locale = $locale[LC_ALL] ?? reset($locale);
		}
		$candidates[] = is_string($locale) ? substr($locale, 0, 2) : null;

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && in_array(strtolower($candidate), I18n::LANGUAGES, true)) {
				return strtolower($candidate);
			}
		}
		return 'en';
	}

	public static function secretsFile(App $kirby): string
	{
		return $kirby->root('config') . '/' . self::SECRETS;
	}
}
