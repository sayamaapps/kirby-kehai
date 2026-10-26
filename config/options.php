<?php

// Options Kehai reads, relative to the plugin: `sayamaapps.kehai.<key>` (OPT-1).
// Each value is null on purpose. Options::get() gives the default at every
// read, and a default here would hide the 1.x spelling `kehai.<key>` (fallback).
return [
	'active'       => null,
	'bubble'       => null,
	'country'      => null,
	'css'          => null,
	'delay'        => null,
	'file'         => null,
	'gap'          => null,
	'groups'       => null,
	'language'     => null,
	'max'          => null,
	'maxAge'       => null,
	'maxStale'     => null,
	'position'     => null,
	'products'     => null,
	'pull'         => null,
	'pullInterval' => null,
	'source'       => null,
	'summary'      => null,
	'summaryDays'  => null,
	'summaryMin'   => null,
	'texts'        => null,
	'timezone'     => null,
	'duration'     => null,
];
