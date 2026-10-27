<?php

namespace Sayamaapps\Kehai;

use Kirby\Cms\App;

/**
 * `kehai fetch` and `kehai check`, run from the project root.
 *
 * Both read the site's own configuration (`kehai.*` in site/config/config.php),
 * so the allow list of products is written down once. For that the CLI
 * boots the site's Kirby from vendor/autoload.php (or kirby/bootstrap.php)
 * with the usual roots: the project root as `base`, `public/` as `index`
 * when it exists, `site/` and `content/`. Options in host-specific config
 * files (config.example.com.php) are not read on the command line.
 *
 * Exit codes: those of Fetch and Check, plus
 *   2  wrong usage, or no Kirby found
 *
 * @package   Kirby Kehai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Cli
{
	public const EXIT_USAGE = 2;

	/**
	 * `kehai fetch`: the key from the environment, else the site's secrets file;
	 * the file from `--file=`, else KEHAI_FILE, else the configuration.
	 *
	 * @param array<string,string> $env
	 * @param array<string, string> $args
	 * @param list<string> $problems
	 * @param resource $out
	 * @param resource $err
	 */
	private static function fetch(array $env, array $args, array $config, array $problems, string|null $key, string $cwd, $out, $err): int
	{
		foreach ($problems as $problem) {
			fwrite($err, 'Note: ' . Text::plain($problem, 300) . "\n");
		}
		$envKey = trim((string)($env[Fetch::ENV_KEY] ?? ''));
		$file = $args['file'] ?? (isset($env[Fetch::ENV_FILE]) ? (string)$env[Fetch::ENV_FILE] : null);
		return Fetch::run($config, $envKey !== '' ? $envKey : $key, $file, null, $cwd, $out, $err);
	}

	/** The site's Kirby, or a message why there is none. */
	public static function kirby(string $root): App|string
	{
		if (!class_exists(App::class)) {
			foreach (['/vendor/autoload.php', '/kirby/bootstrap.php'] as $loader) {
				if (is_file($root . $loader)) {
					require_once $root . $loader;
					break;
				}
			}
		}
		if (!class_exists(App::class)) {
			return 'Kirby not found in ' . $root . ' (no vendor/autoload.php or kirby/bootstrap.php). Run kehai from the project root or pass --root=.';
		}
		if (!is_dir($root . '/site')) {
			return 'No site/ folder in ' . $root . '. Run kehai from the project root or pass --root=.';
		}
		return new App(['roots' => [
			'index'   => is_dir($root . '/public') ? $root . '/public' : $root,
			'base'    => $root,
			'site'    => $root . '/site',
			'content' => $root . '/content',
		]]);
	}

	/**
	 * @param array<string,string> $env usually getenv()
	 * @param list<string> $argv
	 * @param resource $out
	 * @param resource $err
	 */
	public static function main(array $env, array $argv, string $cwd, $out, $err): int
	{
		[$command, $args] = self::parse($argv);
		if (!in_array($command, ['fetch', 'check'], true)) {
			return self::usageReply($command, $out, $err);
		}

		$kirby = self::kirby(self::root($args, $cwd));
		if (is_string($kirby)) {
			fwrite($err, Text::plain($kirby, 300) . "\n");
			return self::EXIT_USAGE;
		}
		['config' => $config, 'problems' => $problems] = Options::config($kirby);
		$key = Options::key(Options::secretsFile($kirby));

		if ($command === 'check') {
			return Check::run($config, $problems, $key !== null, $out);
		}
		return self::fetch($env, $args, $config, $problems, $key, $cwd, $out, $err);
	}

	/**
	 * The command (its first argument) and the `--root=` and `--file=` options.
	 * Any other argument turns the command into `help`.
	 *
	 * @param list<string> $argv
	 * @return array{0: string, 1: array<string, string>}
	 */
	private static function parse(array $argv): array
	{
		$command = $argv[1] ?? '';
		$args = [];
		foreach (array_slice($argv, 2) as $arg) {
			if (preg_match('/^--(root|file)=(.*)\z/s', $arg, $m)) {
				$args[$m[1]] = $m[2];
			} else {
				$command = 'help';
			}
		}
		return [$command, $args];
	}

	/** The site root: `--root=` if given and not blank, else the current directory. */
	private static function root(array $args, string $cwd): string
	{
		return isset($args['root']) && trim($args['root']) !== '' ? Config::resolve($args['root'], $cwd) : $cwd;
	}

	public static function usage(): string
	{
		return <<<TXT
        Usage: kehai fetch [--file=storage/kehai/events.json] [--root=.]
               kehai check [--root=.]

        fetch  reads the source and writes the anonymous data file (kehai.file,
               or --file / KEHAI_FILE relative to the current directory).
               Key: DIGISTORE24_API_KEY, else site/config/kehai.php.
               Exit codes: 0 written, 1 error (the old file stays), 3 no key.
        check  shows configuration, data file and events per product.
               Exit codes: 0 fine, 1 a problem.
        Both:  2 wrong usage or no Kirby in the project root.

        TXT;
	}

	/** @param resource $out @param resource $err */
	private static function usageReply(string $command, $out, $err): int
	{
		fwrite(in_array($command, ['help', '--help', '-h'], true) ? $out : $err, self::usage());
		return in_array($command, ['--help', '-h'], true) ? 0 : self::EXIT_USAGE;
	}
}
