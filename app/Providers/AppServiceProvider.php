<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Named limiters the routes reference.
     */
    protected function configureRateLimiting(): void
    {
        /*
         * Keyed on the connection's webhook token rather than the caller's
         * address: GitHub delivers from a wide, changing range, so an IP key
         * would either throttle unrelated connections together or, behind a
         * proxy, throttle nothing at all. A busy repository can burst, hence
         * a ceiling well above any realistic merge rate.
         */
        RateLimiter::for('github-webhook', fn (Request $request) => Limit::perMinute(120)
            ->by((string) $request->route('token')));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
