<?php

use Kehai\Check;
use Kehai\Cli;
use Kehai\Events;
use Kehai\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `kehai check` and the CLI entry: what is reported, the exit codes, and
 * that no value from the configuration or the data file can put a control
 * character, an escape sequence or a bidi override into the terminal.
 * The CLI part boots a throwaway site with real Kirby.
 *   php tests/CheckTest.php
 */
#[CoversClass(Check::class)]
final class CheckTest extends TestCase
{
    public function testCheckAndCli(): void
    {


        $tmp = tempDir('check');
        $now = new DateTimeImmutable('2026-10-13T10:00:00Z');
        $check = fn (array $config, array $problems = [], bool $keyFile = false) =>
            captured(fn ($out) => Check::run($config, $problems, $keyFile, $out, $now));
        $event = fn (string $product, string $ago) => ['type' => 'purchase', 'product' => $product, 'at' => Events::minute($now->modify($ago))];

        try {
            // ---- Text::plain --------------------------------------------------------
            expect('red [31m text', Text::plain("red\x1b[31m text"), 'ESC becomes a space; the rest is harmless text');
            expect('a b', Text::plain("a\x00\x07\x7f\u{9b}b"), 'C0, DEL and C1 controls');
            expect('evil', Text::plain("e\u{202e}vil\u{200b}"), 'bidi override and zero-width removed');
            expect('a b c', Text::plain("a\nb\u{2028}c"), 'line breaks and separators become spaces');
            expect('a?b', Text::plain("a\xffb"), 'invalid UTF-8 replaced');
            expect('', Text::plain(['x']), 'arrays print nothing');
            expect(5, mb_strlen(Text::plain(str_repeat('x', 50), 5)), 'cut to the maximum');
            holds(Text::isPlain("ok\nfine") && !Text::isPlain("a\x1b[2Jb") && !Text::isPlain("a\u{202e}b") && !Text::isPlain("a\rb"), 'isPlain');

            // ---- A healthy setup ------------------------------------------------------
            $config = testConfig($tmp, ['bubble' => true, 'summary' => true]);
            Events::write($config['file'], Events::build([
                $event('jahreskurs', '-1 hour'), $event('jahreskurs', '-2 days'), $event('jahreskurs', '-3 days'), $event('wochenendkurs', '-10 days'),
            ], $now->modify('-30 minutes'), 'digistore24', false, null, $now->modify('-14 days')));
            [$code, $out] = $check($config);
            expect(Check::EXIT_OK, $code, 'healthy: exit 0 (' . $out . ')');
            foreach ([
                'active: on', 'products: 3', '111111 → jahreskurs ("Jahreskurs" / "Annual course")', 'group kurse: jahreskurs, wochenendkurs',
                'bubble: on (left, after 8 s, at most 3, events up to 14 days old)', 'summary: on (last 7 days, at least 3; the fetch reads 14 days)',
                'generated 2026-10-13 09:30 UTC (30 minutes ago), source digistore24, format 1', 'covers 14 days (from 2026-09-29 10:00 UTC)', '4 events',
                'jahreskurs: 3 in the last 14 days (bubble), 3 in the last 7 days (summary, shown)',
                'wochenendkurs: 1 in the last 14 days (bubble), 0 in the last 7 days (summary, below 3, not shown)',
                'group kurse: 3 in the last 7 days (summary, shown)', 'No problems.',
            ] as $line) {
                holds(str_contains($out, $line), "healthy: reports \"$line\"");
            }
            holds(!str_contains($out, 'covers only'), 'healthy: no note about a shortened coverage');

            // ---- What the file covers ------------------------------------------------------
            $write = fn (?DateTimeImmutable $from, ?array $events = null) => Events::write($config['file'], Events::build($events ?? [$event('jahreskurs', '-1 hour')], $now->modify('-30 minutes'), 'digistore24', false, null, $from));
            $write(null);
            [$code, $out] = $check($config);
            holds($code === Check::EXIT_OK && str_contains($out, 'covers: not recorded (written by 1.0.0; the fetch window of this configuration is assumed)') && !str_contains($out, 'covers only'),
                'file without "from" (1.0.0): coverage not recorded, no note, no problem');
            $write($now->modify('-3 days 30 minutes'));
            [$code, $out] = $check($config);
            holds(str_contains($out, 'covers 2 days (from 2026-10-10 10:30 UTC)') && str_contains($out, 'The data file covers only 2 days: summaries are shortened to 2 days (the source\'s answer was cut; see kehai fetch).'),
                'a file that covers less than summaryDays: the coverage and a note (' . $out . ')');
            expect(Check::EXIT_OK, $code, 'a shortened coverage is a note, not a problem');
            holds(str_contains($out, 'jahreskurs: 1 in the last 14 days (bubble), 1 in the last 2 days (summary, below 3, not shown)'), 'a shortened coverage: the per-product line counts the shortened period (' . $out . ')');
            $write($now->modify('-10 minutes'));
            [$code, $out] = $check($config);
            holds($code === Check::EXIT_OK && str_contains($out, 'covers 0 days (from 2026-10-13 09:30 UTC)') && str_contains($out, 'The data file covers less than a day: summaries show nothing')
                && str_contains($out, 'jahreskurs: 1 in the last 14 days (bubble), summary not shown (the data file covers less than a day)') && str_contains($out, 'group kurse: summary not shown'),
                'a file that covers less than a day: the note and the lines say the summary shows nothing (' . $out . ')');
            $write($now->modify('-7 days'));
            holds(!str_contains($check($config)[1], 'covers only'), 'coverage equal to summaryDays: no note');

            // ---- Problems: exit 1 ---------------------------------------------------------
            Events::write($config['file'], Events::build([$event('jahreskurs', '-1 hour')], $now->modify('-49 hours'), 'digistore24', false));
            [$code, $out] = $check($config);
            holds($code === Check::EXIT_PROBLEM && str_contains($out, 'older than kehai.maxStale (48 hours)'), 'stale file: exit 1');
            unlink($config['file']);
            [$code, $out] = $check($config);
            holds($code === Check::EXIT_PROBLEM && str_contains($out, 'The data file is missing'), 'missing file: exit 1');
            file_put_contents($config['file'], '{"format": 7}');
            [$code, $out] = $check($config);
            holds($code === Check::EXIT_PROBLEM && str_contains($out, 'unknown format 7'), 'unknown format: exit 1');
            [$code, $out] = $check(testConfig($tmp, ['products' => []]));
            holds($code === Check::EXIT_PROBLEM && str_contains($out, 'kehai.products is empty'), 'no products: exit 1');
            [$code, $out] = $check(testConfig($tmp, ['pull' => true]));
            holds($code === Check::EXIT_PROBLEM && str_contains($out, 'kehai.pull is on, but site/config/kehai.php has no key'), 'pull without key: exit 1');
            [$code, $out] = $check($config, ['kehai.position must be left or right; using left']);
            holds($code === Check::EXIT_PROBLEM && str_contains($out, 'kehai.position must be left or right'), 'configuration problems: exit 1');

            // ---- Notes -------------------------------------------------------------------------
            Events::write($config['file'], Events::build([$event('fremd', '-1 hour')], $now, 'digistore24', false));
            [$code, $out] = $check(testConfig($tmp, ['active' => false]), [], true);
            holds($code === Check::EXIT_OK, 'notes alone: exit 0');
            holds(str_contains($out, 'kehai.active is off'), 'note: switched off');
            holds(str_contains($out, 'holds a key although pull mode is off'), 'note: key on the web server without pull mode');
            holds(str_contains($out, '1 events for the product key "fremd"'), 'note: events of an unknown product key');
            $pullNote = 'kehai.pull is on and kehai.bubble is off';
            [$code, $out] = $check(testConfig($tmp, ['pull' => true, 'bubble' => false]), [], true);
            holds($code === Check::EXIT_OK && str_contains($out, $pullNote) && str_contains($out, 'only a page render on PHP-FPM or LiteSpeed starts the fetch'), 'note: pull mode without the bubble');
            [, $out] = $check(testConfig($tmp, ['pull' => true, 'bubble' => true]), [], true);
            holds(!str_contains($out, $pullNote), 'no note: pull mode with the bubble');
            [, $out] = $check(testConfig($tmp, ['pull' => true, 'bubble' => false, 'active' => false]), [], true);
            holds(!str_contains($out, $pullNote), 'no note: pull mode without the bubble, but switched off');
            [, $out] = $check($config);
            holds(str_contains($out, 'No event in the last 14 days: the bubble stays hidden.'), 'note: nothing to show');

            // ---- No control characters, whatever the configuration and the file say ---------------
            $evil = testConfig($tmp, [
                'file' => "storage/kehai/ev\x1b]0;owned\x07ents.json",
                'products' => ['111111' => ['key' => 'jahreskurs', 'label' => ['de' => "Jahres\x1b[2J\x1b[31mkurs\u{202e}", 'en' => "a\rb\nc"]]],
                'groups' => ['g' => ['products' => ['jahreskurs', "x\x1b[1m"], 'label' => "\x9b31m"]],
                'bubble' => true,
            ]);
            @mkdir(dirname($evil['file']), 0700, true);
            file_put_contents($evil['file'], json_encode([
                'format' => 1, 'generated' => '2026-10-13T09:00:00Z', 'source' => "ds\x1b[8m24\u{202e}",
                'events' => [$event('jahreskurs', '-1 hour'), ['type' => "purchase\x1b[2J", 'product' => "x\x1b[31m", 'at' => "\x1b"]],
            ]));
            $problems = Kehai\Config::normalize(['products' => ["1\x1b[2J" => ['key' => 'x'], '2' => ['key' => "\x1b[31m"]]], $tmp)['problems'];
            [$code, $out] = $check($evil, $problems);
            holds(Text::isPlain($out), 'check output has no control characters, escapes or bidi overrides: ' . json_encode($out));
            holds(str_contains($out, 'source ds [8m24,') && str_contains($out, '1 invalid entries ignored'), 'the values are shown, made harmless');

            // ---- The CLI with a real site ---------------------------------------------------------------
            $site = $tmp . '/site-root';
            mkdir($site . '/site/config', 0700, true);
            mkdir($site . '/public', 0700, true);
            file_put_contents($site . '/site/config/config.php', '<?php return ' . var_export(['kehai' => [
                'active' => true, 'bubble' => true,
                'products' => ['111111' => ['key' => 'jahreskurs', 'label' => "Jahres\x1b[31mkurs"]],
            ]], true) . ';');
            $cli = fn (array $argv, array $env = []) => captured(fn ($out, $err) => Cli::main($env, $argv, $site, $out, $err));

            [$code, , $err] = $cli(['kehai']);
            holds($code === Cli::EXIT_USAGE && str_contains($err, 'Usage: kehai fetch'), 'no command: usage, exit 2');
            [$code, $out] = $cli(['kehai', '--help']);
            holds($code === 0 && str_contains($out, 'Usage:'), '--help: exit 0');
            [$code, , $err] = $cli(['kehai', 'publish']);
            expect(Cli::EXIT_USAGE, $code, 'unknown command: exit 2');
            [$code, , $err] = $cli(['kehai', 'check', '--root=' . $tmp . '/nowhere']);
            holds($code === Cli::EXIT_USAGE && str_contains($err, 'No site/ folder'), 'no site: exit 2');

            [$code, $out] = $cli(['kehai', 'check']);
            expect(Check::EXIT_PROBLEM, $code, 'CLI check without a data file: exit 1');
            holds(str_contains($out, $site . '/storage/kehai/events.json') && str_contains($out, '111111 → jahreskurs'), 'CLI check reads the site configuration (' . $out . ')');
            holds(Text::isPlain($out), 'CLI check output plain');
            Events::write($site . '/storage/kehai/events.json', Events::build([$event('jahreskurs', '-1 hour')], new DateTimeImmutable('-5 minutes'), 'digistore24', false));
            [$code] = $cli(['kehai', 'check']);
            expect(Check::EXIT_OK, $code, 'CLI check with a fresh file: exit 0');

            [$code, $out] = $cli(['kehai', 'fetch']);
            holds($code === 3 && str_contains($out, 'No DIGISTORE24_API_KEY'), 'CLI fetch without a key: exit 3, no request');

        } finally {
            removeTree($tmp);
        }
    }
}
