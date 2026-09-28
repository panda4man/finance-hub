<?php

namespace App\Providers;

use App\Models\CategoryRule;
use App\Observers\CategoryRuleObserver;
use App\Services\CategorizationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CategorizationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        CategoryRule::observe(CategoryRuleObserver::class);

        // Throttle middleware runs before auth resolves the default guard,
        // so the sanctum guard is checked explicitly. Keyed per user (not
        // per token) so issuing/rotating keys never changes a user's quota.
        RateLimiter::for('api', function (Request $request): Limit {
            $userId = $request->user('sanctum')?->getAuthIdentifier();

            return Limit::perMinute(60)->by($userId ? "user:{$userId}" : "ip:{$request->ip()}");
        });
    }
}
