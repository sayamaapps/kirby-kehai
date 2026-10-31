<?php

use Kirby\Cms\App;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kehai\Check;
use Sayamaapps\Kehai\Config;
use Sayamaapps\Kehai\Options;

/**
 * Options are read as `sayamaapps.kehai.<key>`, falling back to the 1.x
 * `kehai.<key>`, which is read without a notice, also in debug mode. The
 * product list, the data file and the other fetch settings read the same way.
 * No network: the fetch and pull tests only see the configuration.
 */
#[CoversClass(Options::class)]
final class OptionsPrefixTest extends TestCase
{
	public function testCheckNamesTheSpellingTheSiteUsed(): void
	{
		$now = new DateTimeImmutable('2026-10-13T10:00:00Z');
		$this->withApp(['sayamaapps.kehai.active' => true], function (App $kirby) use ($now): void {
			$checked = Options::config($kirby);
			[, $out] = captured(fn ($out) => Check::run($checked['config'], $checked['problems'], false, $out, $now, $checked['names']));
			$this->assertStringContainsString('sayamaapps.kehai.products is empty', $out, 'Check names the new spelling');
		});
	}

	public function testFetchSettingsReadInEitherSpelling(): void
	{
		$products = ['111111' => ['key' => 'jahreskurs', 'label' => 'Annual course']];
		$this->withApp([
			'sayamaapps.kehai.active'   => true,
			'sayamaapps.kehai.products' => $products,
			'sayamaapps.kehai.file'     => 'storage/kehai/new.json',
			'kehai.maxAge'              => 7,
		], function (App $kirby): void {
			$config = Options::config($kirby)['config'];
			$this->assertSame(['jahreskurs'], Config::keys($config), 'products from sayamaapps.kehai.products');
			$this->assertStringEndsWith('/storage/kehai/new.json', $config['file'], 'file from sayamaapps.kehai.file, relative to the project root');
			$this->assertSame(7, $config['maxAge'], 'maxAge from the 1.x kehai.maxAge');
		});
	}

	public function testProblemsNameTheSpellingTheSiteUsed(): void
	{
		$bad = ['x' => ['key' => 'Bad Key']];
		$this->withApp(['sayamaapps.kehai.products' => $bad], function (App $kirby): void {
			$this->assertContains('sayamaapps.kehai.products: ignoring product x (key missing or not a-z, 0-9, - and _)', Options::config($kirby)['problems'], 'new spelling in the message');
		});
		$this->withApp(['kehai.products' => $bad], function (App $kirby): void {
			$this->assertContains('kehai.products: ignoring product x (key missing or not a-z, 0-9, - and _)', Options::config($kirby)['problems'], '1.x spelling in the message');
		});
	}

	public function testSetKeyNamesTheSpellingTheSiteUsed(): void
	{
		$this->withApp([], function (App $kirby): void {
			$this->assertSame('sayamaapps.kehai.max', Options::setKey($kirby, 'max'), 'nothing set: the new name');
		});
		$this->withApp(['kehai.max' => 3], function (App $kirby): void {
			$this->assertSame('kehai.max', Options::setKey($kirby, 'max'), 'only the 1.x name set: the 1.x name');
		});
		$this->withApp(['kehai.max' => 3, 'sayamaapps.kehai.max' => 5], function (App $kirby): void {
			$this->assertSame('sayamaapps.kehai.max', Options::setKey($kirby, 'max'), 'both set: the new name');
		});
	}

	public function testTheLegacyFunctionReadsTheNewSpelling(): void
	{
		$this->withApp(['sayamaapps.kehai.active' => true], function (App $kirby): void {
			$this->assertSame(true, kehai_option('active'), 'kehai_option() reads sayamaapps.kehai.active');
		});
	}

	public function testTheNewSpellingIsRead(): void
	{
		$this->withApp(['sayamaapps.kehai.active' => true], function (App $kirby): void {
			$this->assertSame(true, Options::get($kirby, 'active'), 'sayamaapps.kehai.active is read');
		});
	}

	public function testTheNewSpellingWinsWhenBothAreSet(): void
	{
		$this->withApp(['sayamaapps.kehai.max' => 5, 'kehai.max' => 3], function (App $kirby): void {
			$this->assertSame(5, Options::get($kirby, 'max'), 'the new name wins');
		});
	}

	public function testTheOneNameIsReadWithoutANotice(): void
	{
		$reports = [];
		set_error_handler(function (int $level, string $message) use (&$reports): bool {
			$reports[] = $message;
			return true;
		}, E_USER_DEPRECATED);

		try {
			$this->withApp(['debug' => true, 'kehai.max' => 3], function (App $kirby): void {
				for ($i = 0; $i < 3; $i++) {
					$this->assertSame(3, Options::get($kirby, 'max'), 'kehai.max is read on every call');
				}
			});
		} finally {
			restore_error_handler();
		}

		$this->assertSame([], $reports, 'no run-time deprecation notice in 1.x or 1.3');
	}

	public function testUnsetFallsBackToTheDefault(): void
	{
		$this->withApp([], function (App $kirby): void {
			$this->assertSame('default', Options::get($kirby, 'active', 'default'), 'unset: the given default');
		});
	}

	/** Runs $fn with a Kirby app on a throwaway site and removes the site afterwards. */
	private function withApp(array $options, callable $fn): void
	{
		$tmp = tempDir('options-prefix');
		try {
			$fn(new App([
				'roots'   => ['index' => $tmp, 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site'],
				'options' => ['url' => 'http://example.invalid'] + $options,
			]));
		} finally {
			App::destroy();
			removeTree($tmp);
		}
	}
}
