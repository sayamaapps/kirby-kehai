<?php

use Kirby\Cms\App;
use Kirby\Template\Snippet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kehai\Events;
use Sayamaapps\Kehai\Log;
use Sayamaapps\Kehai\Pull;

/**
 * The plugin with real Kirby, in a throwaway site: off by default, the
 * route (only anonymous fields, short cache, 404 when off), the bubble
 * snippet (own script and stylesheet, served by Kirby; no inline script,
 * no style attribute), the summary with its minimum, a stale file, the
 * language, and that in pull mode neither the page nor the route waits
 * for the source.
 *   php tests/KirbyTest.php
 */
#[CoversClass(Options::class)]
final class KirbyTest extends TestCase
{
	public function testWithKirby(): void
	{
		$base = dirname(__DIR__);
		$tmp = tempDir('kirby');
		$file = $tmp . '/storage/kehai/events.json';
		$products = [
			'111111' => ['key' => 'jahreskurs', 'label' => ['de' => 'Jahreskurs', 'en' => 'Annual course']],
			'222222' => ['key' => 'wochenendkurs', 'label' => 'Wochenendkurs'],
		];
		$on = ['kehai.active' => true, 'kehai.products' => $products, 'kehai.bubble' => true, 'kehai.summary' => true];
		// One reference time for the whole test: a minute boundary between the writes and the expectations must not move them.
		$reference = new DateTimeImmutable('now');
		$minute = fn (string $ago) => Events::minute($reference->modify($ago));
		/** Writes the data file as `kehai fetch` would — plus fields a tampered file might carry. */
		$writeFile = function (array $events, string $generated = '-10 minutes', string|null $from = '-14 days') use ($file): void {
			$utc = fn (string $time) => (new DateTimeImmutable($time))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
			@mkdir(dirname($file), 0700, true);
			file_put_contents($file, json_encode(['format' => 1, 'generated' => $utc($generated)]
				+ ($from === null ? [] : ['from' => $utc($from)]) + ['source' => 'digistore24', 'events' => $events]));
		};
		/** A new Kirby with these options. */
		$kirby = function (array $options) use ($tmp): App {
			Snippet::$cache = [];
			return new App([
				'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site'],
				'options' => ['url' => 'http://example.invalid'] + $options,
			]);
		};
		$route = function (array $options, string $query = '') use ($kirby): array {
			parse_str(ltrim($query, '?'), $_GET);
			$app = $kirby($options);
			[$response, $log] = logged(fn () => $app->render('kehai/events.json'));
			$_GET = [];
			return [$response, json_decode((string)$response?->body(), true), $log];
		};
		$page = function (array $options) use ($kirby): array {
			$app = $kirby($options);
			return logged(fn () => $app->page('home')->render());
		};
		$noInlineScript = function (string $html): bool {
			preg_match_all('~<script\b([^>]*)>(.*?)</script>~is', $html, $m, PREG_SET_ORDER);
			foreach ($m as $script) {
				if (!preg_match('~\ssrc="[^"]+"~', $script[1]) || trim($script[2]) !== '') {
					return false;
				}
			}
			return !preg_match('~\son[a-z]+\s*=~i', $html) && !preg_match('~\sstyle\s*=|<style\b~i', $html);
		};

		try {
			foreach (['public', 'content/home', 'site/plugins', 'site/templates'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			symlink($base, $tmp . '/site/plugins/kehai');
			file_put_contents($tmp . '/site/templates/home.php', '<!doctype html><html><body><main><?php snippet("kehai/summary", ["product" => "jahreskurs"]) ?><?php snippet("kehai/summary", ["product" => "wochenendkurs"]) ?></main><?php snippet("kehai/bubble") ?></body></html>');
			file_put_contents($tmp . '/content/home/home.txt', 'Title: Home');
			$writeFile([
				['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-2 hours'), 'country' => 'AT', 'name' => 'Annegret Beispielhuber', 'email' => 'a@example.org', 'id' => 'AB12CD34', 'amount' => '97.00'],
				['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-1 day'), 'country' => 'DE'],
				['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-3 days')],
				['type' => 'purchase', 'product' => 'wochenendkurs', 'at' => $minute('-4 days'), 'country' => 'CH'],
				['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-20 days')],
				['type' => 'purchase', 'product' => 'fremd', 'at' => $minute('-1 hour')],
			]);

			// ---- 1. Off by default ------------------------------------------------------------
			[$response] = $route([]);
			expect(404, $response->code(), 'without kehai.active the route is Kirby\'s 404');
			[$html] = $page(['kehai.products' => $products, 'kehai.bubble' => true, 'kehai.summary' => true]);
			holds(!str_contains($html, 'kehai'), 'without kehai.active the page has no trace of Kehai');
			[$response] = $route(['kehai.active' => true, 'kehai.products' => $products]);
			expect(404, $response->code(), 'kehai.bubble off: route 404');
			[$html] = $page(['kehai.active' => true, 'kehai.products' => $products]);
			holds(!str_contains($html, 'kehai'), 'bubble and summary off: nothing printed');
			[$response] = $route(['kehai.active' => 'yes', 'kehai.bubble' => true, 'kehai.products' => $products]);
			expect(404, $response->code(), 'only true switches on');
			holds($kirby([])->plugin('sayamaapps/kehai') !== null, 'plugin sayamaapps/kehai registered');
			expect('Schließen', $kirby([])->translation('de')->get('kehai.close'), 'strings registered as Kirby translations');

			// ---- 2. The route: only anonymous fields ---------------------------------------------
			[$response, $json] = $route($on);
			expect(200, $response->code(), 'route answers');
			expect('application/json', $response->type(), 'JSON');
			expect('public, max-age=60', $response->headers()['Cache-Control'] ?? null, 'short cache');
			expect('nosniff', $response->headers()['X-Content-Type-Options'] ?? null, 'nosniff');
			expect(['now', 'texts', 'events'], array_keys($json), 'route: exactly now, texts, events');
			expect(3, count($json['events']), 'route: kehai.max (3) entries');
			foreach ($json['events'] as $entry) {
				expect(['text', 'at'], array_keys($entry), 'route: an entry has only the sentence and the time');
			}
			expect([
				['text' => 'Someone booked “Annual course”', 'at' => $minute('-2 hours')],
				['text' => 'Someone booked “Annual course”', 'at' => $minute('-1 day')],
				['text' => 'Someone booked “Annual course”', 'at' => $minute('-3 days')],
			], $json['events'], 'route: newest real events, country off, unknown product ignored');
			$body = $response->body();
			foreach (['Annegret', 'a@example.org', 'AB12CD34', '97.00', 'fremd', '"AT"', 'country'] as $secret) {
				holds(!str_contains($body, $secret), "route never says \"$secret\"");
			}
			expect(['now', 'minute', 'minutes', 'hour', 'hours', 'day', 'days'], array_keys($json['texts']), 'time patterns for the script');
			holds((bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['now']), 'server time');

			[, $json] = $route($on + ['kehai.country' => true, 'kehai.max' => 5], '?lang=de');
			expect([
				'Jemand aus Österreich hat „Jahreskurs“ gebucht', 'Jemand aus Deutschland hat „Jahreskurs“ gebucht',
				'Jemand hat „Jahreskurs“ gebucht', 'Jemand aus der Schweiz hat „Wochenendkurs“ gebucht',
			], array_column($json['events'], 'text'), 'route: German, with country; the 20-day-old event never (maxAge 14)');
			expect('vor {count} Stunden', $json['texts']['hours'], 'German time patterns');
			[, $json] = $route($on, '?lang=xx&products=wochenendkurs,nope');
			expect(['Someone booked “Wochenendkurs”'], array_column($json['events'], 'text'), 'route: products filter; unknown language ignored');
			[, $json] = $route($on + ['kehai.texts' => ['en' => ['bubble.purchase' => 'Booked: {product}']]]);
			expect('Booked: Annual course', $json['events'][0]['text'], 'kehai.texts');

			// ---- 3. The bubble snippet ------------------------------------------------------------
			[$html] = $page($on + ['kehai.position' => 'right', 'kehai.delay' => 12]);
			holds(str_contains($html, '<div class="kehai kehai-right" data-kehai data-kehai-src="http://example.invalid/kehai/events.json?lang=en" data-kehai-delay="12" data-kehai-max="3" data-kehai-duration="6" data-kehai-gap="10">'), 'container with settings as data attributes: ' . $html);
			holds(str_contains($html, '<p class="kehai-text" role="status" aria-live="polite" aria-atomic="true"></p>'), 'polite live region, empty');
			holds(str_contains($html, '<button type="button" class="kehai-close" aria-label="Close" hidden>'), 'close button, hidden until there is something to close');
			holds((bool)preg_match('~<script src="http://example\.invalid/media/plugins/sayamaapps/kehai/[^"]+/kehai\.js" defer></script>~', $html), 'own script file, deferred');
			holds((bool)preg_match('~<link rel="stylesheet" href="http://example\.invalid/media/plugins/sayamaapps/kehai/[^"]+/kehai\.css">~', $html), 'own stylesheet');
			holds($noInlineScript($html), 'no inline script, no event handler attribute, no style attribute or element');
			holds(!str_contains($html, 'digistore'), 'the page never names the source');
			// The files are served by Kirby from the plugin's assets.
			preg_match('~<script src="http://example\.invalid/([^"]+)"~', $html, $m);
			$asset = $kirby($on)->render($m[1]);
			// Kirby 5 types .js as application/javascript, Kirby 6 as text/javascript (RFC 9239): both are JavaScript.
			expect(200, $asset->code(), 'kehai.js is served');
			holds(in_array($asset->type(), ['application/javascript', 'text/javascript'], true), 'kehai.js is served as JavaScript: ' . var_export($asset->type(), true));
			expect(file_get_contents($base . '/assets/kehai.js'), $asset->body(), 'kehai.js is the package file');
			preg_match('~<link rel="stylesheet" href="http://example\.invalid/([^"]+)"~', $html, $m);
			expect(file_get_contents($base . '/assets/kehai.css'), $kirby($on)->render($m[1])->body(), 'kehai.css is served');

			[$html] = $page($on + ['kehai.css' => false, 'kehai.language' => 'de']);
			holds(!str_contains($html, 'kehai.css') && str_contains($html, 'aria-label="Schließen"') && str_contains($html, '?lang=de'), 'kehai.css false: no stylesheet; German');
			$kirbyApp = $kirby($on);
			$only = (string)$kirbyApp->snippet('kehai/bubble', ['products' => ['wochenendkurs']], true);
			holds(str_contains($only, 'events.json?lang=en&amp;products=wochenendkurs'), 'snippet products → route filter');
			expect('', trim((string)$kirby($on)->snippet('kehai/bubble', ['products' => ['nope']], true)), 'no event for these products: nothing printed');

			// ---- 4. The summary --------------------------------------------------------------------
			[$html] = $page($on);
			holds(str_contains($html, '<p class="kehai-summary">In the last 7 days, “Annual course” was booked 3 times</p>'), 'summary for jahreskurs: three in seven days');
			holds(!str_contains($html, 'Wochenendkurs'), 'summary for wochenendkurs: one is below the minimum 3, nothing');
			$app = $kirby($on);
			$before = time();
			$app->page('home')->render();
			holds(($app->response()->expires() ?? PHP_INT_MAX) <= $before + 15 * 60 + 5, 'a page with Kehai content stays in Kirby\'s page cache for 15 minutes at most');
			[$html] = $page($on + ['kehai.summaryMin' => 1, 'locale' => 'de_DE.UTF-8']);
			holds(str_contains($html, 'In den letzten 7 Tagen wurde „Wochenendkurs“ einmal gebucht'), 'kehai.summaryMin 1; German via locale');
			$app = $kirby($on + ['kehai.groups' => ['kurse' => ['products' => ['jahreskurs', 'wochenendkurs'], 'label' => 'all courses']]]);
			expect(4, $app->site()->kehaiSummary('kurse')['count'], 'site method with a group');
			expect(null, $app->site()->kehaiSummary('jahreskurs', 7, 4), 'site method: own minimum');
			expect(null, $kirby(['kehai.active' => true, 'kehai.products' => $products])->site()->kehaiSummary('jahreskurs'), 'kehai.summary off: null');
			// KEH-002: `days` longer than what the fetch reads is shortened to what the file covers.
			$snippet = fn (array $options, array $data) => logged(fn () => trim((string)$kirby($options)->snippet('kehai/summary', $data, true)));
			[$html, $log] = $snippet($on, ['product' => 'jahreskurs', 'days' => 31]);
			holds($html === '<p class="kehai-summary">In the last 14 days, “Annual course” was booked 3 times</p>', 'summary snippet with days 31: "In the last 14 days", the count is the one of 14 days (' . $html . ')');
			holds(str_contains($log, 'the summary for "jahreskurs" asks for 31 days, but the data file covers 14'), 'summary snippet with days 31: the shortening is logged');
			$kept = file_get_contents($file);
			file_put_contents($file, json_encode(['from' => (new DateTimeImmutable('-30 days'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')] + json_decode($kept, true)));
			[$html, $log] = $snippet($on + ['kehai.summaryDays' => 30], ['product' => 'jahreskurs', 'days' => 30]);
			holds(str_contains($html, 'In the last 30 days, “Annual course” was booked 4 times') && $log === '', 'kehai.summaryDays 30 and a file that covers 30 days: days 30 is shown as asked, nothing logged (' . $html . $log . ')');
			file_put_contents($file, $kept);
			$writeFile([['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-2 hours')]], '-10 minutes', null);
			[$html] = $snippet($on + ['kehai.summaryMin' => 1], ['product' => 'jahreskurs', 'days' => 30]);
			holds(str_contains($html, 'In the last 14 days'), 'a 1.0.0 file (no "from") is assumed to cover the fetch window: 30 days become 14');
			$writeFile([['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-2 hours')]], '-10 minutes', '-2 hours');
			[$html, $log] = $snippet($on + ['kehai.summaryMin' => 1], ['product' => 'wochenendkurs']);
			holds($html === '' && str_contains($log, 'covers less than a day'), 'a file that covers less than a day: no summary, logged');

			// ---- 5. Stale or missing file: nothing anywhere, a log line --------------------------------
			$writeFile([['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-1 hour')]], '-49 hours');
			[$html, $log] = $page($on);
			holds(!str_contains($html, 'kehai'), 'stale file: no bubble, no summary');
			holds(str_contains($log, 'was last updated 49 hours ago'), 'stale file: logged');
			[, $json] = $route($on);
			expect([], $json['events'], 'stale file: the route has no entries');
			rename($file, $file . '.away');
			[$html, $log] = $page($on);
			holds(!str_contains($html, 'kehai') && str_contains($log, 'is missing'), 'missing file: nothing, logged');
			rename($file . '.away', $file);
			$writeFile([['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-1 hour')]]);

			// ---- 6. Pull mode: the page and the route never wait for the source ------------------------------
			$calls = [];
			$pullTransport = function (int $timeout) use (&$calls): Closure {
				return scripted([['status' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/digistore-purchases.json')]], $calls);
			};
			mkdir($tmp . '/site/config', 0700, true);
			file_put_contents($tmp . '/site/config/kehai.php', "<?php return ['key' => 'pull-key-0000'];");
			@unlink(Pull::marker($file));
			$pull = $on + ['kehai.pull' => true, 'kehai.products' => ['111111' => ['key' => 'jahreskurs']] + $products];
			[$html] = $page($pull);
			expect(0, count($calls), 'pull mode: rendering a page makes no request');
			[$response] = $route($pull);
			expect([200, 0], [$response->code(), count($calls)], 'pull mode: the route answers without a request');
			holds(Pull::pending(), 'pull mode: the route leaves a pull for after the answer');
			expect('written', Pull::flush($pullTransport), 'pull mode: the pull runs after the answer');
			expect(1, count($calls), 'pull mode: one request');
			holds(!str_contains(file_get_contents($file), 'Annegret'), 'pull mode: the file is anonymous');
			[$response] = $route($pull);
			holds(!Pull::pending(), 'pull mode: not due again right after');
			// Pull mode prints the bubble even with nothing to show: its script's call of the route is what restarts the fetch.
			$printed = fn (string $html) => str_contains($html, '<div class="kehai') && str_contains($html, 'data-kehai-src=') &&
				(bool)preg_match('~<script src="http://example\.invalid/[^"]*kehai\.js[^"]*" defer></script>~', $html);
			$kept = file_get_contents($file);
			$requests = count($calls);
			$emptyStates = [
				'file missing' => [fn () => unlink($file), fn () => file_put_contents($file, $kept)],
				'file stale' => [fn () => $writeFile([['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-1 hour')]], '-49 hours'), fn () => file_put_contents($file, $kept)],
			];
			foreach ($emptyStates as $state => [$set, $restore]) {
				$set();
				[$html] = $page($pull);
				holds($printed($html) && $noInlineScript($html), "pull mode, $state: the bubble's container and script are still printed");
				expect($requests, count($calls), "pull mode, $state: the page render still makes no request");
				$restore();
			}
			holds($printed((string)$kirby($pull)->snippet('kehai/bubble', ['products' => ['nope']], true)), 'pull mode, no event of these products: container and script are printed');
			expect('', trim((string)$kirby(array_replace($pull, ['kehai.active' => false]))->snippet('kehai/bubble', [], true)), 'pull mode, kehai.active off: nothing printed');
			[$html] = $page(array_replace($pull, ['kehai.bubble' => false, 'kehai.summary' => false]));
			holds(!str_contains($html, 'kehai'), 'pull mode, kehai.bubble off: no Kehai markup');
			expect(false, $kirby(array_replace($pull, ['kehai.bubble' => false]))->site()->kehaiBubble(), 'pull mode, kehai.bubble off: kehaiBubble() is false');
			expect('', trim((string)$kirby($on)->snippet('kehai/bubble', ['products' => ['nope']], true)), 'pull mode off, no event of these products: still nothing printed');
			unlink($tmp . '/site/config/kehai.php');
			@unlink(Pull::marker($file));
			[, , $log] = $route($pull);
			holds(!Pull::pending() && str_contains($log, 'kehai.pull is on, but site/config/kehai.php has no key'), 'pull mode without key: logged, nothing scheduled');
			expect('', trim((string)$kirby($pull)->snippet('kehai/bubble', ['products' => ['nope']], true)), 'pull mode without a key: nothing to show, nothing printed (the route could not fetch anyway)');

			// ---- 7. A site's own snippet wins ------------------------------------------------------------------
			$writeFile([['type' => 'purchase', 'product' => 'jahreskurs', 'at' => $minute('-1 hour')]]);
			mkdir($tmp . '/site/snippets/kehai', 0700, true);
			file_put_contents($tmp . '/site/snippets/kehai/summary.php', '<?php $s = site()->kehaiSummary($product, null, 1); ?>OWN <?= $s["count"] ?? "none" ?>');
			expect('OWN 1', trim((string)$kirby($on)->snippet('kehai/summary', ['product' => 'jahreskurs'], true)), 'site/snippets/kehai/summary.php overrides the package snippet');

		} finally {
			removeTree($tmp);
		}
	}
}
