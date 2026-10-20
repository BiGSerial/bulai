<?php

namespace App\Providers;

use App\Services\Ai\AiProviderManager;
use App\Services\Ai\DocumentClassificationProvider;
use App\Services\Ai\EmbeddingProvider;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Ai\VisionProvider;
use App\Services\Knowledge\AnvisaRegulatoryCatalog;
use App\Services\Knowledge\DocumentExtractionQualityEvaluator;
use App\Services\Knowledge\DocumentOcrClient;
use App\Services\Knowledge\EloquentAnvisaRegulatoryCatalog;
use App\Services\Knowledge\HeuristicExtractionQualityEvaluator;
use App\Services\Knowledge\HttpDocumentOcrClient;
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
        $this->app->singleton(AiProviderManager::class);
        $this->app->bind(TextGenerationProvider::class, fn ($app): TextGenerationProvider => $app->make(AiProviderManager::class)->text());
        $this->app->bind(EmbeddingProvider::class, fn ($app): EmbeddingProvider => $app->make(AiProviderManager::class)->embedding());
        $this->app->bind(DocumentClassificationProvider::class, fn ($app): DocumentClassificationProvider => $app->make(AiProviderManager::class)->classifier());
        $this->app->bind(VisionProvider::class, fn ($app): VisionProvider => $app->make(AiProviderManager::class)->vision());
        $this->app->bind(DocumentExtractionQualityEvaluator::class, HeuristicExtractionQualityEvaluator::class);
        $this->app->bind(DocumentOcrClient::class, fn (): DocumentOcrClient => match (config('document_ocr.driver')) {
            'http' => new HttpDocumentOcrClient,
            default => throw new \InvalidArgumentException('Unsupported document OCR driver.'),
        });
        $this->app->bind(AnvisaRegulatoryCatalog::class, EloquentAnvisaRegulatoryCatalog::class);
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
