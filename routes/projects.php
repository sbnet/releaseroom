<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RepositoryConnectionController;
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
            });
        });
});
