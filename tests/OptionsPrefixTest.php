<?php

use Kirby\Cms\App;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kehai\Options;

/**
 * Options are read as sayamaapps.kehai.<key>, with the 1.x spelling
 * kehai.<key> as fallback. The fallback is reported as a deprecation in
 * debug mode only.
 */
#[CoversClass(Options::class)]
final class OptionsPrefixTest extends TestCase
{
	public function testNewSpellingIsRead(): void
	{
		$this->withApp(['sayamaapps.kehai.active' => true], function (App $kirby): void {
			$this->assertSame(true, Options::get($kirby, 'active'), 'sayamaapps.kehai.active is read');
		});
	}

	public function testNewSpellingWinsOverOldWithoutDeprecation(): void
	{
		$this->withApp(['sayamaapps.kehai.active' => false, 'kehai.active' => true], function (App $kirby): void {
			$this->assertSame(false, Options::get($kirby, 'active'), 'sayamaapps.kehai.active wins when both are set');
		});
	}

	public function testOldSpellingIsReadAndDeprecatedInDebugMode(): void
	{
		$deprecations = [];
		set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
			$deprecations[] = $message;
			return true;
		}, E_USER_DEPRECATED);

		try {
			$this->withApp(['debug' => true, 'kehai.active' => true], function (App $kirby): void {
				$this->assertSame(true, Options::get($kirby, 'active'), 'kehai.active is read as fallback');
			});
		} finally {
			restore_error_handler();
		}

		$this->assertSame(['Kehai: kehai.active is deprecated, use sayamaapps.kehai.active'], $deprecations);
	}

	public function testOldSpellingIsReportedOncePerName(): void
	{
		$deprecations = [];
		set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
			$deprecations[] = $message;
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

		$this->assertSame(['Kehai: kehai.max is deprecated, use sayamaapps.kehai.max'], $deprecations);
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
		$tmp = tempDir('options');
		try {
			$fn(new App([
				'roots'   => ['index' => $tmp, 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site'],
				'options' => ['url' => 'http://example.invalid'] + $options,
			]));
		} finally {
			removeTree($tmp);
		}
	}
}
