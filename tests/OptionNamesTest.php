<?php

use Kirby\Cms\App;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kehai\Options;

/**
 * Options are read under their 1.x names, `kehai.<key>`, and no other:
 * a new spelling would switch the module on for sites that 1.x leaves off.
 * Runs in debug mode too, where 1.x printed nothing either.
 */
#[CoversClass(Options::class)]
final class OptionNamesTest extends TestCase
{
	public function testReadingInDebugModeReportsNothing(): void
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

		$this->assertSame([], $reports, 'no run-time deprecation notice in 1.x');
	}

	public function testTheNewSpellingIsNotRead(): void
	{
		$this->withApp(['sayamaapps.kehai.active' => true], function (App $kirby): void {
			$this->assertNull(Options::get($kirby, 'active'), 'sayamaapps.kehai.active is not read');
		});
	}

	public function testTheOneNameIsRead(): void
	{
		$this->withApp(['kehai.active' => true], function (App $kirby): void {
			$this->assertSame(true, Options::get($kirby, 'active'), 'kehai.active is read');
		});
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
			App::destroy();
			removeTree($tmp);
		}
	}
}
