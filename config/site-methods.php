<?php

use Sayamaapps\Kehai\Plugin;

// Site methods: the summary for a product key or group, and whether the bubble
// is printed. Each one delegates to Plugin.
return [
	/**
	 * The summary for a product key or group, or null (= show nothing).
	 *
	 *   $site->kehaiSummary('jahreskurs') → ['count' => 12, 'days' => 7, 'product' => 'Jahreskurs', 'text' => '…']
	 */
	'kehaiSummary' => function (string $name, int|null $days = null, int|null $min = null): array|null {
		return Plugin::summary($this->kirby(), $name, $days, $min);
	},

	/**
	 * Whether the snippet prints the bubble: a real event to show, or pull mode
	 * with its key. See Plugin::bubbleShown().
	 */
	'kehaiBubble' => function (array|null $products = null): bool {
		return Plugin::bubbleShown($this->kirby(), $products);
	},
];
