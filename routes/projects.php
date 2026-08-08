<?php

use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

/*
 * Management screens bind by id, not by slug, so that renaming a slug never
 * breaks a management URL. The slug is reserved for the future public
 * release page.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('projects', ProjectController::class);
});
