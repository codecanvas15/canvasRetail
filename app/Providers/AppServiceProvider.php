<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //comment for development
        URL::forceScheme('https');
        date_default_timezone_set('Asia/Jakarta');
        Schema::defaultStringLength(2048);
        Schema::defaultStringLength(64, 'personal_access_tokens');
    }
}
