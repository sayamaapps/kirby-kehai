<?php

use Kirby\Cms\App;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kehai\Pull;

/**
 * The 1.x names in legacy.php: the class aliases resolve on first use, and
 * the 1.x functions answer as they did in 1.x.
 */
#[CoversNothing]
final class LegacyTest extends TestCase
{
	public function testClassAliasesResolveToTheNewClasses(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		$this->assertTrue(class_exists('Kehai\\Pull'), 'Kehai\\Pull loads through the alias autoloader');
		$this->assertSame('Sayamaapps\\Kehai\\Pull', (new ReflectionClass('Kehai\\Pull'))->getName());
		$this->assertTrue(interface_exists('Kehai\\Source'), 'the interface alias resolves too');
		$this->assertFalse(class_exists('Kehai\\NoSuchClass'), 'an unknown 1.x name stays unknown');
	}

	public function testRouteIsA404WhileTheModuleIsOff(): void
	{
		$this->withApp([], function (App $kirby): void {
			$this->assertFalse(kehai_route(), 'kehai_route() answers false (Kirby\'s 404) while kehai.active is off');
		});
	}

	public function testKeysReadTheProductParameter(): void
	{
		$config = testConfig(sys_get_temp_dir());
		$this->assertSame(['jahreskurs'], kehai_keys('jahreskurs, nope', $config), 'known keys are kept, unknown dropped');
		$this->assertSame(null, kehai_keys(null, $config), 'no parameter: all products');
		$this->assertSame(null, kehai_keys('  ', $config), 'blank parameter: all products');
		$this->assertSame(null, kehai_keys(['jahreskurs'], $config), 'a non-string parameter: all products');
	}

	public function testPullLaterDoesNothingWhileTheModuleIsOff(): void
	{
		$this->withApp([], function (App $kirby): void {
			kehai_pull_later($kirby, testConfig(sys_get_temp_dir()));
			$this->assertFalse(Pull::pending(), 'nothing is remembered for after the answer');
		});
	}

	public function testCacheLimitSetsTheCacheHeader(): void
	{
		$this->withApp([], function (App $kirby): void {
			kehai_cache_limit($kirby);
			$this->assertEqualsWithDelta(time() + 15 * 60, $kirby->response()->expires(), 5, 'the page may stay cached for 15 minutes at most');

		});
	}

	public function testOldFunctionsAreDeprecatedInDebugMode(): void
	{
		$deprecations = [];
		set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
			$deprecations[] = $message;
			return true;
		}, E_USER_DEPRECATED);

		try {
			$this->withApp(['debug' => true, 'sayamaapps.kehai.active' => true], function (App $kirby): void {
				$this->assertSame(true, kehai_option('active'), 'kehai_option() still reads the option');
			});
		} finally {
			restore_error_handler();
		}

		$this->assertSame(['kehai_option() is deprecated, use Sayamaapps\Kehai\Options::get()'], $deprecations);
	}

	/** Runs $fn with a throwaway site, like the other tests. */
	private function withApp(array $options, callable $fn): void
	{
		$tmp = tempDir('legacy');
		try {
			$kirby = new App([
				'roots'   => ['index' => $tmp, 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site'],
				'options' => ['url' => 'http://example.invalid'] + $options,
			]);
			$fn($kirby);
		} finally {
			removeTree($tmp);
		}
	}
}
