<?php
/**
 * The summary: one line for the page text, rendered on the server.
 *
 *   <?php snippet('kehai/summary', ['product' => 'jahreskurs']) ?>
 *   <?php snippet('kehai/summary', ['product' => 'courses', 'days' => 30, 'min' => 5]) ?>   (a group from kehai.groups)
 *
 * "In the last 7 days, “Jahreskurs” was booked 12 times." Counts real
 * purchases in the period; below the minimum (`kehai.summaryMin`, default 3)
 * it prints nothing — no "0 bookings", no "be the first". The text is
 * changed with `kehai.texts` (summary.purchase.*). For your own markup use
 * $site->kehaiSummary('jahreskurs') in a snippet of your own.
 *
 * @var string   $product product key or group name
 * @var int|null $days    period instead of kehai.summaryDays
 * @var int|null $min     minimum instead of kehai.summaryMin
 */
if (!isset($product) || !is_string($product)) {
    return;
}
$summary = site()->kehaiSummary($product, isset($days) ? (int) $days : null, isset($min) ? (int) $min : null);
if ($summary === null) {
    return;
}
?>
<p class="kehai-summary"><?= esc($summary['text']) ?></p>
