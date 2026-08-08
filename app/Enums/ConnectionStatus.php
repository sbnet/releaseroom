<?php

namespace App\Enums;

/**
 * Whether the last verification of a repository connection succeeded.
 *
 * There is no "pending" state: a connection is only ever written after
 * GitHub confirmed it, so it starts its life connected. `Failed` is a
 * recoverable state reached by a later re-check, never by a first connect.
 */
enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Failed = 'failed';
}
