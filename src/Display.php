<?php

namespace Kehai;

use DateTimeImmutable;

/**
 * What a page shows, from the usable events (Kirby-free). The texts come
 * from I18n; the caller sets the language and `kehai.texts` first.
 *
 * Nothing here adds, repeats or rounds up: the bubble gets the newest
 * real events, each once, not older than `maxAge` days; the summary counts
 * real events in its period and says nothing below `summaryMin`.
 */
final class Display
{
	/**
	 * Entries for the bubble, newest first, at most `max`.
	 *
	 * @param list<array> $events Events::usable()['events']
	 * @param list<string>|null $keys only these product keys; null = all configured
	 * @return list<array{text:string, at:string}>
	 */
	public static function bubble(array $events, array $config, DateTimeImmutable $now, string $language, array|null $keys = null): array
	{
		$oldest = $now->getTimestamp() - $config['maxAge'] * 86400;
		$entries = [];
		foreach ($events as $event) {
			if (($keys !== null && !in_array($event['product'], $keys, true)) || Events::time($event)->getTimestamp() < $oldest) {
				continue;
			}
			$entries[] = ['text' => self::sentence($event, $config, $language), 'at' => $event['at']];
			if (count($entries) >= $config['max']) {
				break;
			}
		}
		return $entries;
	}

	/** "Someone from Austria booked “Jahreskurs”" — the country only when switched on and named. */
	public static function sentence(array $event, array $config, string $language): string
	{
		$values = ['product' => Config::label($config, $event['product'], $language)];
		$code = $event['country'] ?? null;
		if ($config['country'] && is_string($code) && I18n::has('country.' . $code)) {
			return I18n::t('bubble.' . $event['type'] . '.country', $values + ['country' => I18n::t('country.' . $code)]);
		}
		return I18n::t('bubble.' . $event['type'], $values);
	}

	/**
	 * The summary for a product key or a group name, or null (= show
	 * nothing): unknown name, a data file that covers less than a day, or
	 * fewer events than the minimum.
	 *
	 * The period is cut to what the data file covers (`$from`, see
	 * Events::covered()): the summary never counts further back than the
	 * data goes, and says the shortened period. The reason is logged.
	 *
	 * @return array{count:int, days:int, product:string, text:string}|null
	 */
	public static function summary(array $events, array $config, DateTimeImmutable $now, string $language, string $name, int|null $days = null, int|null $min = null, DateTimeImmutable|null $from = null): array|null
	{
		$products = $config['groups'][$name]['products'] ?? (in_array($name, Config::keys($config), true) ? [$name] : null);
		if ($products === null) {
			Log::error('Kehai: the summary asks for "' . $name . '", which is neither a product key nor a group');
			return null;
		}
		$days = max(1, min(90, $days ?? $config['summaryDays']));
		$covered = Events::covered($from, $config, $now);
		if ($covered < 1) {
			Log::error('Kehai: the summary for "' . $name . '" shows nothing, the data file covers less than a day');
			return null;
		}
		if ($days > $covered) {
			Log::error('Kehai: the summary for "' . $name . '" asks for ' . $days . ' days, but the data file covers ' . $covered
				. ' (the fetch reads max(kehai.maxAge, kehai.summaryDays) days); showing ' . $covered);
			$days = $covered;
		}
		$min = max(1, $min ?? $config['summaryMin']);
		$oldest = $now->getTimestamp() - $days * 86400;
		$count = 0;
		foreach ($events as $event) {
			if ($event['type'] === 'purchase' && in_array($event['product'], $products, true) && Events::time($event)->getTimestamp() >= $oldest) {
				$count++;
			}
		}
		if ($count < $min) {
			return null;
		}
		$label = Config::label($config, $name, $language);
		$key = 'summary.purchase.' . ($days === 1 ? 'day.' : '') . ($count === 1 ? 'one' : 'other');
		return [
			'count'   => $count,
			'days'    => $days,
			'product' => $label,
			'text'    => I18n::t($key, ['count' => $count, 'days' => $days, 'product' => $label]),
		];
	}

	/** The `time.*` patterns for the script, keyed without the prefix. */
	public static function times(): array
	{
		$texts = [];
		foreach (['now', 'minute', 'minutes', 'hour', 'hours', 'day', 'days'] as $key) {
			$texts[$key] = I18n::t('time.' . $key);
		}
		return $texts;
	}
}
