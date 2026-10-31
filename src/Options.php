<?php

namespace Sayamaapps\Kehai;

use Closure;
use Kirby\Cms\App;
use WeakMap;

/**
 * Reads Kehai's options: `sayamaapps.kehai.<key>`, falling back to the 1.x
 * name `kehai.<key>`, which is read silently. Hands them to Config.
 *
 * The flat form (`'sayamaapps.kehai.active' => true`) is the one to use. The
 * nested form (`'kehai' => ['active' => true]`) works for the 1.x prefix only:
 * Kirby resolves `sayamaapps.kehai.*` through the plugin's own options block
 * first, which shadows a nested `sayamaapps` array.
 *
 * Defaults are given here and at the call sites. The `options` block of the
 * plugin (config/options.php) lists the keys with null: a default there would
 * hide the 1.x spelling.
 *
 * `null` counts as "not set", like Kirby's own option().
 *
 * @package   Kirby Kehai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Options
{
	/** Option prefix since 1.3. */
	public const OPTION_PREFIX = 'sayamaapps.kehai.';

	/**
	 * Option prefix of 1.x (`kehai.<key>`). Still read as a fallback until 2.0.
	 *
	 * @deprecated 1.3 use OPTION_PREFIX; removal planned for 2.0
	 */
	public const PREFIX = 'kehai.';

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
	 * Reads an option under the sayamaapps.kehai.* spelling, then under the
	 * 1.x kehai.* spelling, which is read silently. Returns $default when
	 * neither is set.
	 */
	public static function get(App $kirby, string $key, mixed $default = null): mixed
	{
		$value = $kirby->option(self::OPTION_PREFIX . $key);
		if ($value !== null) {
			return $value;
		}

		return $kirby->option(self::PREFIX . $key) ?? $default;
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
	 * Language of the visitor-facing text: `sayamaapps.kehai.language` (or `kehai.language`), otherwise the
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
