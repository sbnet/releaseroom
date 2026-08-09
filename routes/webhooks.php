<?php

use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Deliveries from GitHub.
 *
 * Registered outside the `web` middleware group on purpose: this route has no
 * session, issues no cookie and carries no CSRF token. Its authentication is
 * the HMAC signature on the raw body, and the opaque token in the path is
 * what says which connection the delivery belongs to — two owners may have
 * connected the same public repository, so nothing inside the payload could
 * tell us that.
 *
 * The throttle is keyed on that token rather than the address: GitHub
 * delivers from a wide and changing range, so an IP key would either lump
 * unrelated connections together or do nothing at all.
 */
Route::post('webhooks/github/{token}', GitHubWebhookController::class)
    ->middleware('throttle:github-webhook')
    ->name('webhooks.github');
