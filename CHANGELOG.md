# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change that makes a page contact a third party, or show or store more
  about a purchase** than product, time and (optionally) country, is a
  **major** version, whatever the code change.
- **A change to what counts as a purchase** (the rules in the README, "What
  counts") or to a default that decides what is shown (`maxAge`, `maxStale`,
  `summaryMin`, `max`) is at least a minor version and listed here: a site
  would show other numbers after a routine update.
- **A change to the data file format** increases `format` and ships with a
  reader for the old format, so a site never shows nothing between the update
  and the next fetch.
- A change to the privacy text (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the plugin must then review its privacy
  policy.

## [1.0.0] — 2026-10-13

First release. Replaces the Digistore24 "Social Proof Bubble" on client sites:
no script from digistore24.com, no first names, no towns.

### Added

- Package `sayamaapps/kirby-kehai`, Kirby plugin id `sayamaapps/kehai`, PHP
  namespace `Kehai\`.
- **`bin/kehai fetch`**: reads Digistore24's `listPurchases` (live orders of
  the last `max(maxAge, summaryDays)` days, paged) and writes the anonymous
  data file atomically. Reads the site's own `kehai.*` options by booting its
  Kirby. Key from `DIGISTORE24_API_KEY`, else `site/config/kehai.php`. Exit
  codes 0 written, 1 error (old file stays), 2 usage, 3 no key.
- **`bin/kehai check`**: configuration, data file, events per product and
  group, notes. Exit codes 0 fine, 1 problem, 2 usage. All output is plain
  text: control characters, escape sequences and bidi overrides from the
  configuration or the file are removed before printing.
- **What counts**: only products on the allow list (`kehai.products`, several
  ids per key); no test orders, refunds, chargebacks, cancellations,
  unpaid or aborted orders; a rebilling is never a new purchase (order date,
  window, one event per order id).
- **Data file format 1**: `format`, `generated`, `source`, `events[]` with
  `type`, `product`, `at` (UTC, rounded down to the minute) and optionally
  `country`. One gate (`Events::clean()`) on writing and reading.
- **Bubble**: snippet `kehai/bubble`, route `/kehai/events.json` (sentence and
  time only, `Cache-Control: public, max-age=60`), `assets/kehai.js` and
  `assets/kehai.css` from Kirby's plugin assets. Delay, number, duration, gap,
  position and maximum age configurable; closable for the page view; polite
  live region; no layout shift; no motion with `prefers-reduced-motion`; no
  cookie, no browser storage, no inline script or style.
- **Summary**: snippet `kehai/summary` and `$site->kehaiSummary()`, per
  product key or group, period and minimum configurable; nothing below the
  minimum.
- A page that shows the bubble or a summary stays in Kirby's page cache for
  15 minutes at most.
- **Freshness**: nothing is shown when the data file is missing, unreadable,
  of an unknown format or older than `kehai.maxStale` hours (48); one log line.
- **Pull mode** (`kehai.pull`): the site fetches by itself, at most every
  `pullInterval` minutes, one at a time (lock), 5 seconds, after the answer
  has been sent; the last good file stays on errors.
- **English and German**, every string replaceable with `kehai.texts`
  (Shoji's pattern), also registered as Kirby translations (`kehai.*`).
- `examples/kehai-fetch.yml`: hourly GitHub Actions workflow that uploads only
  the data file.
- CI on PHP 8.2–8.4 and Node 22.

### [privacy text]

- `DATENSCHUTZ.md` / `PRIVACY.md` are new: no transfer to third parties when
  the page loads, only anonymous purchase events (product, time, optionally
  country) on display, no cookies and no browser storage; what the fetch
  processes, where the file lives, where the key lives; additions for
  `kehai.country` and `kehai.pull`.
