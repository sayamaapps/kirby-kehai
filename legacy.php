<?php

/**
 * Names of the 1.x API, kept until the next major version (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases and
 * function wrappers only; no logic here. Nothing is reported at run time: the
 * old names keep working silently, as in 1.x, and are marked @deprecated.
 */

/**
 * Every 1.x class name is an alias, made here and not on first use: `catch`
 * and `instanceof` do not autoload, so a lazy alias would miss them.
 *
 * @deprecated use Sayamaapps\Kehai\… (class aliases, removed in the next major version)
 */
foreach (['Check', 'Cli', 'Config', 'Digistore24', 'Display', 'Events', 'Fetch', 'Http', 'I18n', 'Log', 'Options', 'Pull', 'Source', 'SourceException', 'Text'] as $kehaiName) {
	class_alias('Sayamaapps\\Kehai\\' . $kehaiName, 'Kehai\\' . $kehaiName);
}
unset($kehaiName);

if (Kirby\Cms\Helpers::hasOverride('kehai_option') === false) {
	/**
	 * Reads `kehai.<key>`.
	 *
	 * @deprecated use Sayamaapps\Kehai\Options::get()
	 */
	function kehai_option(string $key, mixed $default = null): mixed
	{
		return Sayamaapps\Kehai\Options::get(Kirby\Cms\App::instance(), $key, $default);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_config') === false) {
	/**
	 * The checked configuration.
	 *
	 * @deprecated use Sayamaapps\Kehai\Plugin::config()
	 */
	function kehai_config(): array
	{
		return Sayamaapps\Kehai\Plugin::config(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_i18n') === false) {
	/**
	 * Language and texts for this request.
	 *
	 * @deprecated use Sayamaapps\Kehai\Plugin::i18n()
	 */
	function kehai_i18n(string|null $language = null): string
	{
		return Sayamaapps\Kehai\Plugin::i18n(Kirby\Cms\App::instance(), $language);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_asset_url') === false) {
	/**
	 * Address of a file in assets/.
	 *
	 * @deprecated use Sayamaapps\Kehai\Plugin::assetUrl()
	 */
	function kehai_asset_url(string $file): string|null
	{
		return Sayamaapps\Kehai\Plugin::assetUrl(Kirby\Cms\App::instance(), $file);
	}
}

// The four 1.x functions that were never documented. Same behaviour as in 1.x.
if (Kirby\Cms\Helpers::hasOverride('kehai_pull_later') === false) {
	/** In pull mode: remember a fetch for after the answer, if one is due. */
	function kehai_pull_later(Kirby\Cms\App $kirby, array $config): void
	{
		Sayamaapps\Kehai\Plugin::pullLater($kirby, $config);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_cache_limit') === false) {
	/** A page with Kehai content stays in the page cache for 15 minutes at most. */
	function kehai_cache_limit(Kirby\Cms\App $kirby): void
	{
		Sayamaapps\Kehai\Plugin::cacheLimit($kirby);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_keys') === false) {
	/** Product keys from the route's `products` parameter (comma separated), or null for all. */
	function kehai_keys(mixed $value, array $config): array|null
	{
		return Sayamaapps\Kehai\Plugin::keys($value, $config);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_route') === false) {
	/** GET /kehai/events.json, as in 1.x. */
	function kehai_route(): Kirby\Http\Response|false
	{
		return Sayamaapps\Kehai\Plugin::route(Kirby\Cms\App::instance());
	}
}
