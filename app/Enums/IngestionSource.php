<?php

namespace App\Enums;

/**
 * How a pull request first reached the application.
 *
 * Three paths converge on one row, so the value records which of them won the
 * race. It is provenance for debugging — "this never arrived by webhook" is
 * the first thing worth knowing when live delivery looks broken — and it is
 * never overwritten once set.
 */
enum IngestionSource: string
{
    /** Delivered by GitHub as it happened. */
    case Webhook = 'webhook';

    /** Imported from history when the repository was connected. */
    case Backfill = 'backfill';

    /** Picked up by an owner-triggered sweep, filling a gap. */
    case Sync = 'sync';
}
