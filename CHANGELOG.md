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
  and the next fetch. An optional field that older readers ignore (such as
  `from`) needs no new `format`.
- A change to the privacy text (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the plugin must then review its privacy
  policy.

## [Unreleased]

### Fixed

- **`kehai fetch` reads the last N × 24 hours, not N calendar days.** The
  window was `-N days` in the process time zone, which is 23 or 25 hours on
  the days the clocks change, while coverage and the summary count 86400
  seconds per day. With `maxAge` or `summaryDays` of 1 and a Europe/Berlin
  process, a fresh purchase dropped out of the one-day summary on the spring
  change. The window now uses the same 86400 seconds as the rest.

## [1.1.0] — 2026-10-17

### Fixed

- **The summary never counts further back than the data file covers.** The
  fetch reads `max(maxAge, summaryDays)` days (14 by default); a snippet
  with `days => 30` still said "in the last 30 days" and counted 14. The
  data file now records the start of the period it covers completely
  (`from`); a summary period beyond it is shortened to the covered days and
  the reason is logged once. A file without `from` (written by 1.0.0) is
  taken to cover this configuration's fetch window. For a 30-day summary set
  `kehai.summaryDays` to 30.
- **A cut answer is no longer shown as an exact count.** When the source's
  answer is cut (20 full pages of 500 orders) or more than 2,000 purchases
  count, the file's `from` moves to the first minute that is complete, the
  fetch output says so, `kehai check` reports the covered days, and summaries
  shorten to them. The request asks Digistore24 for the configured product
  ids only (`search[product_id]`), newest first (`sort_by=date`,
  `sort_order=desc`), so a cut drops the oldest orders, never the newest; a
  cut answer that is not newest first covers nothing. The allow list stays
  the gate locally.
- **Pull mode starts again after an empty data state.** In pull mode the
  fetch is triggered by the bubble script's call of `/kehai/events.json`
  (a page render triggers it only on PHP-FPM and LiteSpeed). With the data
  file missing, empty or stale the snippet printed nothing, so the route was
  never called and the pull never ran again. `$site->kehaiBubble()` now
  returns true in pull mode, the container stays empty until there is an
  event; `kehai check` notes pull mode without the bubble.
- **One five-second deadline for the whole pull.** Every page request got
  the full five seconds again, so a pull of several slow pages ran well past
  the promised limit. Each request now gets only the whole seconds that are
  left; once they are used up the pull fails, the last good file stays and
  the attempt counts for `pullInterval`.

### Changed

- Data file: the new field `from` (UTC) after `generated`. `format` stays 1:
  a 1.0.0 file is read as before, and 1.0.0 reads a 1.1.0 file. A file
  without `from` is assumed to cover this configuration's fetch window; if
  `maxAge` or `summaryDays` was raised since that fetch, the next fetch sets
  it right. A `from` that is not a valid time refuses the file.
- User agent `kirby-kehai/1.1`.
- `kehai fetch` prints `window: 14 days asked, N days covered` when the file
  covers less than asked; `kehai check` prints the covered period and the
  fetch window.

## [1.0.0] — 2026-10-13

First release. Replaces Digistore24's own social-proof bubble: no script
from digistore24.com, no first names, no towns.

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
- **English and German**, every string replaceable with `kehai.texts`,
  also registered as Kirby translations (`kehai.*`).
- `examples/kehai-fetch.yml`: hourly GitHub Actions workflow that uploads only
  the data file.
- CI on PHP 8.2–8.4 and Node 22.

### [privacy text]

- `DATENSCHUTZ.md` / `PRIVACY.md` are new: no transfer to third parties when
  the page loads, only anonymous purchase events (product, time, optionally
  country) on display, no cookies and no browser storage; what the fetch
  processes, where the file lives, where the key lives; additions for
  `kehai.country` and `kehai.pull`.
