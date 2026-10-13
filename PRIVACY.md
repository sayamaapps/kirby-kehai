# Kehai: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe
the implementation. They are not legal advice. Each operator has to determine
the legal basis and the wording for their own site.

## What the visitor's browser does

Nothing towards a third party. The notices ("Someone from Austria booked
“Jahreskurs” · 2 hours ago", "In the last 7 days, “Jahreskurs” was booked
12 times") come from the site's own server: the script, the stylesheet and
the data. No request goes to Digistore24 or anyone else when the page loads,
and no data about the visitor is passed on.

The plugin sets no cookies and uses no browser storage (no local storage, no
session storage). Closing the notice applies to the current page view only.
Loading the notices is an ordinary request to the site's own server, handled
like every other request (server log files).

## What is shown

Anonymous purchase events only: which offer was booked, when (rounded down to
the minute) and — if the site switches it on (`kehai.country`) — the buyer's
country (the VAT country of the order). Never a name, an e-mail address, an
address, an order number, an amount or payment details. A visitor cannot tell
from a notice who booked.

Only real purchases of the configured products are shown: no refunds,
chargebacks, cancellations, test orders or later instalments; no event older
than 14 days; nothing at all when the data is older than 48 hours.

## What is fetched, and where

About once an hour, `kehai fetch` reads the recent orders from Digistore24
with the site's API key — on a CI runner (recommended) or, in pull mode, on
the web server itself, independent of any visit. The answer holds full order
data, including buyers' names and e-mail addresses; it is processed in memory
only. Everything except event type, product, time and (optionally) country is
discarded before anything is written. The fetch's output and log lines name
counts, reasons and product ids, never anything about a buyer.

Whoever runs the CI runner processes the full order data for that moment and
belongs on the list of processors or service providers (GitHub, for
GitHub-hosted runners; nobody additional for the operator's own runner).

## Where it is stored, and for how long

One JSON file on the web server (`storage/kehai/events.json` by default),
outside the web root. Per purchase: `type`, `product` (a key from the site's
configuration), `at` and, with `kehai.country`, `country`. Every run replaces
the file completely; there is no archive. A purchase that is refunded
disappears with the next run.

## The API key

Lives only where the fetch runs: a repository secret in the recommended setup,
never in the Kirby configuration and never on the web server. Only in pull
mode is it on the web server, in `site/config/kehai.php` (out of git, not
served). It is never printed; error messages are scrubbed of it.
