<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('space-creation', fn (Request $request) => Limit::perHour(3)->by('space-create:'.$request->ip()));
        RateLimiter::for('call-writes', fn (Request $request) => Limit::perMinute(180)->by('call-write:'.$request->user()->id));
        RateLimiter::for('notification-writes', fn (Request $request) => Limit::perMinute(30)->by('notification-write:'.$request->user()->id));
        RateLimiter::for('note-writes', fn (Request $request) => Limit::perMinute(30)->by('note-write:'.$request->user()->id));
        RateLimiter::for('expense-writes', fn (Request $request) => Limit::perMinute(30)->by('expense-write:'.$request->user()->id));
        RateLimiter::for('challenge-writes', fn (Request $request) => Limit::perMinute(30)->by('challenge-write:'.$request->user()->id));
        RateLimiter::for('chat-writes', fn (Request $request) => Limit::perMinute(120)->by('chat-write:'.$request->user()->id));
        RateLimiter::for('avatar-writes', fn (Request $request) => Limit::perMinute(10)->by('avatar-write:'.$request->user()->id));
        RateLimiter::for('bucket-writes', fn (Request $request) => Limit::perMinute(20)->by('bucket-write:'.$request->user()->id));
        RateLimiter::for('memory-writes', fn (Request $request) => Limit::perMinute(20)->by('memory-write:'.$request->user()->id));
        RateLimiter::for('device-credentials', fn (Request $request) => [
            Limit::perMinute(5)->by('device-minute:'.$request->ip()),
            Limit::perHour(30)->by('device-hour:'.$request->ip()),
        ]);
    }
}
