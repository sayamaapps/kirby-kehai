# Kehai

Honest social proof for [Kirby CMS](https://getkirby.com) 5: real, recent purchases, shown from your own server.

*Kehai* (気配) is Japanese for the sense that someone is there — without knowing who: footsteps in the next room, a light behind a paper screen. That is the sense here. The plugin shows that people really book an offer ("Someone from Austria booked “Jahreskurs” · 2 hours ago"), and never who they are.

- **Real events only.** Every notice is a purchase from your Digistore24 account, with its real time. Refunds, chargebacks, cancellations, test orders and later instalments are not purchases (see [What counts](#what-counts)). There is no setting that invents, repeats or embellishes an entry; nothing at all is shown when there is no fresh data.
- **No third-party request when the page loads.** A small CLI (`kehai fetch`) reads Digistore24 on a runner of your own and writes one anonymous JSON file, which is uploaded to the web server. The page, the script and the stylesheet come from your own site. Replaces Digistore24's "Social Proof Bubble", which loads a script from digistore24.com on every page.
- **No personal data.** The data file holds the type of event, a product key from your configuration, the time rounded to the minute and — only if you switch it on — the buyer's country. Never a name, an e-mail address, an order number, an amount, an affiliate or a campaign. Tests feed full purchases with names and addresses through the fetch and check that none of it reaches the file or the output.
- **The API key never reaches the web server.** It lives where the fetch runs (a repository secret). An optional pull mode lets the site fetch by itself — then the key is in `site/config/kehai.php`, and the page still never waits for the source.
- **Two displays, both off by default.** A *bubble* in a corner of the page, and a *summary* line for the page text ("In the last 7 days, “Jahreskurs” was booked 12 times"), which says nothing below a minimum.
- **Content Security Policy friendly.** One script and one stylesheet from Kirby's plugin assets. No inline script, no style attribute; `'self'` is all it needs (see [Content Security Policy](#content-security-policy)).
- **Nothing is stored in the browser.** No cookie, no local or session storage. Closing the bubble ends it for this page view.
- **Accessible.** A polite live region, a real close button, no layout shift, no motion with `prefers-reduced-motion`.
- **English and German**, every string replaceable.

## Requirements

- PHP 8.2–8.4 with `mbstring`; `curl` recommended for the fetch
- Kirby 5
- A Digistore24 account with an API key (read access)
- A place to run the fetch every hour: a GitHub Actions runner (your own or GitHub's) or a cron job — or [pull mode](#pull-mode)

## Installation

```sh
composer require sayamaapps/kirby-kehai
```

Composer installs the plugin into `site/plugins/kehai/` (via `getkirby/composer-installer`, which ships with `getkirby/cms`) and links the CLI as `vendor/bin/kehai`. Add `/site/plugins/kehai/` to `.gitignore` and make sure your deploy ships it, just as it ships `kirby/` and `vendor/`.

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/simsimmal/kirby-kehai" }]
```

Manual installation (download into `site/plugins/kehai/`) also works; the CLI is then `php site/plugins/kehai/bin/kehai`.

## Quick start

1. **Configure** in `site/config/config.php` (not in a host-specific config file: the CLI reads `config.php`):

   ```php
   return [
       'kehai' => [
           'active'   => true,
           'products' => [
               // Digistore24 product id => key and label
               '123456' => ['key' => 'jahreskurs', 'label' => ['de' => 'Jahreskurs', 'en' => 'Annual course']],
               '123457' => ['key' => 'jahreskurs', 'label' => ['de' => 'Jahreskurs', 'en' => 'Annual course']], // same offer, instalments
           ],
           'bubble'   => true,
           'summary'  => true,
           'country'  => true,   // optional: "Someone from Austria …"
       ],
   ];
   ```

2. **Fetch once** from the project root, to see what would count:

   ```sh
   DIGISTORE24_API_KEY=… vendor/bin/kehai fetch
   vendor/bin/kehai check
   ```

3. **Put the snippets into the templates:**

   ```php
   <?php snippet('kehai/summary', ['product' => 'jahreskurs']) ?>   <!-- in the page text -->
   …
   <?php snippet('kehai/bubble') ?>                                 <!-- before </body> -->
   ```

4. **Set up the hourly fetch** ([Operation](#operation)) and remove Digistore24's Social Proof Bubble script from the site.

## Operation

The recommended setup keeps fetching and showing apart:

```
runner (hourly)                                   web server
───────────────                                   ──────────
DIGISTORE24_API_KEY (secret)                      site/config/config.php  (kehai.*)
vendor/bin/kehai fetch  ──▶ events.json ──upload──▶ storage/kehai/events.json
                                                   │
visitor ◀── page, kehai.js, kehai.css, /kehai/events.json  (all from this server)
```

`examples/kehai-fetch.yml` is a complete GitHub Actions workflow for the **site's** repository: every hour it checks out the site, installs its packages, runs `kehai fetch` with the key from the repository secret `DIGISTORE24_API_KEY` and uploads **only the data file** (an `lftp` step as a placeholder for your own upload; it uploads under a temporary name and renames, so the site never reads half a file). Nothing is committed, nothing else is deployed. Copy it to `.github/workflows/`, replace the values marked `←`, store the secrets, run it once by hand.

### `kehai fetch`

```sh
DIGISTORE24_API_KEY=… vendor/bin/kehai fetch [--file=build/kehai/events.json] [--root=.]
```

Run it from the project root: it boots the site's Kirby (from `vendor/autoload.php`) to read the `kehai.*` options, so the list of products is written down once. The key comes from `DIGISTORE24_API_KEY`, otherwise from `site/config/kehai.php` (for a local run).

| Exit code | Meaning |
|---|---|
| `0` | File written (also when nothing counts: an empty list is the truth). |
| `1` | Error: no products configured, Digistore24 not reachable, an error answer, an answer that is not JSON, the file not writable. The old file stays. |
| `2` | Wrong usage, or no Kirby in the project root. |
| `3` | No key — a workflow turns this into a notice. |

The output lists how many purchases arrived and how many counted, the reason for every one that did not (`not counted: 2 × transaction_type refund`), product ids that are not in `kehai.products`, and the events per product key. It never prints a name, an address, an order id or the key; Digistore24's own error messages are cleaned of the key and of anything that looks like an e-mail address.

The fetch reads the last `max(maxAge, summaryDays)` days (14 by default) and replaces the file completely on every run. A purchase that is refunded tomorrow disappears from the site with the next run.

### `kehai check`

```sh
vendor/bin/kehai check [--root=.]
```

Shows the configuration, the data file (age, format, number of events), the events per product and group (in the bubble's and in the summary's period, and whether the summary reaches its minimum) and notes such as "No event in the last 14 days: the bubble stays hidden". Exit code `0` when nothing is wrong, `1` when there is a problem (file missing, older than `maxStale`, unreadable; configuration errors; pull mode without key), `2` for wrong usage. Every value from the configuration and the data file is printed as plain text: control characters, escape sequences and bidi overrides are removed first.

### Pull mode

For a site without a runner: `'kehai.pull' => true` and the key in `site/config/kehai.php`:

```php
<?php return ['key' => '…'];   // keep this file out of git
```

The site then fetches by itself:

- at most every `pullInterval` minutes (15); a failed attempt counts too, so a broken source is not asked on every request;
- one fetch at a time (an exclusive, non-blocking lock in `storage/kehai/`);
- with a time limit of 5 seconds;
- on any error the last good file stays and the error log gets one line;
- **never while a visitor waits.** The fetch is started from the `/kehai/events.json` route, which the bubble's script loads in the background, and it runs after the answer has been handed over. On PHP-FPM (and LiteSpeed) a page render can start it as well, after `fastcgi_finish_request()`; elsewhere only the route does, since the visitor would wait otherwise. A site that uses only the summary should therefore run on PHP-FPM or use the workflow.

The key is then on the web server, in a PHP file that is never served. The workflow avoids that and is the recommended setup.

## What counts

Digistore24's `listPurchases` returns orders, each with the buyer's details and a few status fields. Kehai reads only the status fields to decide, and stores only what is described in [Data file](#data-file).

| Rule | Field | Why |
|---|---|---|
| Only configured products | `main_product_id` must be a key of `kehai.products` | The allow list. Other products (an affiliate product, a hidden test product) are reported by id in the fetch output, never shown. Several ids can share one key, e.g. single payment and instalments. |
| No test orders | request with `search[order_type]=live`; an entry with `order_type: test`, `is_test_order` or `is_test_payment` is dropped as well | A test purchase is not a purchase. Filtered by Digistore24 and once more locally, in case the filter is ignored. |
| No refunds, no chargebacks | `transaction_type`, when present, must be `payment` | Digistore24 knows `payment`, `refund`, `chargeback`, `payment_missed`, `payment_denial` and more (the types of its IPN events); only a payment counts. Without the field, `billing_status` decides. |
| No cancellations | `is_canceled_now` (or `is_cancelled_now`) must not be `Y` | Cancelled with immediate effect: no longer a customer. |
| Paid or being paid | `billing_status` must be `completed` or `paying` | `aborted`, `unpaid`, `reminding` and a missing status do not count. Within the days a bubble looks back, an aborted order is almost always a cancellation; an unpaid one was never paid. |
| A rebilling is not a new purchase | the time is the order's `created_at`; an order created before the window is dropped even if the API lists it; an order id that appears twice counts once | `listPurchases` lists orders, not payments, and `created_at` is when the order was placed (the first payment). A later instalment changes neither, so it can never show up as "booked 2 hours ago". |
| Real time | `created_at` in the account's time zone (`kehai.timezone`, Europe/Berlin), stored in UTC, rounded **down** to the minute | Never later than it happened; an entry more than five minutes in the future is a clock error and dropped. |

The rules are deliberately strict: a purchase that does not clearly count is not shown. A change to these rules is listed in the [CHANGELOG](CHANGELOG.md).

## Honesty

Social proof that is invented, repeated, embellished or older than it claims is misleading. In Germany that is an unfair commercial practice (§ 5 UWG; in the EU, the Unfair Commercial Practices Directive); a competitor or a consumer association can send a cease-and-desist letter. Kehai is built so that it cannot do that:

- It can only show events that are in the source's current answer: there is no way to add, import or pin an entry, and every fetch replaces the file.
- The bubble shows the newest real events, each **once** per page view and never in a loop, at most `kehai.max`, none older than `kehai.maxAge` days (14).
- The relative time ("2 hours ago") is computed from the real time of the event when it is shown, not when the page was built; a visitor clock that is clearly wrong is replaced by the server's.
- When the data file is missing, unreadable or older than `kehai.maxStale` hours (48) — the fetch stopped — the site shows nothing at all and writes one line to the error log.
- The summary counts bookings, not people (one person may book twice), and says nothing below `kehai.summaryMin` (3): no "0 bookings", no "be the first".

Texts are yours to change (`kehai.texts`); keep them as true as the numbers. This is a description of the implementation, not legal advice.

## Configuration

Everything is off by default. All keys are read as `kehai.<name>`, flat or nested. `null` counts as "not set".

| Option | Default | Meaning |
|---|---|---|
| `active` | `false` | Master switch. Off: snippets print nothing, the route answers 404. Only `true` switches on. |
| `products` | `[]` | The allow list: Digistore24 product id => `['key' => 'jahreskurs', 'label' => ['de' => …, 'en' => …]]` (or a single string label). Keys: `a–z`, `0–9`, `-`, `_`. |
| `groups` | `[]` | For the summary: name => `['products' => ['jahreskurs', 'wochenendkurs'], 'label' => …]`. |
| `country` | `false` | Store and show the buyer's country (`vat_country`): "Someone from Austria booked …". Off: the country is not even stored. |
| `file` | `storage/kehai/events.json` | Data file: absolute, relative to the project root (Kirby's `base` root, or the directory above `site/`), or a closure. The upload target of the workflow. |
| `source` | `digistore24` | The only source in 1.0. |
| `timezone` | `Europe/Berlin` | Time zone of Digistore24's `created_at`. |
| `maxStale` | `48` | Hours without a successful fetch after which nothing is shown. |
| `bubble` | `false` | The bubble (snippet `kehai/bubble`, route `/kehai/events.json`). |
| `position` | `left` | `left` or `right` (bottom corner). |
| `delay` | `8` | Seconds before the first entry. |
| `max` | `3` | Entries per page view, at most (1–20). |
| `duration` | `6` | Seconds each entry stays (at least 2). |
| `gap` | `10` | Seconds between two entries. |
| `maxAge` | `14` | Days; older events are never shown in the bubble. |
| `css` | `true` | `false`: the snippet prints no stylesheet; style `.kehai` yourself. |
| `summary` | `false` | The summary (snippet `kehai/summary`, `$site->kehaiSummary()`). |
| `summaryDays` | `7` | Period of the summary, in days. |
| `summaryMin` | `3` | Below this count the summary says nothing. |
| `pull` | `false` | [Pull mode](#pull-mode). Needs the key in `site/config/kehai.php`. |
| `pullInterval` | `15` | Minutes between two pull attempts (at least 5). |
| `language` | site language | `en` or `de`. Otherwise the language of the request, the default language, the first two letters of Kirby's `locale` option, then English. |
| `texts` | `[]` | Replace single strings, per language or for all: `['de' => ['bubble.purchase' => 'Jemand hat „{product}“ gekauft']]`. Keys in `translations/en.php`; a site can add countries (`'country.JP' => 'Japan'`). |

Invalid values fall back to the default; the reason is written to the error log once and listed by `kehai check`.

## Snippets

### Bubble

```php
<?php snippet('kehai/bubble') ?>
<?php snippet('kehai/bubble', ['products' => ['jahreskurs']]) ?>   // only these product keys, e.g. on the offer's page
```

Prints nothing unless Kehai and the bubble are on and there is a real event to show. Otherwise:

```html
<link rel="stylesheet" href="/media/plugins/sayamaapps/kehai/…/kehai.css">
<div class="kehai kehai-left" data-kehai data-kehai-src="/kehai/events.json?lang=de" data-kehai-delay="8" data-kehai-max="3" data-kehai-duration="6" data-kehai-gap="10">
  <p class="kehai-text" role="status" aria-live="polite" aria-atomic="true"></p>
  <button type="button" class="kehai-close" aria-label="Schließen" hidden><span aria-hidden="true">×</span></button>
</div>
<script src="/media/plugins/sayamaapps/kehai/…/kehai.js" defer></script>
```

The container is fixed to a corner and empty until the script fills it, so it never moves the page. The look is in custom properties (`--kehai-background`, `--kehai-color`, `--kehai-border`, `--kehai-shadow`, `--kehai-radius`, `--kehai-offset`, `--kehai-font-size`) that a site's stylesheet can set on `.kehai`.

`/kehai/events.json` answers with the sentences and times only, `Cache-Control: public, max-age=60`:

```json
{"now": "2026-10-13T10:00:00Z",
 "texts": {"now": "gerade eben", "minute": "vor 1 Minute", "minutes": "vor {count} Minuten", "hour": "…", "hours": "…", "day": "…", "days": "…"},
 "events": [{"text": "Jemand aus Österreich hat „Jahreskurs“ gebucht", "at": "2026-10-13T07:41:00Z"}]}
```

Own markup: create `site/snippets/kehai/bubble.php` (Kirby takes it first) and keep the container's data attributes and the two child elements; `$site->kehaiBubble()` says whether there is anything to show.

### Summary

```php
<?php snippet('kehai/summary', ['product' => 'jahreskurs']) ?>
<?php snippet('kehai/summary', ['product' => 'courses', 'days' => 30, 'min' => 5]) ?>   // a group, own period and minimum
```

```html
<p class="kehai-summary">In den letzten 7 Tagen wurde „Jahreskurs“ 12-mal gebucht</p>
```

Rendered on the server, no script. A page that shows the summary or the bubble stays in Kirby's page cache for 15 minutes at most, so a cached page never keeps an old count. For own markup:

```php
$summary = $site->kehaiSummary('jahreskurs');   // null = show nothing
// ['count' => 12, 'days' => 7, 'product' => 'Jahreskurs', 'text' => 'In den letzten 7 Tagen …']
```

## Data file

`storage/kehai/events.json` (or `kehai.file`), written by `kehai fetch` or pull mode, replaced completely on every run:

```json
{
  "format": 1,
  "generated": "2026-10-13T09:00:12Z",
  "source": "digistore24",
  "events": [
    {"type": "purchase", "product": "jahreskurs", "at": "2026-10-13T07:41:00Z", "country": "AT"}
  ]
}
```

Exactly these fields; `country` only with `kehai.country`. `at` is UTC, rounded down to the minute. Reading applies the same rules as writing: an event with any other type, an unknown product key or a malformed time is ignored, and a file with another `format` is refused (nothing shown, logged) rather than guessed at. The file is written atomically (temporary file, then rename).

`storage/` lies next to `public/` in the usual Kirby setup and is not reachable through the web. In a setup without a `public/` folder, keep the file outside the document root or deny `storage/` in the server configuration — the file holds nothing the route does not show anyway, but it need not be public.

## Content Security Policy

Kehai needs `'self'` and nothing else:

| Directive | Needs | Why |
|---|---|---|
| `script-src` | `'self'` | `kehai.js` comes from `/media/plugins/sayamaapps/kehai/…`; there is no inline script. |
| `style-src` | `'self'` | `kehai.css` from the same place; no style attribute, and the script sets no inline style. |
| `connect-src` | `'self'` | The script fetches `/kehai/events.json`. Covered by `default-src 'self'`. |

After switching from Digistore24's Social Proof Bubble, `https://www.digistore24.com` can leave `script-src` (unless the site still embeds Digistore24 order forms or scripts elsewhere).

## Privacy

See [PRIVACY.md](PRIVACY.md) for what is fetched, stored and shown, and [DATENSCHUTZ.md](DATENSCHUTZ.md) for the German text block for privacy policies. They describe the implementation and are not legal advice.

## Planned

- **Brevo sign-ups** as a second source ("Someone signed up for the newsletter"), as an adapter of the same interface (`Kehai\Source`) with event type `signup`. Not part of 1.0.

## Development

```sh
composer install --ignore-platform-req=ext-gd   # Kirby is a dev dependency used as a test fixture
composer test                                   # or: sh tests/run.sh
```

The tests are plain PHP scripts (exit code 0 = pass) and, when Node is installed, `node --test tests/*.test.mjs` for the bubble script against a fake DOM. The fetch is tested against a recorded Digistore24 answer in `tests/fixtures/` (with made-up buyers); no test needs a network or a key.

## Maintenance

This module is open source under the MIT license and is maintained for the author's own projects. Issues and pull requests are welcome, but there is no support promise and no release schedule. Report security problems privately through GitHub's private vulnerability reporting (the "Report a vulnerability" button on the Security tab).

## License

MIT, see [LICENSE](LICENSE).
