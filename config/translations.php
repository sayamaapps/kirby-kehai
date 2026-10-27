<?php

// The visitor-facing strings, as Kirby translations: `kehai.<key>`, the 1.x
// spelling, which 1.x sites already use. Same tables as I18n, same strings.
$translations = [];
foreach (Sayamaapps\Kehai\I18n::LANGUAGES as $language) {
	foreach (Sayamaapps\Kehai\I18n::table($language) as $key => $text) {
		$translations[$language]['kehai.' . $key] = $text;
	}
}

return $translations;
