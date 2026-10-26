<?php

/**
 * Names of the 1.x API, kept until 2.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases and
 * function wrappers only; no logic here.
 */

class_alias(\Sayamaapps\Kehai\Check::class, 'Kehai\Check');
class_alias(\Sayamaapps\Kehai\Cli::class, 'Kehai\Cli');
class_alias(\Sayamaapps\Kehai\Config::class, 'Kehai\Config');
class_alias(\Sayamaapps\Kehai\Digistore24::class, 'Kehai\Digistore24');
class_alias(\Sayamaapps\Kehai\Display::class, 'Kehai\Display');
class_alias(\Sayamaapps\Kehai\Events::class, 'Kehai\Events');
class_alias(\Sayamaapps\Kehai\Fetch::class, 'Kehai\Fetch');
class_alias(\Sayamaapps\Kehai\Http::class, 'Kehai\Http');
class_alias(\Sayamaapps\Kehai\I18n::class, 'Kehai\I18n');
class_alias(\Sayamaapps\Kehai\Log::class, 'Kehai\Log');
class_alias(\Sayamaapps\Kehai\Options::class, 'Kehai\Options');
class_alias(\Sayamaapps\Kehai\Pull::class, 'Kehai\Pull');
class_alias(\Sayamaapps\Kehai\Source::class, 'Kehai\Source');
class_alias(\Sayamaapps\Kehai\SourceException::class, 'Kehai\SourceException');
class_alias(\Sayamaapps\Kehai\Text::class, 'Kehai\Text');

if (Kirby\Cms\Helpers::hasOverride('kehai_option') === false) {
	/** Reads `kehai.<key>`. Deprecated: use Sayamaapps\Kehai\Options::get(). */
	function kehai_option(string $key, mixed $default = null): mixed
	{
		Kirby\Cms\Helpers::deprecated(
			'kehai_option() is deprecated, use Sayamaapps\Kehai\Options::get()',
			'kehai-function'
		);

		return Sayamaapps\Kehai\Options::get(Kirby\Cms\App::instance(), $key, $default);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_config') === false) {
	/** The checked configuration. Deprecated: use Sayamaapps\Kehai\Plugin::config(). */
	function kehai_config(): array
	{
		Kirby\Cms\Helpers::deprecated(
			'kehai_config() is deprecated, use Sayamaapps\Kehai\Plugin::config()',
			'kehai-function'
		);

		return Sayamaapps\Kehai\Plugin::config(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_i18n') === false) {
	/** Language and texts for this request. Deprecated: use Sayamaapps\Kehai\Plugin::i18n(). */
	function kehai_i18n(string|null $language = null): string
	{
		Kirby\Cms\Helpers::deprecated(
			'kehai_i18n() is deprecated, use Sayamaapps\Kehai\Plugin::i18n()',
			'kehai-function'
		);

		return Sayamaapps\Kehai\Plugin::i18n(Kirby\Cms\App::instance(), $language);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kehai_asset_url') === false) {
	/** Address of a file in assets/. Deprecated: use Sayamaapps\Kehai\Plugin::assetUrl(). */
	function kehai_asset_url(string $file): string|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kehai_asset_url() is deprecated, use Sayamaapps\Kehai\Plugin::assetUrl()',
			'kehai-function'
		);

		return Sayamaapps\Kehai\Plugin::assetUrl(Kirby\Cms\App::instance(), $file);
	}
}
