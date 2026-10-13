<?php

use Kirby\Cms\App;
use Kirby\Http\Response;

require_once __DIR__ . '/src/autoload.php';

use Kehai\Config;
use Kehai\Display;
use Kehai\Events;
use Kehai\I18n;
use Kehai\Options;
use Kehai\Pull;

/**
 * Kehai (気配, the sense that someone is there — without knowing who):
 * real, recent purchases as social proof, from a data file on your own
 * server. Off by default: without `'kehai.active' => true` the snippets
 * print nothing and the route answers 404; the bubble and the summary are
 * switched on separately (`kehai.bubble`, `kehai.summary`).
 *
 * The page never talks to the source and never waits for it. The data file
 * is written by `kehai fetch` (a workflow, with the key as a secret) or, in
 * pull mode, after the answer has been sent (src/Pull.php).
 *
 * Defaults are passed as fallback values at each option call (Config), NOT
 * in an 'options' block (Kirby would file them under sayamaapps.kehai.*).
 */

/** Reads `kehai.<key>` as configured (unchecked). For site snippets with their own markup. */
function kehai_option(string $key, mixed $default = null): mixed
{
    return Options::get(App::instance(), $key, $default);
}

/** The checked configuration (Config::normalize()). */
function kehai_config(): array
{
    return Options::config(App::instance())['config'];
}

/** Language and `kehai.texts` for this request. Snippets call it before printing text. */
function kehai_i18n(?string $language = null): string
{
    $kirby = App::instance();
    I18n::setLanguage($language ?? Options::language($kirby));
    I18n::setOverrides(Options::get($kirby, 'texts'));
    return I18n::language();
}

/** Address of a file in assets/ (Kirby serves plugin assets from /media/plugins/…, with a version hash). */
function kehai_asset_url(string $file): ?string
{
    return App::instance()->plugin('sayamaapps/kehai')?->asset($file)?->url();
}

/** In pull mode: remember a fetch for after the answer, if one is due. */
function kehai_pull_later(App $kirby, array $config): void
{
    if (!$config['active'] || !$config['pull'] || !Pull::due($config, time())) {
        return;
    }
    $key = Options::key(Options::secretsFile($kirby));
    if ($key === null) {
        Kehai\Log::error('Kehai: kehai.pull is on, but site/config/' . Options::SECRETS . ' has no key');
        return;
    }
    Pull::later($config, $key);
}

/**
 * A page that shows Kehai content may stay in Kirby's page cache for
 * 15 minutes at most: a cached page must not keep showing a count after
 * the data has changed or gone stale.
 */
function kehai_cache_limit(App $kirby): void
{
    $kirby->response()->expires(15);
}

/** Product keys from the route's `products` parameter (comma separated), or null for all. */
function kehai_keys(mixed $value, array $config): ?array
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    return array_values(array_intersect(Config::keys($config), array_map('trim', explode(',', $value))));
}

/**
 * GET /kehai/events.json — the bubble's entries: sentence and time, no
 * more. Short cache (60 s): the file changes at most every few minutes,
 * and the script computes the age of each entry itself.
 */
function kehai_route(): Response|false
{
    $kirby = App::instance();
    $config = Options::config($kirby)['config'];
    if (!$config['active'] || !$config['bubble']) {
        return false; // Kirby's 404: the module does not reveal itself
    }
    kehai_pull_later($kirby, $config);

    $language = get('lang');
    $language = kehai_i18n(is_string($language) && in_array($language, I18n::LANGUAGES, true) ? $language : null);
    $now = new DateTimeImmutable('now');
    $events = Display::bubble(Events::usable($config, $now), $config, $now, $language, kehai_keys(get('products'), $config));
    $body = [
        'now'    => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'texts'  => Display::times(),
        'events' => $events,
    ];
    return new Response(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json', 200, [
        'Cache-Control'          => 'public, max-age=60',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag'           => 'noindex',
    ]);
}

$kehaiTranslations = [];
foreach (I18n::LANGUAGES as $kehaiLanguage) {
    foreach (I18n::table($kehaiLanguage) as $kehaiKey => $kehaiText) {
        $kehaiTranslations[$kehaiLanguage]['kehai.' . $kehaiKey] = $kehaiText;
    }
}
unset($kehaiLanguage, $kehaiKey, $kehaiText);

App::plugin('sayamaapps/kehai', [
    // The visitor-facing strings, also usable as t('kehai.<key>').
    'translations' => $kehaiTranslations,

    /**
     * Plain defaults. A site with its own markup creates
     * site/snippets/kehai/bubble.php or summary.php — Kirby looks there first.
     */
    'snippets' => [
        'kehai/bubble'  => __DIR__ . '/snippets/bubble.php',
        'kehai/summary' => __DIR__ . '/snippets/summary.php',
    ],

    'siteMethods' => [
        /**
         * The summary for a product key or group, or null (= show nothing:
         * switched off, file missing or too old, fewer than the minimum).
         *
         *   $site->kehaiSummary('jahreskurs') → ['count' => 12, 'days' => 7, 'product' => 'Jahreskurs', 'text' => '…']
         */
        'kehaiSummary' => function (string $name, ?int $days = null, ?int $min = null): ?array {
            $kirby = $this->kirby();
            $config = Options::config($kirby)['config'];
            if (!$config['active'] || !$config['summary']) {
                return null;
            }
            if (Pull::canFinishEarly()) {
                kehai_pull_later($kirby, $config);
            }
            kehai_cache_limit($kirby);
            $language = kehai_i18n();
            $now = new DateTimeImmutable('now');
            return Display::summary(Events::usable($config, $now), $config, $now, $language, $name, $days, $min);
        },

        /** Whether the bubble has something to show right now (the snippet asks before printing anything). */
        'kehaiBubble' => function (?array $products = null): bool {
            $kirby = $this->kirby();
            $config = Options::config($kirby)['config'];
            if (!$config['active'] || !$config['bubble']) {
                return false;
            }
            if (Pull::canFinishEarly()) {
                kehai_pull_later($kirby, $config);
            }
            kehai_cache_limit($kirby);
            $now = new DateTimeImmutable('now');
            $keys = $products === null ? null : array_values(array_intersect(Config::keys($config), $products));
            return Display::bubble(Events::usable($config, $now), $config, $now, I18n::language(), $keys) !== [];
        },
    ],

    'routes' => [
        [
            'pattern' => 'kehai/events.json',
            'method'  => 'GET',
            'action'  => fn () => kehai_route(),
        ],
    ],
]);
