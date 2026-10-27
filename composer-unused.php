<?php

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

// Requirements without PHP symbols the tool can see: the PHP version, the JSON
// extension (json_encode() and json_decode() are plain functions), the mbstring
// extension (mb_* calls are not seen either) and the installer that places the
// plugin in site/plugins.
return static function (Configuration $configuration): Configuration {
	return $configuration
		->addNamedFilter(NamedFilter::fromString('php'))
		->addNamedFilter(NamedFilter::fromString('ext-json'))
		->addNamedFilter(NamedFilter::fromString('ext-mbstring'))
		->addNamedFilter(NamedFilter::fromString('getkirby/composer-installer'));
};
