<?php

namespace App\Enums;

/**
 * Where a merged pull request stands in the owner's triage.
 *
 * Only a human ever writes this. Ingestion is explicitly forbidden from
 * touching it, which is what makes a dismissal survive every later delivery
 * and every later sync — without that rule, each sweep would resurrect
 * everything the owner had already ruled out.
 */
enum CandidateState: string
{
    /** Waiting for the owner to rule on it. */
    case Pending = 'pending';

    /** Ruled out: noise, chore, or simply not worth a changelog line. */
    case Dismissed = 'dismissed';
}
