<?php

use Kehai\Display;
use Kehai\Events;
use Kehai\Fetch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The fetch window across a daylight-saving change: `kehai fetch` asks the
 * source for the last N × 24 hours, the same span the data file's coverage,
 * the summary and the bubble count. A calendar day (-1 days) is 23 or 25
 * hours on the change days, so a one-day summary would lose its purchase
 * for that day. Deterministic: every instant is given, no clock is read.
 *   php tests/FetchTest.php
 */
#[CoversClass(Fetch::class)]
final class FetchTest extends TestCase
{
	public function testFetchWindow(): void
	{


		// The process time zone is what made `-1 days` a wall-clock day in `kehai fetch`.
		$previousZone = date_default_timezone_get();
		date_default_timezone_set('Europe/Berlin');
		$berlin = new DateTimeZone('Europe/Berlin');
		$key = 'ds24-not-a-real-key-0000';
		$tmp = tempDir('fetch');
		$config = testConfig($tmp, ['maxAge' => 1, 'summaryDays' => 1, 'summaryMin' => 1]);

		// Spring: the day before the change is 23 hours long. Autumn: the change back is 25 hours.
		$cases = [
			'spring' => ['2026-03-29T12:00:00', $berlin],
			'autumn' => ['2026-10-25T12:00:00', $berlin],
			'ordinary' => ['2026-03-28T12:00:00', $berlin],
			'spring in UTC' => ['2026-03-29T10:00:00', new DateTimeZone('UTC')],
		];

		try {
			foreach ($cases as $name => [$text, $zone]) {
				$now = new DateTimeImmutable($text, $zone);
				// A fresh purchase, two hours old, in the account's Berlin time as Digistore24 sends it.
				$purchase = [
					'id' => 'SYNTHETIC-' . $name,
					'main_product_id' => '111111',
					'billing_status' => 'completed',
					'order_type' => 'live',
					'created_at' => $now->modify('-2 hours')->setTimezone($berlin)->format('Y-m-d H:i:s'),
				];
				$calls = [];
				$answer = ['status' => 200, 'body' => json_encode(['result' => 'success', 'data' => ['purchase_list' => [$purchase]]])];
				$collected = Fetch::collect(Fetch::source($config, $key, scripted([$answer], $calls)), $config, $now);

				expect(
					$now->getTimestamp() - 24 * 3600,
					Events::parse($collected['data']['from'] ?? null)->getTimestamp(),
					"$name: the fetch window is 24 hours, not a calendar day"
				);

				$file = $tmp . '/' . str_replace(' ', '-', $name) . '.json';
				Events::write($file, $collected['data']);
				$config['file'] = $file;
				$usable = Events::usable($config, $now);
				expect(1, Events::covered($usable['from'], $config, $now), "$name: the file covers one whole day");

				$summary = Display::summary($usable['events'], $config, $now, 'en', 'jahreskurs', null, null, $usable['from']);
				expect(1, $summary['count'] ?? null, "$name: the one-day summary counts the fresh purchase");
				expect(1, $summary['days'] ?? null, "$name: the summary says one day");
			}
		} finally {
			date_default_timezone_set($previousZone);
			removeTree($tmp);
		}
	}
}
