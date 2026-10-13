<?php

/**
 * Kehai's visitor-facing text, English — the default and the fallback for
 * missing keys. Same keys and placeholders in every language
 * (tests/I18nTest.php). A site changes single strings with `kehai.texts`.
 *
 * `bubble.*` is the sentence of the notice; the JavaScript adds " · " and
 * the time (`time.*`). `summary.*` is the line of the summary snippet.
 * `country.XX` is the country as it reads after "from" (hence "the
 * Netherlands"); a code without a text is shown without a country.
 */
return [
    'bubble.purchase'         => 'Someone booked “{product}”',
    'bubble.purchase.country' => 'Someone from {country} booked “{product}”',

    'summary.purchase.one'       => 'In the last {days} days, “{product}” was booked once',
    'summary.purchase.other'     => 'In the last {days} days, “{product}” was booked {count} times',
    'summary.purchase.day.one'   => 'In the last 24 hours, “{product}” was booked once',
    'summary.purchase.day.other' => 'In the last 24 hours, “{product}” was booked {count} times',

    'time.now'     => 'just now',
    'time.minute'  => '1 minute ago',
    'time.minutes' => '{count} minutes ago',
    'time.hour'    => '1 hour ago',
    'time.hours'   => '{count} hours ago',
    'time.day'     => '1 day ago',
    'time.days'    => '{count} days ago',

    'close' => 'Close',

    'country.AT' => 'Austria',
    'country.AU' => 'Australia',
    'country.BE' => 'Belgium',
    'country.BG' => 'Bulgaria',
    'country.CA' => 'Canada',
    'country.CH' => 'Switzerland',
    'country.CY' => 'Cyprus',
    'country.CZ' => 'Czechia',
    'country.DE' => 'Germany',
    'country.DK' => 'Denmark',
    'country.EE' => 'Estonia',
    'country.ES' => 'Spain',
    'country.FI' => 'Finland',
    'country.FR' => 'France',
    'country.GB' => 'the United Kingdom',
    'country.GR' => 'Greece',
    'country.HR' => 'Croatia',
    'country.HU' => 'Hungary',
    'country.IE' => 'Ireland',
    'country.IS' => 'Iceland',
    'country.IT' => 'Italy',
    'country.LI' => 'Liechtenstein',
    'country.LT' => 'Lithuania',
    'country.LU' => 'Luxembourg',
    'country.LV' => 'Latvia',
    'country.MT' => 'Malta',
    'country.NL' => 'the Netherlands',
    'country.NO' => 'Norway',
    'country.PL' => 'Poland',
    'country.PT' => 'Portugal',
    'country.RO' => 'Romania',
    'country.SE' => 'Sweden',
    'country.SI' => 'Slovenia',
    'country.SK' => 'Slovakia',
    'country.US' => 'the United States',
];
