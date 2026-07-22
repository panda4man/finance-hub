<?php

namespace App\Providers;

use App\Models\CategoryRule;
use App\Observers\CategoryRuleObserver;
use App\Services\CategorizationService;
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
    }
}
