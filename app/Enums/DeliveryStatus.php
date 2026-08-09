<?php

namespace App\Enums;

/**
 * What became of a delivery GitHub signed and we accepted.
 *
 * The log exists to answer one question after the fact: why did that pull
 * request never show up? `Ignored` carries the answer most of the time, and
 * it is a perfectly healthy outcome — the vast majority of what a
 * `pull_request` subscription delivers is not a merge.
 */
enum DeliveryStatus: string
{
    /** Recorded, not yet handled. */
    case Received = 'received';

    /** Acted on: a candidate was written or refreshed. */
    case Processed = 'processed';

    /** Deliberately not acted on, with a reason. */
    case Ignored = 'ignored';

    /** Something was wrong with it, with a reason. */
    case Failed = 'failed';
}
