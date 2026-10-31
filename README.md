# Kehai

Honest social proof for [Kirby CMS](https://getkirby.com): real, recent Digistore24 purchases, shown from your own server. Kehai shows notices such as "Someone from Austria booked “Jahreskurs” · 2 hours ago" in a corner bubble or as a summary line in the page text. It never shows who bought, and it shows nothing that did not happen.

## Features

- Real purchases only: no refunds, cancellations, test orders or later instalments.
- No third-party request on page load: script, stylesheet and data come from your server.
- No personal data: no names, e-mail addresses or order numbers.
- No cookies, no browser storage, no inline script; `'self'` is enough for a Content Security Policy.
- A bubble, a summary line, or both; off by default; English and German.
- The API key stays on a runner of your own; an optional pull mode fetches on the server.

## Requirements

- Kirby 5
- PHP 8.2 to 8.5 with `mbstring`; `curl` recommended (streams are used without it)
- A Digistore24 account with an API key (read access)
- An hourly job for `kehai fetch`: a GitHub Actions runner or cron, or [pull mode](#pull-mode)

Kirby 6 is tested in CI as a pre-release only; it is not declared as supported yet.

## Installation

Pick one of three ways.

**Composer** (installs to `site/plugins/kehai/` and links the CLI as `vendor/bin/kehai`):

```sh
composer require sayamaapps/kirby-kehai
```

Add `site/plugins/kehai/` to `.gitignore` if you deploy with `vendor/`.

**Git submodule:**

```sh
git submodule add https://github.com/sayamaapps/kirby-kehai.git site/plugins/kehai
```

**ZIP:** download the release archive from GitHub and extract it to `site/plugins/kehai/`. The CLI then runs as `php site/plugins/kehai/bin/kehai`.

## Usage

Everything is off by default. Minimal `site/config/config.php` (the CLI reads only this file, not host-specific ones):

```php
return [
    'sayamaapps.kehai.active' => true,
    'sayamaapps.kehai.bubble' => true,
    'sayamaapps.kehai.products' => [
        // Digistore24 product id => key and label
        '123456' => ['key' => 'jahreskurs', 'label' => ['de' => 'Jahreskurs', 'en' => 'Annual course']],
    ],
];
```

The options are named `sayamaapps.kehai.<name>`, written flat (see [Configuration](#configuration)). The 1.x name `kehai.<name>` still works.

Fetch once from the project root, then check what was counted:

```sh
DIGISTORE24_API_KEY=… vendor/bin/kehai fetch
vendor/bin/kehai check
```

The bubble goes before `</body>`, the summary into the page text:

```php
<?php snippet('kehai/bubble') ?>
<?php snippet('kehai/summary', ['product' => 'jahreskurs']) ?>
```

The bubble snippet accepts `['products' => ['jahreskurs']]` to show only some products. For your own markup, create `site/snippets/kehai/bubble.php`, or call `$site->kehaiSummary('jahreskurs')`, which returns `['count' => …, 'text' => …]` or `null`. Pages that show Kehai content are cached for 15 minutes at most.

## Operation

The API key stays on a runner that fetches the purchases and uploads only the data file. `docs/kehai-fetch.yml` is a GitHub Actions workflow for the site's repository: copy it to `.github/workflows/`, replace the values marked `←`, store the secrets and run it once by hand.

`kehai fetch` runs from the project root and boots the site's Kirby to read the `sayamaapps.kehai.*` options, so the product list is written down once. The key comes from `DIGISTORE24_API_KEY`, otherwise from `site/config/kehai.php`. `--file` and `--root` override the data file (`sayamaapps.kehai.file`) and the project root. Each run reads the last `max(maxAge, summaryDays)` days and replaces the data file completely. Exit codes: `0` written, `1` error (the old file stays), `2` wrong usage or no Kirby, `3` no key.

### Pull mode

For a site without a runner, set `'sayamaapps.kehai.pull' => true` and put the key in `site/config/kehai.php`:

```php
<?php return ['key' => '…'];   // keep this file out of git
```

The site then fetches by itself, at most every `pullInterval` minutes, after the answer has been sent. A page render or a request to `/kehai/events.json` (the bubble's script makes it) can start the attempt; a failed attempt counts, and the last good file stays. The key is then on the web server, so the runner is preferred. A summary-only site needs PHP-FPM or LiteSpeed (a page render starts the fetch there) or the workflow.

## What counts

Kehai reads only the status, product and time of an order, and the country if enabled. It counts an order when:

- its `main_product_id` is a key of `sayamaapps.kehai.products`; other products are reported by id in the fetch output, never shown;
- it is live: `order_type` is not `test`, and neither `is_test_order` nor `is_test_payment` is set;
- `transaction_type`, when present, is `payment`: refunds and chargebacks do not count;
- `is_canceled_now` is not `Y`, and `billing_status` is `completed` or `paying`;
- it is within the window. Its `created_at`, the first payment, is the time shown, rounded down to the minute. Later instalments change nothing, and each order counts once.

## Honesty

- Only events from the source's current answer: nothing can be added or pinned, and nothing is shown from a missing or stale file (`sayamaapps.kehai.maxStale`).
- The bubble shows the newest events, each once per page view, at most `sayamaapps.kehai.max`, none older than `sayamaapps.kehai.maxAge` days.
- The summary counts bookings, not people, and says nothing below `sayamaapps.kehai.summaryMin`.

## Configuration

Every option is `sayamaapps.kehai.<name>`, written flat: `'sayamaapps.kehai.active' => true`. The nested form `'kehai' => ['active' => true]` works for the 1.x name only (see below). `null` means "not set".

The 1.x name `kehai.<name>` still works as a fallback until 2.0, flat or nested, and is read without a warning. If both names are set, the new one wins. See [Deprecations](#deprecations).

| Option | Default | Description |
|---|---|---|
| `active` | `false` | Master switch; only `true` enables Kehai. Off: snippets print nothing, the route returns 404. |
| `products` | `[]` | Allow list: Digistore24 product id => `['key' => …, 'label' => …]`. Keys: `a–z`, `0–9`, `-`, `_`. |
| `groups` | `[]` | Groups for the summary: `name => ['products' => ['key', …], 'label' => …]`. |
| `country` | `false` | Store and show the buyer's country (`vat_country`). Off: it is not stored. |
| `file` | `storage/kehai/events.json` | Data file, absolute or relative to the project root. A closure is allowed. |
| `source` | `digistore24` | The only source. |
| `timezone` | `Europe/Berlin` | Time zone of Digistore24's `created_at`. |
| `maxStale` | `48` | Hours after which the data file is too old to show anything. |
| `bubble` | `false` | Enable the bubble (`kehai/bubble`, route `/kehai/events.json`). |
| `position` | `left` | Corner of the bubble: `left` or `right`. |
| `delay` | `8` | Seconds before the first entry. |
| `max` | `3` | Entries per page view, 1 to 20. |
| `duration` | `6` | Seconds each entry stays, at least 2. |
| `gap` | `10` | Seconds between two entries. |
| `maxAge` | `14` | Days; older events are never shown in the bubble. |
| `css` | `true` | `false`: the snippet prints no stylesheet; style `.kehai` yourself. |
| `summary` | `false` | Enable the summary (`kehai/summary`, `$site->kehaiSummary()`). |
| `summaryDays` | `7` | Summary period in days. |
| `summaryMin` | `3` | Below this count the summary says nothing. |
| `pull` | `false` | Enable [pull mode](#pull-mode); needs the key in `site/config/kehai.php`. |
| `pullInterval` | `15` | Minutes between two pull attempts, at least 5. |
| `language` | (auto) | `en` or `de`. Otherwise: request language, default language, Kirby's `locale`, English. |
| `texts` | `[]` | Replace single strings per language, e.g. `['de' => ['bubble.purchase' => '…']]`. Keys: `translations/en.php`. |

## Deprecations

The 1.x names keep working and print no notice. They are deprecated and will be removed in the next major version.

| 1.x | Replacement |
|---|---|
| `kehai.<option>` in `config.php` | `sayamaapps.kehai.<option>` | Still read as fallback. Rename the keys in your site config. |
| classes `Kehai\…` | `Sayamaapps\Kehai\…` (class aliases, all made when the plugin loads) |
| `kehai_option()` | `Sayamaapps\Kehai\Options::get()` |
| `kehai_config()` | `Sayamaapps\Kehai\Plugin::config()` |
| `kehai_i18n()` | `Sayamaapps\Kehai\Plugin::i18n()` |
| `kehai_asset_url()` | `Sayamaapps\Kehai\Plugin::assetUrl()` |

The 1.x functions `kehai_pull_later()`, `kehai_cache_limit()`, `kehai_keys()` and `kehai_route()` still work; they were never documented.

Site methods `$site->kehaiBubble()` and `$site->kehaiSummary()`, the snippets `kehai/bubble` and `kehai/summary`, the route `/kehai/events.json`, the options (both spellings) and the data file format do not change.

### Planned for 2.0

Breaking changes, so not in 1.x:

- translation keys `sayamaapps.kehai.<key>`;
- Kirby exception types instead of `RuntimeException`;
- run-time deprecation notices for the 1.x names;
- removal of the class aliases and the 1.x functions.

## Dependencies

Kehai has no runtime dependencies. Two parts are its own, on purpose:

- **HTTP client** (`src/Http.php`). It fetches HTTPS addresses only, follows no redirects, reads at most 10 MB, and gives one pull a single time limit for all its pages. Kirby's `Remote` is not used for these limits.
- **Texts** (`src/I18n.php`). The tables are PHP arrays. `config/translations.php` hands them to Kirby's translation system, so `t('kehai.…')` and the snippets use the same strings.

## Privacy

- **No cookies, no browser storage, no third parties.** The page makes no request to Digistore24 or anyone else, and nothing is stored on the visitor's device.
- **Anonymous events.** Each event holds only its type, the product key, the time rounded down to the minute and, with `sayamaapps.kehai.country`, the buyer's country. Each run replaces the file, so there is no archive.

```json
{"format": 1, "generated": "2026-10-13T09:00:12Z", "from": "2026-09-29T09:00:12Z", "source": "digistore24",
 "events": [{"type": "purchase", "product": "jahreskurs", "at": "2026-10-13T07:41:00Z", "country": "AT"}]}
```

- **The API key** is never printed. It stays in the runner's secrets; in pull mode it sits in `site/config/kehai.php`, outside git and not served.

See [PRIVACY.md](PRIVACY.md) (English) and [DATENSCHUTZ.md](docs/DATENSCHUTZ.md) (German text for privacy policies). They are not legal advice.

## Maintenance

Tests and checks: `composer test` runs PHPUnit; see [CONTRIBUTING](.github/CONTRIBUTING.md) for the rest. CI runs the lowest, the locked (`composer.lock`) and the highest dependency versions. After a dependency change, run `composer update` and commit the lock. Issues and pull requests are welcome, without a support promise. Report security problems privately (see [SECURITY](.github/SECURITY.md)). Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE).
