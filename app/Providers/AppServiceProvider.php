<?php

namespace App\Providers;

use App\Http\Middleware\EnsureTemporaryPasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Notifications\Channels\DedupedDatabaseChannel;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiUsageAttribution;
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
use App\Services\Mail\KingHostSmtpTransport;
use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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

    /** Mensagem de bloqueio com o tempo restante em segundos ou minutos. */
    public static function throttleMessage(int $seconds): string
    {
        if ($seconds >= 120) {
            return __('auth.throttle_minutes', ['minutes' => (int) ceil($seconds / 60)]);
        }

        return __('auth.throttle', ['seconds' => $seconds]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Avisos no aplicativo com chave de deduplicação (lembretes de dose nunca duplicam).
        Notification::extend('database', fn (): DedupedDatabaseChannel => new DedupedDatabaseChannel);

        // E-mail transacional pela API da KingHost (MAIL_MAILER=kinghost).
        Mail::extend('kinghost', fn (): KingHostSmtpTransport => new KingHostSmtpTransport(
            token: (string) config('services.kinghost_smtp.token'),
            baseUrl: (string) config('services.kinghost_smtp.url'),
            timeoutSeconds: (int) config('services.kinghost_smtp.timeout'),
        ));

        // Consumo de IA disparado por comando (avaliações, importações) fica com a origem artisan:<comando>. O worker e o
        // agendador ficam de fora: cada job traz a origem de quem o despachou.
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command !== null && ! Str::startsWith($event->command, ['queue:', 'schedule:'])) {
                AiUsageAttribution::remember(null, 'artisan:'.$event->command);
            }
        });

        Event::listen(Looping::class, fn () => app(WorkerHeartbeat::class)->beat());
        Event::listen(JobProcessing::class, fn () => app(WorkerHeartbeat::class)->beat(busy: true));
        Event::listen([JobProcessed::class, JobFailed::class], fn () => app(WorkerHeartbeat::class)->beat(force: true));

        RateLimiter::for('knowledge-ingest', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        // API /v1: o limitador roda antes do middleware do token, então conta pelo hash do token enviado, com um teto por IP
        // para quem troca de token a cada chamada. Avaliação chama o modelo de verdade, então é bem mais curta.
        $apiKey = fn (Request $request): string => hash('sha256', (string) $request->bearerToken());
        RateLimiter::for('api-read', fn (Request $request): array => [
            Limit::perMinute(60)->by('api:'.$apiKey($request)),
            Limit::perMinute(120)->by('api-ip:'.$request->ip()),
        ]);
        RateLimiter::for('api-evaluate', fn (Request $request): array => [
            Limit::perMinute(6)->by('api-eval:'.$apiKey($request)),
            Limit::perDay(300)->by('api-eval-day:'.$apiKey($request)),
            Limit::perMinute(12)->by('api-eval-ip:'.$request->ip()),
        ]);

        // Bloqueio progressivo do login por e-mail + IP: 5 tentativas por minuto e 20 por 15 minutos. Ao exceder,
        // volta ao formulário com a mensagem (e o tempo restante) no campo de e-mail, em vez da página 429.
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $key = (is_string($email) ? Str::lower(trim($email)) : '').'|'.$request->ip();
            $response = fn (Request $request, array $headers): RedirectResponse => redirect()
                ->route('login')
                ->withInput($request->only('email'))
                ->withErrors(['email' => self::throttleMessage((int) ($headers['Retry-After'] ?? 60))]);

            return [
                Limit::perMinute(5)->by($key)->response($response),
                Limit::perMinutes(15, 20)->by($key)->response($response),
            ];
        });

        // Pedidos de link de redefinição: 3 por minuto por e-mail + IP (além do throttle do broker).
        RateLimiter::for('password-reset', function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perMinute(3)
                ->by((is_string($email) ? Str::lower(trim($email)) : '').'|'.$request->ip())
                ->response(fn (Request $request, array $headers): RedirectResponse => redirect()
                    ->route('password.request')
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => self::throttleMessage((int) ($headers['Retry-After'] ?? 60))]));
        });

        // Cadastro pelo site: 3 envios por minuto e 10 por hora por IP (contém robôs e varredura de e-mails cadastrados).
        RateLimiter::for('register', function (Request $request): array {
            $response = fn (Request $request, array $headers): RedirectResponse => redirect()
                ->route('register')
                ->withInput($request->only('name', 'email'))
                ->withErrors(['email' => self::throttleMessage((int) ($headers['Retry-After'] ?? 60))]);

            return [
                Limit::perMinute(3)->by('register|'.$request->ip())->response($response),
                Limit::perHour(10)->by('register-hour|'.$request->ip())->response($response),
            ];
        });

        // Política de senha: 12+ caracteres com letras e números; em produção, também não constar em vazamentos conhecidos.
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->letters()->numbers()->uncompromised()
            : Password::min(12)->letters()->numbers());

        // Ações Livewire (POST no endpoint de update) reaplicam estes middlewares da rota original:
        // um ADMIN rebaixado, desativado ou com troca de senha pendente não age numa tela já aberta.
        Livewire::addPersistentMiddleware([
            EnsureUserIsActive::class,
            EnsureUserIsAdmin::class,
            EnsureTemporaryPasswordIsChanged::class,
        ]);
    }
}
