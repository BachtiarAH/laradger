<?php

namespace App\Providers;

use App\Services\Ai\AiCallRecordingService;
use App\Services\Ai\Contracts\AiCallRecorder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiCallRecorder::class, function ($app) {
            return $app->make(AiCallRecordingService::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
