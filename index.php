<?php

/**
 * Kehai (気配, the sense that someone is there — without knowing who):
 * real, recent purchases as social proof, from a data file on your own
 * server. Off by default: without `'kehai.active' => true` (or the
 * 1.x spelling `kehai.active`) the snippets print nothing and the route
 * answers 404; the bubble and the summary are switched on separately.
 *
 * This file only registers the plugin. The definitions live in config/, the
 * logic in src/. The page never talks to the source and never waits for it.
 * Defaults are passed at each option read (Options::get, Config), not in an
 * 'options' block: Kirby would file them under sayamaapps.kehai.*, which 1.x
 * does not read.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */

use Kirby\Filesystem\F;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

Kirby::plugin(
	name: 'sayamaapps/kehai',
	extends: [
		'translations' => require __DIR__ . '/config/translations.php',
		'snippets'     => require __DIR__ . '/config/snippets.php',
		'siteMethods'  => require __DIR__ . '/config/site-methods.php',
		'routes'       => require __DIR__ . '/config/routes.php',
	],
	root: __DIR__
);
