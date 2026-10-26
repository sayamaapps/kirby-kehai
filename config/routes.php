<?php

use Kirby\Cms\App;
use Sayamaapps\Kehai\Plugin;

// GET /kehai/events.json: the bubble's entries, see Plugin::route().
return [
	[
		'pattern' => 'kehai/events.json',
		'method'  => 'GET',
		'action'  => function () {
			return Plugin::route(App::instance());
		},
	],
];
