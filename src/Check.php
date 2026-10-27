<?php

namespace Sayamaapps\Kehai;

use Closure;
use DateTimeImmutable;

/**
 * `kehai check`: configuration, data file, events per product, notes.
 *
 * Exit codes:
 *   0  nothing wrong (notes may still say why nothing is shown, e.g. switched off)
 *   1  a problem: the file is missing, too old or unreadable, or the configuration has errors
 *
 * Every value that comes from the configuration or the data file passes
 * Text::plain() before it is printed: the output never carries control
 * characters, escape sequences or bidi overrides, whatever the file says.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Check
{
	public const EXIT_OK = 0;
	public const EXIT_PROBLEM = 1;

	private static function age(int $seconds): string
	{
		$seconds = max(0, $seconds);
		return $seconds < 3600 ? intdiv($seconds, 60) . ' minutes' : (intdiv($seconds, 3600) . ' hours');
	}

	/**
	 * Notes and problems that follow from the configuration alone.
	 *
	 * @param list<string> $problems
	 * @param list<string> $notes
	 */
	private static function checkConfiguration(array $config, bool $keyFile, array &$problems, array &$notes): void
	{
		self::switchNotes($config, $notes);
		if ($config['products'] === []) {
			$problems[] = 'kehai.products is empty: no purchase is counted.';
		}
		if ($config['pull'] && !$keyFile) {
			$problems[] = 'kehai.pull is on, but site/config/' . Options::SECRETS . ' has no key.';
		}
		if (!$config['pull'] && $keyFile) {
			$notes[] = 'site/config/' . Options::SECRETS . ' holds a key although pull mode is off. With the workflow, the key does not belong on the web server.';
		}
	}

	/** Events of one product key between now and the future tolerance, within the last $days days. */
	private static function countWithin(array $events, string $key, int $days, DateTimeImmutable $now, int $limit): int
	{
		$oldest = $now->getTimestamp() - $days * 86400;
		return count(array_filter($events, fn (array $e) => $e['product'] === $key &&
			Events::time($e)->getTimestamp() >= $oldest && Events::time($e)->getTimestamp() <= $limit));
	}

	/** Prints the configuration as it is read, one line per setting. */
	private static function describeConfiguration(Closure $say, array $config, bool $keyFile): void
	{
		$say('Kehai check');
		$say('');
		$say('Configuration');
		$say('active: ' . self::onOff($config['active']), 1);
		$say('source: ' . $config['source'] . ', time zone ' . $config['timezone'], 1);
		$say('products: ' . ($config['products'] === [] ? 'none' : count($config['products'])), 1);
		foreach ($config['products'] as $id => $product) {
			$say($id . ' → ' . $product['key'] . ' ("' . implode('" / "', $product['label']) . '")', 2);
		}
		foreach ($config['groups'] as $name => $group) {
			$say('group ' . $name . ': ' . ($group['products'] === [] ? '(empty)' : implode(', ', $group['products'])), 2);
		}
		$say('country: ' . self::onOff($config['country']), 1);
		$say('bubble: ' . self::onOff($config['bubble']) . ($config['bubble']
			? " ({$config['position']}, after {$config['delay']} s, at most {$config['max']}, events up to {$config['maxAge']} days old)" : ''), 1);
		$say('summary: ' . self::onOff($config['summary']) . ($config['summary']
			? " (last {$config['summaryDays']} days, at least {$config['summaryMin']}; the fetch reads " . Config::window($config) . ' days)' : ''), 1);
		$say('pull: ' . self::onOff($config['pull']) . ($config['pull'] ? " (every {$config['pullInterval']} minutes)" : '')
			. ', key in site/config/' . Options::SECRETS . ': ' . ($keyFile ? 'yes' : 'no'), 1);
	}

	/**
	 * The data file: its state, what it covers, and whether it is too old.
	 *
	 * @param list<string> $problems
	 * @param list<string> $notes
	 * @return array{read: array, covered: int} The read result and the days the file covers
	 */
	private static function describeDataFile(Closure $say, array $config, DateTimeImmutable $now, array &$problems, array &$notes): array
	{
		$say('');
		$say('Data file');
		$say($config['file'], 1);
		$read = Events::read($config['file'], true, null);
		if ($read['error'] !== null) {
			$say($read['error'], 1);
			$reason = $read['error'] === 'missing' ? 'missing' : 'unusable (' . $read['error'] . ')';
			$problems[] = 'The data file is ' . $reason . ': nothing is shown. Run `kehai fetch` or check the upload.';
			return ['read' => $read, 'covered' => Config::window($config)];
		}

		/** @var DateTimeImmutable $generated set whenever the read succeeded */
		$generated = $read['generated'];
		$age = $now->getTimestamp() - $generated->getTimestamp();
		$say('generated ' . $generated->format('Y-m-d H:i') . ' UTC (' . self::age($age) . ' ago), source ' . ($read['source'] ?? '?') . ', format ' . Events::FORMAT, 1);
		$covered = Events::covered($read['from'], $config, $now);
		$say($read['from'] === null
			? 'covers: not recorded (written by 1.0.0; the fetch window of this configuration is assumed)'
			: 'covers ' . $covered . ' days (from ' . $read['from']->format('Y-m-d H:i') . ' UTC)', 1);
		$say(count($read['events']) . ' events' . ($read['dropped'] > 0 ? ', ' . $read['dropped'] . ' invalid entries ignored' : ''), 1);
		self::fileNotes($read, $config, $covered, $notes);
		if ($age > $config['maxStale'] * 3600) {
			$problems[] = 'The data file is older than kehai.maxStale (' . $config['maxStale'] . ' hours): nothing is shown until the next successful fetch.';
		} elseif ($age < -Events::FUTURE_TOLERANCE) {
			$problems[] = 'The data file claims to be generated in the future; check the clocks of the server and of the machine that runs the fetch.';
		}
		return ['read' => $read, 'covered' => $covered];
	}

	/**
	 * Events per product and per group, and the notes about them.
	 *
	 * @param array{read: array, covered: int} $data
	 * @param list<string> $notes
	 */
	private static function describeEvents(Closure $say, array $config, DateTimeImmutable $now, array $data, array &$notes): void
	{
		$read = $data['read'];
		$events = $read['error'] === null ? $read['events'] : [];
		$keys = Config::keys($config);
		$limit = $now->getTimestamp() + Events::FUTURE_TOLERANCE;
		$say('');
		$say('Events per product');
		$future = count(array_filter($events, fn (array $e) => Events::time($e)->getTimestamp() > $limit));
		if ($future > 0) {
			$notes[] = "$future events lie in the future and are never shown.";
		}
		$summaryDays = min($config['summaryDays'], $data['covered']); // the page shortens the summary to what the file covers
		$bubbleTotal = self::perProductLines($say, $config, $events, $keys, $summaryDays, $now, $limit);
		foreach ($config['groups'] as $name => $group) {
			$summary = array_sum(array_map(fn (string $key) => self::countWithin($events, $key, max(1, $summaryDays), $now, $limit), $group['products']));
			$say("group $name: " . self::summaryLine($summary, $summaryDays, $config['summaryMin']), 1);
		}
		self::unknownNotes($events, $keys, $notes);
		if ($keys === []) {
			$say('(no products configured)', 1);
		}
		if ($config['active'] && $config['bubble'] && $read['error'] === null && $bubbleTotal === 0) {
			$notes[] = "No event in the last {$config['maxAge']} days: the bubble stays hidden.";
		}
	}

	/** The result: problems first, then notes. */
	private static function describeResult(Closure $say, array $problems, array $notes): void
	{
		$say('');
		$say($problems === [] ? 'No problems.' : 'Problems');
		foreach ($problems as $problem) {
			$say('- ' . $problem, 1);
		}
		if ($notes !== []) {
			$say('');
			$say('Notes');
			foreach ($notes as $note) {
				$say('- ' . $note, 1);
			}
		}
	}

	/**
	 * Notes about invalid entries and about a file that covers less than the summaries ask for.
	 *
	 * @param list<string> $notes
	 */
	private static function fileNotes(array $read, array $config, int $covered, array &$notes): void
	{
		if ($read['dropped'] > 0) {
			$notes[] = $read['dropped'] . ' entries in the data file are not valid events and are ignored.';
		}
		if ($read['from'] !== null && $covered < $config['summaryDays']) {
			$notes[] = $covered < 1
				? "The data file covers less than a day: summaries show nothing (the source's answer was cut; see kehai fetch)."
				: "The data file covers only $covered days: summaries are shortened to $covered days (the source's answer was cut; see kehai fetch).";
		}
	}

	private static function onOff(bool $on): string
	{
		return $on ? 'on' : 'off';
	}

	/**
	 * One line per product. Returns the number of events the bubble would show.
	 *
	 * @param list<string> $keys
	 */
	private static function perProductLines(Closure $say, array $config, array $events, array $keys, int $summaryDays, DateTimeImmutable $now, int $limit): int
	{
		$bubbleTotal = 0;
		foreach ($keys as $key) {
			$bubble = self::countWithin($events, $key, $config['maxAge'], $now, $limit);
			$summary = self::countWithin($events, $key, max(1, $summaryDays), $now, $limit);
			$bubbleTotal += $bubble;
			$say("$key: $bubble in the last {$config['maxAge']} days (bubble), " . self::summaryLine($summary, $summaryDays, $config['summaryMin']), 1);
		}
		return $bubbleTotal;
	}

	/**
	 * @param $config Config::normalize()['config']
	 * @param list<string> $problems Config::normalize()['problems']
	 * @param $keyFile whether site/config/kehai.php has a key
	 * @param resource $out
	 */
	public static function run(array $config, array $problems, bool $keyFile, $out, DateTimeImmutable|null $now = null): int
	{
		$now ??= new DateTimeImmutable('now');
		$say = self::writer($out);
		$notes = [];

		self::describeConfiguration($say, $config, $keyFile);
		self::checkConfiguration($config, $keyFile, $problems, $notes);
		$data = self::describeDataFile($say, $config, $now, $problems, $notes);
		self::describeEvents($say, $config, $now, $data, $notes);
		self::describeResult($say, $problems, $notes);
		return $problems === [] ? self::EXIT_OK : self::EXIT_PROBLEM;
	}

	/** "3 in the last 7 days (summary, shown)" — or that the summary shows nothing, since the file covers less than a day. */
	private static function summaryLine(int $count, int $days, int $min): string
	{
		if ($days < 1) {
			return 'summary not shown (the data file covers less than a day)';
		}
		return "$count in the last $days days (summary" . ($count >= $min ? ', shown' : ", below $min, not shown") . ')';
	}

	/**
	 * Notes about what is switched off, and what pull mode needs on this server.
	 *
	 * @param list<string> $notes
	 */
	private static function switchNotes(array $config, array &$notes): void
	{
		if (!$config['active']) {
			$notes[] = 'kehai.active is off: the site shows nothing and the route answers 404.';
		} elseif (!$config['bubble'] && !$config['summary']) {
			$notes[] = 'kehai.bubble and kehai.summary are both off: nothing is shown.';
		}
		if ($config['active'] && $config['pull'] && !$config['bubble']) {
			$notes[] = 'kehai.pull is on and kehai.bubble is off: only a page render on PHP-FPM or LiteSpeed '
				. 'starts the fetch (the bubble\'s script would call the route elsewhere). On another server use the workflow.';
		}
	}

	/**
	 * A note for each product key in the data file that the configuration does not know.
	 *
	 * @param list<array> $events
	 * @param list<string> $keys
	 * @param list<string> $notes
	 */
	private static function unknownNotes(array $events, array $keys, array &$notes): void
	{
		$unknown = array_count_values(array_column(array_filter($events, fn (array $e) => !in_array($e['product'], $keys, true)), 'product'));
		ksort($unknown);
		foreach ($unknown as $key => $count) {
			$notes[] = "$count events for the product key \"$key\", which is not in kehai.products, are ignored.";
		}
	}

	/** Writes one line with an indent of two spaces per level. The text passes Text::plain(). */
	private static function writer(mixed $out): Closure
	{
		return function (string $text, int $indent = 0) use ($out): void {
			fwrite($out, str_repeat('  ', $indent) . Text::plain($text, 300) . "\n");
		};
	}
}
