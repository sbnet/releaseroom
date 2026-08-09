<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PullRequestCandidateController;
use App\Http\Controllers\RepositoryConnectionController;
use App\Http\Controllers\RepositoryWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Management screens bind by id, not by slug, so that renaming a slug never
 * breaks a management URL. The slug is reserved for the future public
 * release page.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('projects', ProjectController::class);

    /*
     * A project holds at most one repository, so this is a singular resource:
     * one address, no identifier of its own.
     *
     * The write routes are throttled because each one spends the token's
     * GitHub quota, and they are the only way to spend it from outside.
     *
     * Note that `throttle:10,1` keys on the authenticated user alone, not on
     * the route or the project: the ten calls a minute are shared across every
     * project an owner has and across all of these actions. The URL reads as
     * though it were per project and is not. That is deliberate — the quota
     * being protected belongs to the tokens, which belong to the user — but it
     * is not what the address suggests, so it is written down here.
     */
    Route::prefix('projects/{project}/repository')
        ->name('projects.repository.')
        ->controller(RepositoryConnectionController::class)
        ->group(function () {
            Route::get('/', 'edit')->name('edit');

            Route::middleware('throttle:10,1')->group(function () {
                Route::post('/', 'store')->name('store');
                Route::put('/', 'update')->name('update');
                Route::delete('/', 'destroy')->name('destroy');
                Route::post('check', 'check')->name('check');

                /*
                 * Spends the token's GitHub quota like the rest of this
                 * group, so it sits under the same limit.
                 */
                Route::post('sync', 'sync')->name('sync');
            });

            /*
             * Hook maintenance: retrying the setup that could not be done
             * automatically, and rotating the signing secret. The first
             * spends quota; the second is grouped with it because a rotation
             * loop against a hook we manage would spend it too.
             */
            Route::middleware('throttle:10,1')
                ->controller(RepositoryWebhookController::class)
                ->name('webhook.')
                ->group(function () {
                    Route::post('webhook', 'store')->name('store');
                    Route::post('webhook/secret', 'update')->name('secret');
                });
        });

    /*
     * The curation list: merged pull requests waiting for a ruling.
     *
     * Dismissing and restoring spend nothing and are not throttled — they are
     * ordinary writes against rows the owner already holds.
     */
    Route::prefix('projects/{project}/candidates')
        ->name('projects.candidates.')
        ->controller(PullRequestCandidateController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('{candidate}/dismiss', 'dismiss')->name('dismiss');
            Route::post('{candidate}/restore', 'restore')->name('restore');
        });
});
