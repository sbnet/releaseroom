<?php

namespace App\Enums;

/**
 * Whether GitHub is pushing merges at us yet.
 *
 * There is no "failed" case. Every way automatic setup can fail — a token
 * without the permission, a hook that already exists, GitHub being down —
 * leaves the owner in exactly the same position: the hook has to be created
 * by hand, or the attempt retried. One state, one set of instructions.
 */
enum WebhookStatus: string
{
    /** A hook exists and is delivering. */
    case Active = 'active';

    /** Nobody has created the hook yet; the owner has to, or retry. */
    case ManualSetupRequired = 'manual_setup_required';
}
