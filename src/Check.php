<?php

namespace Kehai;

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
 */
final class Check
{
    public const EXIT_OK = 0;
    public const EXIT_PROBLEM = 1;

    /**
     * @param array        $config   Config::normalize()['config']
     * @param list<string> $problems Config::normalize()['problems']
     * @param bool         $keyFile  whether site/config/kehai.php has a key
     * @param resource     $out
     */
    public static function run(array $config, array $problems, bool $keyFile, $out, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now');
        $notes = [];
        $say = function (string $text, int $indent = 0) use ($out): void {
            fwrite($out, str_repeat('  ', $indent) . Text::plain($text, 300) . "\n");
        };
        $onOff = fn (bool $on) => $on ? 'on' : 'off';

        $say('Kehai check');
        $say('');
        $say('Configuration');
        $say('active: ' . $onOff($config['active']), 1);
        $say('source: ' . $config['source'] . ', time zone ' . $config['timezone'], 1);
        $say('products: ' . ($config['products'] === [] ? 'none' : count($config['products'])), 1);
        foreach ($config['products'] as $id => $product) {
            $say($id . ' → ' . $product['key'] . ' ("' . implode('" / "', $product['label']) . '")', 2);
        }
        foreach ($config['groups'] as $name => $group) {
            $say('group ' . $name . ': ' . ($group['products'] === [] ? '(empty)' : implode(', ', $group['products'])), 2);
        }
        $say('country: ' . $onOff($config['country']), 1);
        $say('bubble: ' . $onOff($config['bubble']) . ($config['bubble']
            ? " ({$config['position']}, after {$config['delay']} s, at most {$config['max']}, events up to {$config['maxAge']} days old)" : ''), 1);
        $say('summary: ' . $onOff($config['summary']) . ($config['summary']
            ? " (last {$config['summaryDays']} days, at least {$config['summaryMin']}; the fetch reads " . Config::window($config) . ' days)' : ''), 1);
        $say('pull: ' . $onOff($config['pull']) . ($config['pull'] ? " (every {$config['pullInterval']} minutes)" : '')
            . ', key in site/config/' . Options::SECRETS . ': ' . ($keyFile ? 'yes' : 'no'), 1);

        if (!$config['active']) {
            $notes[] = 'kehai.active is off: the site shows nothing and the route answers 404.';
        } elseif (!$config['bubble'] && !$config['summary']) {
            $notes[] = 'kehai.bubble and kehai.summary are both off: nothing is shown.';
        }
        if ($config['active'] && $config['pull'] && !$config['bubble']) {
            $notes[] = 'kehai.pull is on and kehai.bubble is off: only a page render on PHP-FPM or LiteSpeed starts the fetch (the bubble\'s script would call the route elsewhere). On another server use the workflow.';
        }
        if ($config['products'] === []) {
            $problems[] = 'kehai.products is empty: no purchase is counted.';
        }
        if ($config['pull'] && !$keyFile) {
            $problems[] = 'kehai.pull is on, but site/config/' . Options::SECRETS . ' has no key.';
        }
        if (!$config['pull'] && $keyFile) {
            $notes[] = 'site/config/' . Options::SECRETS . ' holds a key although pull mode is off. With the workflow, the key does not belong on the web server.';
        }

        $say('');
        $say('Data file');
        $say($config['file'], 1);
        $read = Events::read($config['file'], true, null);
        $events = [];
        if ($read['error'] !== null) {
            $say($read['error'], 1);
            $problems[] = 'The data file is ' . ($read['error'] === 'missing' ? 'missing' : 'unusable (' . $read['error'] . ')') . ': nothing is shown. Run `kehai fetch` or check the upload.';
        } else {
            $age = $now->getTimestamp() - $read['generated']->getTimestamp();
            $say('generated ' . $read['generated']->format('Y-m-d H:i') . ' UTC (' . self::age($age) . ' ago), source ' . ($read['source'] ?? '?') . ', format ' . Events::FORMAT, 1);
            $covered = Events::covered($read['from'], $config, $now);
            $say($read['from'] === null
                ? 'covers: not recorded (written by 1.0.0; the fetch window of this configuration is assumed)'
                : 'covers ' . $covered . ' days (from ' . $read['from']->format('Y-m-d H:i') . ' UTC)', 1);
            $say(count($read['events']) . ' events' . ($read['dropped'] > 0 ? ', ' . $read['dropped'] . ' invalid entries ignored' : ''), 1);
            if ($read['dropped'] > 0) {
                $notes[] = $read['dropped'] . ' entries in the data file are not valid events and are ignored.';
            }
            if ($read['from'] !== null && $covered < $config['summaryDays']) {
                $notes[] = "The data file covers only $covered days: summaries are shortened to $covered days (the source's answer was cut; see kehai fetch).";
            }
            if ($age > $config['maxStale'] * 3600) {
                $problems[] = 'The data file is older than kehai.maxStale (' . $config['maxStale'] . ' hours): nothing is shown until the next successful fetch.';
            } elseif ($age < -Events::FUTURE_TOLERANCE) {
                $problems[] = 'The data file claims to be generated in the future; check the clocks of the server and of the machine that runs the fetch.';
            }
            $events = $read['events'];
        }

        $say('');
        $say('Events per product');
        $keys = Config::keys($config);
        $limit = $now->getTimestamp() + Events::FUTURE_TOLERANCE;
        $future = count(array_filter($events, fn (array $e) => Events::time($e)->getTimestamp() > $limit));
        if ($future > 0) {
            $notes[] = "$future events lie in the future and are never shown.";
        }
        $within = function (string $key, int $days) use ($events, $now, $limit): int {
            $oldest = $now->getTimestamp() - $days * 86400;
            return count(array_filter($events, fn (array $e) => $e['product'] === $key
                && Events::time($e)->getTimestamp() >= $oldest && Events::time($e)->getTimestamp() <= $limit));
        };
        $bubbleTotal = 0;
        foreach ($keys as $key) {
            $bubble = $within($key, $config['maxAge']);
            $summary = $within($key, $config['summaryDays']);
            $bubbleTotal += $bubble;
            $say("$key: $bubble in the last {$config['maxAge']} days (bubble), $summary in the last {$config['summaryDays']} days (summary"
                . ($summary >= $config['summaryMin'] ? ', shown' : ', below ' . $config['summaryMin'] . ', not shown') . ')', 1);
        }
        foreach ($config['groups'] as $name => $group) {
            $summary = array_sum(array_map(fn (string $key) => $within($key, $config['summaryDays']), $group['products']));
            $say("group $name: $summary in the last {$config['summaryDays']} days (summary"
                . ($summary >= $config['summaryMin'] ? ', shown' : ', below ' . $config['summaryMin'] . ', not shown') . ')', 1);
        }
        $unknown = array_count_values(array_column(array_filter($events, fn (array $e) => !in_array($e['product'], $keys, true)), 'product'));
        ksort($unknown);
        foreach ($unknown as $key => $count) {
            $notes[] = "$count events for the product key \"$key\", which is not in kehai.products, are ignored.";
        }
        if ($keys === []) {
            $say('(no products configured)', 1);
        }
        if ($config['active'] && $config['bubble'] && $read['error'] === null && $bubbleTotal === 0) {
            $notes[] = "No event in the last {$config['maxAge']} days: the bubble stays hidden.";
        }

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
        return $problems === [] ? self::EXIT_OK : self::EXIT_PROBLEM;
    }

    private static function age(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return $seconds < 3600 ? intdiv($seconds, 60) . ' minutes' : (intdiv($seconds, 3600) . ' hours');
    }
}
