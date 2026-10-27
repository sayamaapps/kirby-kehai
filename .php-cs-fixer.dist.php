<?php

// Kirby's rule set removes @package from the class docblocks; this plugin keeps it.
return Kirby\PhpCs\Config::create()
	->setRules(['@Kirby/style:risky' => true, 'phpdoc_no_package' => false])
	->setFinder(
		PhpCsFixer\Finder::create()
			->exclude('vendor')
			->in(__DIR__)
	);
