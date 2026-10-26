<?php

namespace Kehai;

use DateTimeImmutable;

/**
 * A place real activity comes from. 1.0 ships Digistore24 (purchases);
 * a Brevo adapter (sign-ups, type `signup`) is planned and fits the same
 * shape: it maps its own ids (list ids) to the site's product keys and
 * returns events, nothing else.
 *
 * An adapter is the only code that sees the source's raw answer, with
 * names, e-mail addresses and order numbers in it. It must return events
 * with no more than Events::clean() keeps — `type`, `product` (a key from
 * the site's allow list), `at` (Events::minute()) and, when asked for,
 * `country` — and its notes must not quote personal data. Events::build()
 * cleans the events once more before anything is written.
 */
interface Source
{
	/**
	 * Events between $from and $now, oldest first or in any order.
	 *
	 * `from` is the start of the period the events cover completely: the
	 * $from asked for, or later when the source cut its answer (a page
	 * limit). Events::build() writes it to the data file.
	 *
	 * @return array{events: list<array{type:string, product:string, at:string, country?:string}>, notes: list<string>, from: DateTimeImmutable}
	 * @throws SourceException with a message that is safe to print
	 */
	public function events(DateTimeImmutable $from, DateTimeImmutable $now): array;

	/** Short name stored in the data file, e.g. `digistore24`. */
	public function id(): string;
}
