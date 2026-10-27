<?php

namespace Sayamaapps\Kehai;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Http\Response;

/**
 * The Kirby glue: the functions and methods the plugin's registration and its
 * snippets call. Every method takes the Kirby app, so nothing reads it
 * implicitly (IDM-2). The plugin's public names are wrappers in legacy.php.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Plugin
{
	/** Cache limit of a page that shows Kehai content, in minutes. */
	private const PAGE_CACHE_MINUTES = 15;

	/** Cache limit of the route's answer, in seconds. */
	private const ROUTE_CACHE_SECONDS = 60;

	/** Address of a file in assets/ (Kirby serves plugin assets from /media/plugins/…, with a version hash). */
	public static function assetUrl(App $kirby, string $file): string|null
	{
		return $kirby->plugin('sayamaapps/kehai')?->asset($file)?->url();
	}

	/**
	 * Whether the snippet prints the bubble: there is a real event to show,
	 * or pull mode is on and has its key. In pull mode the container and the
	 * script are printed even while there is nothing to show, since the
	 * script's call of /kehai/events.json is what triggers the fetch (on
	 * PHP-FPM and LiteSpeed a page render does too); the visitor sees nothing
	 * until there is an event.
	 *
	 * @param list<string>|null $products
	 */
	public static function bubbleShown(App $kirby, array|null $products = null): bool
	{
		$config = self::config($kirby);
		if (!$config['active'] || !$config['bubble']) {
			return false;
		}
		if (Pull::canFinishEarly()) {
			self::pullLater($kirby, $config);
		}
		self::cacheLimit($kirby);
		$now = new DateTimeImmutable('now');
		$keys = $products === null ? null : array_values(array_intersect(Config::keys($config), $products));
		$shown = Display::bubble(Events::usable($config, $now)['events'], $config, $now, I18n::language(), $keys) !== [];
		// In pull mode the markup is printed anyway, so the script calls the route — when there is a key to fetch with.
		return $shown || ($config['pull'] && Options::key(Options::secretsFile($kirby)) !== null);
	}

	/**
	 * A page that shows Kehai content may stay in Kirby's page cache for
	 * 15 minutes at most: a cached page must not keep showing a count after
	 * the data has changed or gone stale.
	 */
	public static function cacheLimit(App $kirby): void
	{
		$kirby->response()->expires(self::PAGE_CACHE_MINUTES);
	}

	/** The checked configuration of the site (Config::normalize()). */
	public static function config(App $kirby): array
	{
		return Options::config($kirby)['config'];
	}

	/**
	 * Language and `kehai.texts` for this request. Snippets call it before
	 * printing text.
	 */
	public static function i18n(App $kirby, string|null $language = null): string
	{
		I18n::setLanguage($language ?? Options::language($kirby));
		I18n::setOverrides(Options::get($kirby, 'texts'));
		return I18n::language();
	}

	/**
	 * Product keys from the route's `products` parameter (comma separated), or null for all.
	 *
	 * @return list<string>|null
	 */
	public static function keys(mixed $value, array $config): array|null
	{
		if (!is_string($value) || trim($value) === '') {
			return null;
		}
		return array_values(array_intersect(Config::keys($config), array_map('trim', explode(',', $value))));
	}

	/** In pull mode: remember a fetch for after the answer, if one is due. */
	public static function pullLater(App $kirby, array $config): void
	{
		if (!$config['active'] || !$config['pull'] || !Pull::due($config, time())) {
			return;
		}
		$key = Options::key(Options::secretsFile($kirby));
		if ($key === null) {
			Log::error('Kehai: kehai.pull is on, but site/config/' . Options::SECRETS . ' has no key');
			return;
		}
		Pull::later($config, $key);
	}

	/**
	 * GET /kehai/events.json — the bubble's entries: sentence and time, no
	 * more. Short cache (60 s): the file changes at most every few minutes,
	 * and the script computes the age of each entry itself.
	 */
	public static function route(App $kirby): Response|false
	{
		$config = self::config($kirby);
		if (!$config['active'] || !$config['bubble']) {
			return false; // Kirby's 404: the module does not reveal itself
		}
		self::pullLater($kirby, $config);

		$language = get('lang');
		$language = self::i18n($kirby, is_string($language) && in_array($language, I18n::LANGUAGES, true) ? $language : null);
		$now = new DateTimeImmutable('now');
		$events = Display::bubble(Events::usable($config, $now)['events'], $config, $now, $language, self::keys(get('products'), $config));
		$body = [
			'now'    => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
			'texts'  => Display::times(),
			'events' => $events,
		];
		return new Response((string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json', 200, [
			'Cache-Control'          => 'public, max-age=' . self::ROUTE_CACHE_SECONDS,
			'X-Content-Type-Options' => 'nosniff',
			'X-Robots-Tag'           => 'noindex',
		]);
	}

	/**
	 * The summary for a product key or group, or null (= show nothing:
	 * switched off, file missing or too old, fewer than the minimum).
	 *
	 * @return array{count: int, days: int, product: string, text: string}|null
	 */
	public static function summary(App $kirby, string $name, int|null $days = null, int|null $min = null): array|null
	{
		$config = self::config($kirby);
		if (!$config['active'] || !$config['summary']) {
			return null;
		}
		if (Pull::canFinishEarly()) {
			self::pullLater($kirby, $config);
		}
		self::cacheLimit($kirby);
		$language = self::i18n($kirby);
		$now = new DateTimeImmutable('now');
		$usable = Events::usable($config, $now);
		return Display::summary($usable['events'], $config, $now, $language, $name, $days, $min, $usable['from']);
	}
}
