<?php

namespace App\Providers;

use App\Services\CryptoMath;
use App\Services\ExchangeEcc;
use App\Services\ExchangeRequestSecurity;
use App\Services\ItemEcc;
use App\Services\ItemSecurity;
use App\Services\KeyManager;
use App\Services\MacService;
use App\Services\NotificationEcc;
use App\Services\NotificationService;
use App\Services\PostEcc;
use App\Services\PostSecurity;
use App\Services\ProfileRsa;
use App\Services\ProfileSecurity;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CryptoMath::class);
        $this->app->singleton(ProfileRsa::class);
        $this->app->singleton(ItemEcc::class);
        $this->app->singleton(PostEcc::class);
        $this->app->singleton(NotificationEcc::class);
        $this->app->singleton(ExchangeEcc::class);
        $this->app->singleton(MacService::class);
        $this->app->singleton(KeyManager::class);
        $this->app->singleton(ItemSecurity::class);
        $this->app->singleton(ProfileSecurity::class);
        $this->app->singleton(PostSecurity::class);
        $this->app->singleton(NotificationService::class);
        $this->app->singleton(ExchangeRequestSecurity::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}
