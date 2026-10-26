<?php

// The visitor-facing strings, as Kirby translations. Both spellings are
// registered: `sayamaapps.kehai.<key>` and the 1.x `kehai.<key>`, which
// keeps working for sites that already use it.
$translations = [];
foreach (Sayamaapps\Kehai\I18n::LANGUAGES as $language) {
	foreach (Sayamaapps\Kehai\I18n::table($language) as $key => $text) {
		$translations[$language]['sayamaapps.kehai.' . $key] = $text;
		$translations[$language]['kehai.' . $key] = $text;
	}
}

return $translations;
