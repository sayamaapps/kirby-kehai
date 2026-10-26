<?php
/**
 * The bubble: a small notice of a recent, real purchase in a corner of the
 * page. The plain default shipped with Kehai; for your own markup create
 * site/snippets/kehai/bubble.php (Kirby takes that one first) and keep the
 * data attributes, the script reads them.
 *
 *   <?php snippet('kehai/bubble') ?>                                    (before </body>)
 *   <?php snippet('kehai/bubble', ['products' => ['jahreskurs']]) ?>   (only these product keys)
 *
 * Prints nothing unless Kehai and the bubble are switched on and there is a
 * real event to show — in pull mode the container and the script are printed
 * even then, see kehaiBubble(). Otherwise: the stylesheet (unless `kehai.css` is
 * false), an empty container fixed to a corner and the script — both files
 * from this site, no inline script, no style attribute. The script loads
 * the entries from /kehai/events.json after `kehai.delay` seconds, shows at
 * most `kehai.max` of them, each once, and stops for this page view when
 * the visitor closes it. It stores nothing in the browser.
 *
 * The live region (role="status") is in the page from the start, so screen
 * readers announce each entry politely; the close button is hidden while
 * there is nothing to close.
 *
 * @var array|null $products product keys to show; null = all configured
 */
$products = isset($products) && is_array($products) ? array_values(array_filter($products, 'is_string')) : null;
if (!site()->kehaiBubble($products)) {
	return;
}
$config = kehai_config();
$language = kehai_i18n();
$script = kehai_asset_url('kehai.js');
$css = $config['css'] ? kehai_asset_url('kehai.css') : null;
if ($script === null) {
	return;
}
$query = ['lang' => $language];
if ($products !== null) {
	$query['products'] = implode(',', $products);
}
$data = [
	'data-kehai-src'      => url('kehai/events.json') . '?' . http_build_query($query),
	'data-kehai-delay'    => $config['delay'],
	'data-kehai-max'      => $config['max'],
	'data-kehai-duration' => $config['duration'],
	'data-kehai-gap'      => $config['gap'],
];
?>
<?php if ($css) : ?><link rel="stylesheet" href="<?= esc($css) ?>"><?php endif ?>
<div class="kehai kehai-<?= esc($config['position']) ?>" data-kehai<?php foreach ($data as $name => $value) : ?> <?= $name ?>="<?= esc((string)$value) ?>"<?php endforeach ?>>
  <p class="kehai-text" role="status" aria-live="polite" aria-atomic="true"></p>
  <button type="button" class="kehai-close" aria-label="<?= esc(\Kehai\I18n::t('close')) ?>" hidden><span aria-hidden="true">×</span></button>
</div>
<script src="<?= esc($script) ?>" defer></script>
