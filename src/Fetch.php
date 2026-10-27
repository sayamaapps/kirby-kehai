<?php

namespace Sayamaapps\Kehai;

use Closure;
use DateTimeImmutable;
use Kirby\Exception\Exception;

/**
 * `kehai fetch`: read the source, write the anonymous data file. Also the
 * core of pull mode (collect()).
 *
 * Exit codes:
 *   0  file written
 *   1  error (no products configured, source or write failure); the old file stays
 *   3  no API key — "skipped", a workflow turns this into a notice
 *
 * Every run replaces the file completely, also when there is nothing to
 * show: an empty list is the truth, an old list would not be. The key is
 * never printed; nothing a buyer entered is printed either.
 *
 * @package   Kirby Kehai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Fetch
{
	public const ENV_FILE = 'KEHAI_FILE';
	public const ENV_KEY = 'DIGISTORE24_API_KEY';
	public const EXIT_ERROR = 1;
	public const EXIT_NO_KEY = 3;
	public const EXIT_OK = 0;

	/**
	 * Fetches and builds the data file content.
	 *
	 * @return array{data: array, notes: list<string>}
	 * @throws SourceException
	 */
	public static function collect(Source $source, array $config, DateTimeImmutable $now): array
	{
		// Whole days of 86400 seconds, as Events::covered() and Display count them.
		// A calendar day is 23 or 25 hours on a DST change and would cover one day less.
		$from = $now->setTimestamp($now->getTimestamp() - Config::window($config) * 86400);
		$result = $source->events($from, $now);
		$data = Events::build($result['events'], $now, $source->id(), $config['country'], Config::keys($config), $result['from']);
		$counts = array_count_values(array_column($data['events'], 'product'));
		ksort($counts);
		$notes = $result['notes'];
		// The source's cut or the MAX_EVENTS cut: the file covers less than was asked for.
		$covers = Events::parse($data['from'] ?? null);
		if ($covers !== null && $covers > $from) {
			$notes[] = 'window: ' . Config::window($config) . ' days asked, ' . Events::covered($covers, $config, $now) . ' days covered';
		}
		foreach ($counts as $product => $count) {
			$notes[] = "  $product: $count";
		}
		return ['data' => $data, 'notes' => $notes];
	}

	/**
	 * @param $config Config::normalize()['config']
	 * @param $key API key (environment, else site/config/kehai.php)
	 * @param $file target from --file / KEHAI_FILE (relative to $cwd), null = sayamaapps.kehai.file
	 * @param $transport null = Http::transport()
	 * @param resource $out
	 * @param resource $err
	 */
	public static function run(array $config, string|null $key, string|null $file, Closure|null $transport, string $cwd, $out, $err, DateTimeImmutable|null $now = null): int
	{
		if ($key === null || trim($key) === '') {
			fwrite($out, 'No ' . self::ENV_KEY . " set — nothing to do.\n"
				. "Store the key as a repository secret (or in site/config/kehai.php for a local run).\n");
			return self::EXIT_NO_KEY;
		}
		if ($config['products'] === []) {
			fwrite($err, "sayamaapps.kehai.products is empty — nothing would be counted. Add the Digistore24 product ids to the configuration.\n");
			return self::EXIT_ERROR;
		}
		$target = $file !== null && trim($file) !== '' ? Config::resolve($file, $cwd) : $config['file'];
		$now ??= new DateTimeImmutable('now');

		try {
			$result = self::collect(self::source($config, $key, $transport ?? Http::transport()), $config, $now);
			Events::write($target, $result['data']);
		} catch (SourceException | Exception $e) {
			fwrite($err, Text::plain(str_replace($key, '[key]', $e->getMessage()), 300) . "\n");
			return self::EXIT_ERROR;
		}
		foreach ($result['notes'] as $note) {
			fwrite($out, (str_starts_with($note, '  ') ? '  ' : '') . Text::plain($note, 300) . "\n");
		}
		fwrite($out, 'Written: ' . Text::plain($target, 300) . ' (' . count($result['data']['events']) . " events)\n");
		return self::EXIT_OK;
	}

	/** The adapter for `sayamaapps.kehai.source`. */
	public static function source(array $config, string $key, Closure $transport): Source
	{
		return match ($config['source']) {
			'digistore24' => new Digistore24($transport, $key, Config::allowList($config), $config['country'], $config['timezone']),
		};
	}
}
