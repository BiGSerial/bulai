<?php

namespace App\Providers;

use App\Services\Knowledge\PdfTextExtractor;
use App\Services\Knowledge\PrinsFrankPdfTextExtractor;
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
        $this->app->bind(PdfTextExtractor::class, PrinsFrankPdfTextExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('knowledge-ingest', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
