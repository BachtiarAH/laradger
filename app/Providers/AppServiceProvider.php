<?php

namespace App\Providers;

use App\Services\Ai\AiCallRecordingService;
use App\Services\Ai\Contracts\AiCallRecorder;
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
        $this->app->singleton(AiCallRecorder::class, function ($app) {
            return $app->make(AiCallRecordingService::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email').'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // AI endpoints spend the user's own money per call, so they are capped
        // per authenticated user rather than per IP.
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(30)->by((string) ($request->user()?->getKey() ?? $request->ip()));
        });

        // The two read-only AI endpoints the drafting page polls while a prompt is
        // in flight. Separate from `ai` because they call no model and cost
        // nothing: leaving them on the spending limit meant a queued prompt's
        // progress bar exhausted the budget and the next real message was
        // rejected. 120/min is roughly five times what the page actually uses, so
        // it still stops a runaway client without getting in the way.
        RateLimiter::for('ai-status', function (Request $request) {
            return Limit::perMinute(120)->by((string) ($request->user()?->getKey() ?? $request->ip()));
        });
    }
}
