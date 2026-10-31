<?php

// Options Kehai reads, relative to the plugin: `sayamaapps.kehai.<key>`.
// Each value is null on purpose. Options::get() gives the default at every read,
// and a default here would hide the 1.x spelling `kehai.<key>` (fallback).
return [
	'active'        => null,
	'file'          => null,
	'source'        => null,
	'products'      => null,
	'groups'        => null,
	'country'       => null,
	'timezone'      => null,
	'maxStale'      => null,
	'bubble'        => null,
	'position'      => null,
	'delay'         => null,
	'max'           => null,
	'duration'      => null,
	'gap'           => null,
	'maxAge'        => null,
	'css'           => null,
	'summary'       => null,
	'summaryDays'   => null,
	'summaryMin'    => null,
	'pull'          => null,
	'pullInterval'  => null,
	'language'      => null,
	'texts'         => null,
];
