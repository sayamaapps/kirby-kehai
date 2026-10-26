<?php
/**
 * Shared by the tests: assertions with the message first, a throwaway
 * directory, and a scripted transport that answers like Digistore24
 * without a network.
 */

use PHPUnit\Framework\Assert;

function expect($expected, $actual, string $what): void
{
	Assert::assertSame($expected, $actual, $what);
}

function holds(bool $ok, string $what): void
{
	Assert::assertTrue($ok, $what);
}

function tempDir(string $name): string
{
	$dir = sys_get_temp_dir() . '/kehai-' . $name . '-' . bin2hex(random_bytes(6));
	mkdir($dir, 0700, true);
	return $dir;
}

function removeTree(string $path): void
{
	Kirby\Filesystem\Dir::remove($path);
}

/**
 * A transport that answers from a script: each entry ['status' => …,
 * 'body' => …] or a Throwable to throw. Every call is recorded in $calls.
 */
function scripted(array $script, array &$calls): Closure
{
	return function (string $url, array $headers) use (&$script, &$calls): array {
		$calls[] = ['url' => $url, 'headers' => $headers];
		$next = array_shift($script) ?? ['status' => 500, 'body' => '{"result":"error","message":"script exhausted"}'];
		if ($next instanceof Throwable) {
			throw $next;
		}
		return $next;
	};
}

/** Runs a closure with two memory streams; returns [result, stdout, stderr]. */
function captured(Closure $run): array
{
	$out = fopen('php://memory', 'w+');
	$err = fopen('php://memory', 'w+');
	$result = $run($out, $err);
	rewind($out);
	rewind($err);
	return [$result, stream_get_contents($out), stream_get_contents($err)];
}

/** Runs a closure with the PHP error log redirected; returns [result, log text]. */
function logged(Closure $run): array
{
	$log = tempnam(sys_get_temp_dir(), 'kehai-log');
	$previous = ini_set('error_log', $log);
	try {
		$result = $run();
	} finally {
		ini_set('error_log', (string)$previous);
	}
	$text = (string)file_get_contents($log);
	unlink($log);
	return [$result, $text];
}

/** The site configuration the tests share: two Digistore24 ids for one key, one more product, a group. */
function testConfig(string $base, array $raw = []): array
{
	return Kehai\Config::normalize($raw + [
		'active'   => true,
		'products' => [
			'111111' => ['key' => 'jahreskurs', 'label' => ['de' => 'Jahreskurs', 'en' => 'Annual course']],
			'111112' => ['key' => 'jahreskurs', 'label' => ['de' => 'Jahreskurs', 'en' => 'Annual course']],
			'222222' => ['key' => 'wochenendkurs', 'label' => 'Wochenendkurs'],
		],
		'groups' => ['kurse' => ['products' => ['jahreskurs', 'wochenendkurs'], 'label' => ['de' => 'unsere Kurse', 'en' => 'our courses']]],
	], $base)['config'];
}

/** Everything personal or commercial in tests/fixtures/digistore-purchases.json that must never be written or printed. */
const PERSONAL = [
	'Annegret', 'Beispielhuber', 'annegret', '@example', 'Bertram', 'Quellenhof', 'Klothilde', 'Sternberg', 'Leopold', 'Ratenplan',
	'Musterweg', 'Wien', '+43', 'B-77001', 'AB12CD34', 'EF56GH78', 'OP12QR34', 'ST56UV78', '97.00', '16.17', '29.10',
	'aff_kehaitest', 'cmp-secret-77', 'trk-secret-88', 'clk-0042', 'creditcard', 'kehaitestvendor', 'Gruppe', 'installment', 'single_payment',
];
