<?php

namespace Kehai;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Purchases from Digistore24 (`listPurchases`), turned into anonymous
 * events.
 *
 *   GET https://www.digistore24.com/api/call/listPurchases/json?from=YYYY-MM-DD&to=YYYY-MM-DD
 *       &search[order_type]=live&search[product_id]=111111,222222&sort_by=date&sort_order=desc&page_no=1&page_size=500
 *   X-DS-API-KEY: …
 *   → {"result": "success", "data": {"purchase_list": [ … ]}}   (sometimes `data` is the list itself)
 *
 * Each purchase in the answer carries the buyer's name, e-mail address,
 * the order id, amounts, affiliate and campaign. None of that leaves this
 * class: an event is built from four values only — the product key the
 * site configured for `main_product_id`, `created_at` rounded down to the
 * minute, and, if `kehai.country` is on, `vat_country`. Notes name counts,
 * reasons and product ids, never anything about a buyer.
 *
 * What counts as a purchase (see the README, "What counts"):
 *
 *  - Only products on the allow list (`kehai.products`); others are
 *    counted in the notes by product id, so the site can add them.
 *  - Test orders never: the request asks for live orders only
 *    (`search[order_type]=live`), and an entry that still says
 *    `order_type: test`, `is_test_order` or `is_test_payment` is dropped.
 *  - Refunds and chargebacks never: `transaction_type`, when present, must
 *    be `payment` (Digistore24's transaction types are payment, refund,
 *    chargeback, payment_missed, payment_denial, …); `is_canceled_now`
 *    (or the spelling `is_cancelled_now`) must not be `Y`.
 *  - Only an order that is paid or being paid: `billing_status` must be
 *    `completed` (single payment, or all instalments done) or `paying`
 *    (instalments or subscription running). `aborted`, `unpaid`,
 *    `reminding` and a missing status do not count — within the 14 days a
 *    bubble looks back, an aborted order is almost always a cancellation.
 *  - Rebillings are not new purchases: listPurchases lists orders, and
 *    `created_at` is when the order was placed, the first payment. A later
 *    instalment changes neither. An order whose `created_at` lies before
 *    the window is dropped even if the API returns it (because of a recent
 *    payment), and an order id that appears twice counts once.
 *
 * The request asks for the allow list's product ids only (`search[product_id]`,
 * comma separated) and newest first (`sort_by=date`, `sort_order=desc`; the
 * API's default is oldest first). The product filter only reduces the
 * volume: the allow list stays the gate (`main_product_id` is checked
 * locally). A regular answer is at most MAX_PAGES × PAGE_SIZE orders; when
 * the last page is still full the answer was cut. Newest first, what falls
 * off is the oldest, so the result's `from` moves to the minute after the
 * oldest order received (boundary()), and the notes say so. The data file
 * then covers a shorter period, and the summary never claims more. A cut
 * answer that is not newest first covers nothing: the source ignored the
 * sort, and what fell off is unknown.
 *
 * The rules are deliberately strict: a purchase that does not count is
 * never shown, one that does not clearly count is not shown either.
 */
final class Digistore24 implements Source
{
    public const ENDPOINT = 'https://www.digistore24.com/api/call/listPurchases/json';
    public const PAGE_SIZE = 500;
    public const MAX_PAGES = 20;

    /** billing_status values of an order that is paid or being paid. */
    public const PAID = ['completed', 'paying'];

    /**
     * @param Closure              $transport fn (string $url, array $headers): array{status:int, body:string}
     * @param array<string,string> $products  Digistore24 product id => product key (the allow list)
     */
    public function __construct(
        private readonly Closure $transport,
        private readonly string $key,
        private readonly array $products,
        private readonly bool $country = false,
        private readonly string $timezone = 'Europe/Berlin',
    ) {
    }

    public function id(): string
    {
        return 'digistore24';
    }

    public function events(DateTimeImmutable $from, DateTimeImmutable $now): array
    {
        $zone = new DateTimeZone($this->timezone);
        $purchases = [];
        $cut = false;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $list = $this->page($from->setTimezone($zone)->format('Y-m-d'), $now->setTimezone($zone)->modify('+1 day')->format('Y-m-d'), $page);
            array_push($purchases, ...$list);
            if (count($list) < self::PAGE_SIZE) {
                break;
            }
            $cut = $page === self::MAX_PAGES; // the last page we read was still full: the answer goes on
        }
        $result = $this->convert($purchases, $from, $now) + ['from' => $from];
        if ($cut) {
            $result['from'] = self::boundary($purchases, $from, $now, $zone);
            // The first note is the count line; the cut belongs right after it.
            array_splice($result['notes'], 1, 0, 'the source cut its answer after ' . self::MAX_PAGES * self::PAGE_SIZE . ' orders (MAX_PAGES × PAGE_SIZE): the file covers the last '
                . max(0, intdiv($now->getTimestamp() - $result['from']->getTimestamp(), 86400)) . ' days only');
        }
        return $result;
    }

    /**
     * Where a cut answer is complete from. The request asks for newest
     * first, so the answer is the newest MAX_PAGES × PAGE_SIZE orders —
     * if the source honoured that. Every readable `created_at` must
     * therefore be in non-increasing order; otherwise nothing is known to
     * be complete ($now, the file covers nothing). An order created before
     * the window among them means every order of the window came along
     * ($from). Else the boundary is the minute after the oldest order
     * received. No readable time at all: $now.
     */
    private static function boundary(array $purchases, DateTimeImmutable $from, DateTimeImmutable $now, DateTimeZone $zone): DateTimeImmutable
    {
        $oldest = null;
        foreach ($purchases as $purchase) {
            $time = is_array($purchase) ? self::time($purchase['created_at'] ?? null, $zone) : null;
            if ($time === null) {
                continue;
            }
            if ($oldest !== null && $time > $oldest) {
                return $now;
            }
            $oldest = $time;
        }
        if ($oldest === null) {
            return $now;
        }
        if ($oldest < $from) {
            return $from;
        }
        $minute = $oldest->setTimezone(new DateTimeZone('UTC'));
        return $minute->setTime((int) $minute->format('H'), (int) $minute->format('i'))->modify('+60 seconds');
    }

    /**
     * The purchases of an answer, as events and notes. Public for the
     * tests; events() calls it with every page.
     *
     * @return array{events: list<array>, notes: list<string>}
     */
    public function convert(array $purchases, DateTimeImmutable $from, DateTimeImmutable $now): array
    {
        $zone = new DateTimeZone($this->timezone);
        $latest = $now->getTimestamp() + Events::FUTURE_TOLERANCE;
        $events = [];
        $skipped = [];
        $unknown = [];
        $seen = [];
        foreach ($purchases as $purchase) {
            $reason = $this->reason($purchase);
            if ($reason === null) {
                $id = (string) ($purchase['main_product_id'] ?? '');
                if (!isset($this->products[$id])) {
                    $shown = preg_match(Config::ID_PATTERN, $id) ? $id : '(no valid id)';
                    $unknown[$shown] = ($unknown[$shown] ?? 0) + 1;
                    $reason = 'product not in kehai.products';
                }
            }
            $at = $reason === null ? self::time($purchase['created_at'] ?? null, $zone) : null;
            if ($reason === null && $at === null) {
                $reason = 'no valid created_at';
            }
            if ($reason === null && ($at < $from || $at->getTimestamp() > $latest)) {
                $reason = 'created before the window (a later payment of an older order)';
            }
            $order = $purchase['id'] ?? $purchase['purchase_id'] ?? null;
            if ($reason === null && is_scalar($order) && (string) $order !== '') {
                if (isset($seen[(string) $order])) {
                    $reason = 'listed twice';
                }
                $seen[(string) $order] = true;
            }
            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                continue;
            }
            $event = ['type' => 'purchase', 'product' => $this->products[(string) $purchase['main_product_id']], 'at' => Events::minute($at)];
            $code = $purchase['vat_country'] ?? null;
            if ($this->country && is_string($code) && preg_match('/^[A-Za-z]{2}\z/', $code)) {
                $event['country'] = strtoupper($code);
            }
            $events[] = $event;
        }

        $notes = [count($purchases) . ' purchases received, ' . count($events) . ' counted'];
        ksort($skipped);
        foreach ($skipped as $reason => $count) {
            $notes[] = "  not counted: $count × $reason";
        }
        ksort($unknown);
        foreach ($unknown as $id => $count) {
            $notes[] = "  product $id is not in kehai.products ($count purchases)";
        }
        return ['events' => $events, 'notes' => $notes];
    }

    /** Why a purchase does not count, or null. Reads only status fields. */
    private function reason(mixed $purchase): ?string
    {
        if (!is_array($purchase)) {
            return 'not a purchase entry';
        }
        $yes = fn (mixed $value) => in_array(is_string($value) ? strtoupper(trim($value)) : $value, ['Y', 'YES', '1', 'TRUE', true, 1], true);
        if (strtolower((string) ($purchase['order_type'] ?? '')) === 'test' || $yes($purchase['is_test_order'] ?? null) || $yes($purchase['is_test_payment'] ?? null)) {
            return 'test order';
        }
        $type = $purchase['transaction_type'] ?? null;
        if ($type !== null && strtolower((string) $type) !== 'payment') {
            $type = strtolower((string) $type);
            return 'transaction_type ' . (preg_match('/^[a-z_]{1,30}\z/', $type) ? $type : '(other)');
        }
        if ($yes($purchase['is_canceled_now'] ?? $purchase['is_cancelled_now'] ?? null)) {
            return 'cancelled (is_canceled_now)';
        }
        $status = strtolower((string) ($purchase['billing_status'] ?? ''));
        if (!in_array($status, self::PAID, true)) {
            return 'billing_status ' . ($status === '' ? '(missing)' : (preg_match('/^[a-z_]{1,30}\z/', $status) ? $status : '(other)'));
        }
        return null;
    }

    /** `created_at` as a time: "2026-10-12 14:03:11" in the account's time zone, or ISO 8601 with offset. */
    public static function time(mixed $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?\z/', $value)) {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i' . (strlen($value) > 16 ? ':s' : ''), str_replace('T', ' ', $value), $zone);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})\z/', $value)) {
            try {
                $time = new DateTimeImmutable($value);
            } catch (\Throwable) {
                $time = false;
            }
        } else {
            return null;
        }
        // createFromFormat() rolls 2026-02-31 over to March; that is not a date.
        $errors = DateTimeImmutable::getLastErrors();
        return $time === false || ($errors !== false && $errors['warning_count'] > 0) ? null : $time;
    }

    /**
     * One page of purchases.
     *
     * @return list<mixed>
     * @throws SourceException
     */
    private function page(string $from, string $to, int $page): array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'from'       => $from,
            'to'         => $to,
            'search'     => ['order_type' => 'live'] + ($this->products === [] ? [] : ['product_id' => implode(',', array_keys($this->products))]),
            'sort_by'    => 'date',
            'sort_order' => 'desc',
            'page_no'    => $page,
            'page_size'  => self::PAGE_SIZE,
        ]);
        try {
            $answer = ($this->transport)($url, ['X-DS-API-KEY: ' . $this->key, 'Accept: application/json']);
        } catch (\Throwable $e) {
            throw new SourceException('Digistore24 not reachable: ' . $this->safe($e->getMessage()));
        }
        $status = (int) ($answer['status'] ?? 0);
        $decoded = json_decode((string) ($answer['body'] ?? ''), true);
        if ($status !== 200) {
            $message = is_array($decoded) ? ($decoded['message'] ?? null) : null;
            throw new SourceException('Digistore24 answered HTTP ' . $status . (is_string($message) && $message !== '' ? ': ' . $this->safe($message) : ''));
        }
        if (!is_array($decoded)) {
            throw new SourceException('Digistore24 answered with a body that is not JSON');
        }
        if (($decoded['result'] ?? null) !== 'success') {
            $message = $decoded['message'] ?? null;
            throw new SourceException('Digistore24 answered result "' . $this->safe(is_scalar($decoded['result'] ?? null) ? (string) $decoded['result'] : '?', 20) . '"'
                . (is_string($message) && $message !== '' ? ': ' . $this->safe($message) : ''));
        }
        $data = $decoded['data'] ?? null;
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }
        if (is_array($data) && is_array($data['purchase_list'] ?? null)) {
            return array_values($data['purchase_list']);
        }
        throw new SourceException('Digistore24 answered without a purchase list');
    }

    /**
     * A message from Digistore24 or the network, safe to print: without the
     * key, without anything that looks like an e-mail address, plain text,
     * at most $max characters.
     */
    private function safe(string $message, int $max = 200): string
    {
        $message = $this->key === '' ? $message : str_replace($this->key, '[key]', $message);
        $message = (string) preg_replace('/[^\s@<>"\']+@[^\s@<>"\']+/u', '[e-mail]', $message);
        return Text::plain($message, $max);
    }
}
