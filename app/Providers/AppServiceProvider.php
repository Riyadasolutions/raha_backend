<?php

namespace App\Providers;

use App\Support\Permissions\PermissionResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionResolver::class);
    }

    public function boot(): void
    {
        // Named limiters: each route family has its own budget (a shared counter made contact/auth starve each other).
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(20)->by('auth|'.$r->ip()));
        RateLimiter::for('contact', fn (Request $r) => Limit::perMinute(5)->by('contact|'.$r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(60)->by('uploads|'.($r->user()?->id ?? $r->ip())));

        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Permission codes (contain a dot, e.g. "cases.view") resolve through RBAC; default deny.
        Gate::before(function ($user, string $ability) {
            if (str_contains($ability, '.')) {
                return app(PermissionResolver::class)->has($user, $ability) ?: false;
            }

            return null;
        });
    }
}
