<?php

namespace App\Providers;

use App\Http\Middleware\EnsureTemporaryPasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
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
use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

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
        Event::listen(Looping::class, fn () => app(WorkerHeartbeat::class)->beat());
        Event::listen(JobProcessing::class, fn () => app(WorkerHeartbeat::class)->beat(busy: true));
        Event::listen([JobProcessed::class, JobFailed::class], fn () => app(WorkerHeartbeat::class)->beat(force: true));

        RateLimiter::for('knowledge-ingest', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Ao exceder, volta ao formulário de login com a mensagem no campo de e-mail (em vez da página 429).
        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perMinute(5)
                ->by((is_string($email) ? Str::lower(trim($email)) : '').'|'.$request->ip())
                ->response(fn (Request $request, array $headers): RedirectResponse => redirect()
                    ->route('login')
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => __('auth.throttle', ['seconds' => $headers['Retry-After'] ?? 60])]));
        });

        Password::defaults(fn (): Password => Password::min(12)->letters()->numbers());

        // Ações Livewire (POST no endpoint de update) reaplicam estes middlewares da rota original:
        // um ADMIN rebaixado, desativado ou com troca de senha pendente não age numa tela já aberta.
        Livewire::addPersistentMiddleware([
            EnsureUserIsActive::class,
            EnsureUserIsAdmin::class,
            EnsureTemporaryPasswordIsChanged::class,
        ]);
    }
}
