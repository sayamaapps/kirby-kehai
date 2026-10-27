<?php

namespace Sayamaapps\Kehai;

use DateTimeZone;

/**
 * The `kehai.*` options as one checked array (Kirby-free).
 *
 * Options::config() collects the raw values from Kirby; the CLI does the
 * same after booting the site. normalize() applies the defaults, clamps
 * numbers into sensible ranges and drops what it cannot use — every drop
 * is listed in `problems` (logged by the site, printed by `kehai check`).
 *
 * Every display is off by default: `active`, `bubble` and `summary` must
 * each be exactly `true`.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Config
{
	/** Default data file, relative to the project root. */
	public const DEFAULT_FILE = 'storage/kehai/events.json';

	/** Product ids at the source (Digistore24: digits). */
	public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}\z/';

	/** Product keys and group names: lower case, digits, `-` and `_`. */
	public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,49}\z/';

	/** Option names read from Kirby, all as `kehai.<name>`. */
	public const KEYS = [
		'active', 'file', 'source', 'products', 'groups', 'country', 'timezone', 'maxStale',
		'bubble', 'position', 'delay', 'max', 'duration', 'gap', 'maxAge', 'css',
		'summary', 'summaryDays', 'summaryMin',
		'pull', 'pullInterval', 'language', 'texts',
	];
	public const SOURCES = ['digistore24'];

	/**
	 * Source product id => product key, the allow list for the fetch.
	 *
	 * @return array<string,string>
	 */
	public static function allowList(array $config): array
	{
		return array_map(fn (array $product) => $product['key'], $config['products']);
	}

	/** All defaults, for tests and the CLI without a site. */
	public static function defaults(string $base = '/'): array
	{
		return self::normalize([], $base)['config'];
	}

	/**
	 * `groups`: name => ['products' => [keys], 'label' => …] for the
	 * summary. Unknown product keys are dropped and reported.
	 *
	 * @return array{0: array<string, array{products:list<string>, label:array<string,string>}>, 1: list<string>}
	 */
	private static function groups(mixed $raw, array $products): array
	{
		if (!is_array($raw)) {
			return [[], ['kehai.groups must be an array of name => [products, label]']];
		}
		$known = array_column($products, 'key');
		$groups = [];
		$problems = [];
		foreach ($raw as $name => $entry) {
			$name = (string)$name;
			$shown = Text::plain($name, 40);
			if (!preg_match(self::KEY_PATTERN, $name) || !is_array($entry) || !is_array($entry['products'] ?? null)) {
				$problems[] = "kehai.groups: ignoring \"$shown\" (needs a name of a-z, 0-9, - and _ and a list of products)";
				continue;
			}
			if (in_array($name, $known, true)) {
				$problems[] = "kehai.groups: ignoring \"$shown\" (a product has the same key)";
				continue;
			}
			$members = [];
			foreach ($entry['products'] as $key) {
				if (is_string($key) && in_array($key, $known, true)) {
					$members[] = $key;
				} else {
					$problems[] = "kehai.groups.$shown: ignoring the unknown product key \"" . Text::plain(is_scalar($key) ? (string)$key : gettype($key), 40) . '"';
				}
			}
			$groups[$name] = ['products' => array_values(array_unique($members)), 'label' => self::labels($entry['label'] ?? null, $name)];
		}
		return [$groups, $problems];
	}

	/** @return list<string> every configured product key, once */
	public static function keys(array $config): array
	{
		return array_values(array_unique(array_column($config['products'], 'key')));
	}

	/** The label of a product key in a language: that language, English, any, the key. */
	public static function label(array $config, string $key, string $language): string
	{
		foreach ($config['products'] as $product) {
			if ($product['key'] === $key) {
				$labels = $product['label'];
				return $labels[$language] ?? $labels['en'] ?? (reset($labels) ?: $key);
			}
		}
		$labels = $config['groups'][$key]['label'] ?? [];
		return $labels[$language] ?? $labels['en'] ?? (reset($labels) ?: $key);
	}

	/** @return array<string,string> language => label (at least one) */
	private static function labels(mixed $label, string $key): array
	{
		if (is_string($label) && trim($label) !== '') {
			return ['en' => trim($label)];
		}
		$labels = [];
		foreach (is_array($label) ? $label : [] as $language => $text) {
			if (is_string($language) && is_string($text) && trim($text) !== '') {
				$labels[strtolower($language)] = trim($text);
			}
		}
		return $labels ?: ['en' => $key];
	}

	/**
	 * @param array<string,mixed> $raw option name => value (null = not set)
	 * @param $base project root, for a relative `file`
	 * @return array{config: array<string,mixed>, problems: list<string>}
	 */
	public static function normalize(array $raw, string $base): array
	{
		$problems = [];
		$int = function (string $name, int $default, int $min, int $max) use ($raw, &$problems): int {
			$value = $raw[$name] ?? null;
			if ($value === null) {
				return $default;
			}
			if (!is_int($value) && !(is_string($value) && preg_match('/^\d+\z/', $value))) {
				$problems[] = "kehai.$name is not a whole number; using $default";
				return $default;
			}
			return max($min, min($max, (int)$value));
		};

		$source = $raw['source'] ?? 'digistore24';
		if (!in_array($source, self::SOURCES, true)) {
			$problems[] = 'kehai.source ' . Text::plain(var_export($source, true), 40) . ' is not supported; using digistore24';
			$source = 'digistore24';
		}

		$timezone = $raw['timezone'] ?? 'Europe/Berlin';
		try {
			/** @var non-empty-string $name a string that is not empty; empty strings throw below */
			$name = is_string($timezone) ? $timezone : '';
			$timezone = (new DateTimeZone($name))->getName();
		} catch (\Throwable) {
			$problems[] = 'kehai.timezone is not a valid time zone; using Europe/Berlin';
			$timezone = 'Europe/Berlin';
		}

		$position = $raw['position'] ?? 'left';
		if (!in_array($position, ['left', 'right'], true)) {
			$problems[] = 'kehai.position must be left or right; using left';
			$position = 'left';
		}

		[$products, $productProblems] = self::products($raw['products'] ?? []);
		[$groups, $groupProblems] = self::groups($raw['groups'] ?? [], $products);
		array_push($problems, ...$productProblems, ...$groupProblems);

		$file = $raw['file'] ?? null;
		$file = is_string($file) && trim($file) !== '' ? trim($file) : self::DEFAULT_FILE;

		$config = [
			'active'       => ($raw['active'] ?? false) === true,
			'file'         => self::resolve($file, $base),
			'source'       => $source,
			'products'     => $products,
			'groups'       => $groups,
			'country'      => ($raw['country'] ?? false) === true,
			'timezone'     => $timezone,
			'maxStale'     => $int('maxStale', 48, 1, 24 * 60),
			'bubble'       => ($raw['bubble'] ?? false) === true,
			'position'     => $position,
			'delay'        => $int('delay', 8, 0, 600),
			'max'          => $int('max', 3, 1, 20),
			'duration'     => $int('duration', 6, 2, 60),
			'gap'          => $int('gap', 10, 0, 600),
			'maxAge'       => $int('maxAge', 14, 1, 90),
			'css'          => ($raw['css'] ?? true) !== false,
			'summary'      => ($raw['summary'] ?? false) === true,
			'summaryDays'  => $int('summaryDays', 7, 1, 90),
			'summaryMin'   => $int('summaryMin', 3, 1, 1000),
			'pull'         => ($raw['pull'] ?? false) === true,
			'pullInterval' => $int('pullInterval', 15, 5, 24 * 60),
			'language'     => is_string($raw['language'] ?? null) ? strtolower($raw['language']) : null,
			'texts'        => is_array($raw['texts'] ?? null) ? $raw['texts'] : [],
		];
		return ['config' => $config, 'problems' => $problems];
	}

	/**
	 * `products`: source product id => ['key' => …, 'label' => string or
	 * [language => string]]. Several ids may share a key (one offer in
	 * several payment plans).
	 *
	 * @return array{0: array<string, array{key:string, label:array<string,string>}>, 1: list<string>}
	 */
	private static function products(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [[], ['kehai.products must be an array of product id => [key, label]']];
		}
		$products = [];
		$problems = [];
		foreach ($raw as $id => $entry) {
			$id = (string)$id;
			$shown = Text::plain($id, 40);
			if (!preg_match(self::ID_PATTERN, $id)) {
				$problems[] = "kehai.products: ignoring the entry \"$shown\" (not a product id)";
				continue;
			}
			$key = is_array($entry) ? ($entry['key'] ?? null) : null;
			if (!is_string($key) || !preg_match(self::KEY_PATTERN, $key)) {
				$problems[] = "kehai.products: ignoring product $shown (key missing or not a-z, 0-9, - and _)";
				continue;
			}
			$products[$id] = ['key' => $key, 'label' => self::labels($entry['label'] ?? null, $key)];
		}
		return [$products, $problems];
	}

	/** Absolute path stays, anything else is taken relative to $base. */
	public static function resolve(string $path, string $base): string
	{
		$path = trim($path);
		return str_starts_with($path, '/') ? $path : rtrim($base, '/') . '/' . $path;
	}

	/**
	 * How many days back the fetch reads: enough for the bubble and the
	 * summary, whichever looks further back.
	 */
	public static function window(array $config): int
	{
		return max($config['maxAge'], $config['summaryDays']);
	}
}
