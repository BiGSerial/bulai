<?php

use App\Http\Controllers\Admin\KnowledgeBatchItemFileController;
use App\Http\Controllers\Admin\KnowledgeStreamController;
use App\Http\Controllers\Admin\ReportDownloadController;
use App\Http\Controllers\Admin\ServiceHealthController;
use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\FirstAccessController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Middleware\EnsureTemporaryPasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Livewire\Admin\Analytics\AiUsage\Index as AnalyticsAiUsageIndex;
use App\Livewire\Admin\Analytics\AiUsage\Show as AnalyticsAiUsageShow;
use App\Livewire\Admin\Analytics\Consultations\Index as AnalyticsConsultationIndex;
use App\Livewire\Admin\Analytics\Consultations\Show as AnalyticsConsultationShow;
use App\Livewire\Admin\Analytics\Dashboard as AnalyticsDashboard;
use App\Livewire\Admin\Analytics\Leaflets\Index as AnalyticsLeafletIndex;
use App\Livewire\Admin\Analytics\Leaflets\Show as AnalyticsLeafletShow;
use App\Livewire\Admin\Analytics\Questions\Index as AnalyticsQuestionIndex;
use App\Livewire\Admin\Analytics\Questions\Show as AnalyticsQuestionShow;
use App\Livewire\Admin\Documents\Create as AdminDocumentCreate;
use App\Livewire\Admin\Documents\Index as AdminDocumentIndex;
use App\Livewire\Admin\Documents\Show as AdminDocumentShow;
use App\Livewire\Admin\Knowledge\Batches\Index as KnowledgeBatchIndex;
use App\Livewire\Admin\Knowledge\Batches\Show as KnowledgeBatchShow;
use App\Livewire\Admin\Knowledge\Catalog\Index as KnowledgeCatalogIndex;
use App\Livewire\Admin\Knowledge\Dashboard as KnowledgeDashboard;
use App\Livewire\Admin\Knowledge\Interactions\Index as KnowledgeInteractionsIndex;
use App\Livewire\Admin\Knowledge\Pending\Index as KnowledgePendingIndex;
use App\Livewire\Admin\Knowledge\Pending\Review as KnowledgePendingReview;
use App\Livewire\Admin\Knowledge\QueueStatus as KnowledgeQueueStatus;
use App\Livewire\Admin\Knowledge\Sources\Form as KnowledgeSourceForm;
use App\Livewire\Admin\Knowledge\Sources\Index as KnowledgeSourceIndex;
use App\Livewire\Admin\Knowledge\Sources\Show as KnowledgeSourceShow;
use App\Livewire\Admin\Knowledge\UnansweredQuestions\Index as KnowledgeUnansweredQuestionsIndex;
use App\Livewire\Admin\Knowledge\UnmatchedMedicines\Index as KnowledgeUnmatchedMedicinesIndex;
use App\Livewire\Admin\Knowledge\Upload as KnowledgeUpload;
use App\Livewire\Admin\Reports\Index as AdminReportIndex;
use App\Livewire\Admin\Users\Form as UserForm;
use App\Livewire\Admin\Users\Index as UserIndex;
use App\Livewire\Admin\Users\Show as UserShow;
use App\Livewire\Consulta\Avisos as ConsultaAvisos;
use App\Livewire\Consulta\Bula as ConsultaBula;
use App\Livewire\Consulta\Historico as ConsultaHistorico;
use App\Livewire\Consulta\Inicio as ConsultaInicio;
use App\Livewire\Consulta\Perfil as ConsultaPerfil;
use App\Livewire\Consulta\Perguntar as ConsultaPerguntar;
use App\Livewire\Consulta\PerguntaRegistrada as ConsultaPerguntaRegistrada;
use App\Livewire\Consulta\Pesquisa as ConsultaPesquisa;
use App\Livewire\Consulta\Receita\Nova as ConsultaReceitaNova;
use App\Livewire\Consulta\Receita\Revisar as ConsultaReceitaRevisar;
use App\Livewire\Consulta\Resultado as ConsultaResultado;
use App\Livewire\Consulta\Tratamento\Hoje as ConsultaTratamentoHoje;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    if ($request->user() === null) {
        return view('welcome');
    }

    return redirect()->route($request->user()->homeRouteName());
});

Route::view('/offline', 'offline')->name('offline');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.update');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Os middlewares de estado da conta são referenciados pela classe (bootstrap/app.php não é alterado).
Route::middleware(['auth', EnsureUserIsActive::class])->group(function (): void {
    Route::get('/first-access', [FirstAccessController::class, 'edit'])->name('first-access');
    Route::put('/first-access/password', [FirstAccessController::class, 'updatePassword'])->name('first-access.password.update');
});

Route::middleware(['auth', EnsureUserIsActive::class, EnsureTemporaryPasswordIsChanged::class])->group(function (): void {
    Route::get('/account', [AccountController::class, 'edit'])->name('account');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
    Route::post('/account/sessions/terminate', [AccountController::class, 'terminateOtherSessions'])->name('account.sessions.terminate');
    Route::get('/account/history', [AccountController::class, 'history'])->name('account.history');
});

// Área do usuário final (PWA de consulta): qualquer conta ativa; dados sempre filtrados pelo próprio usuário.
Route::middleware(['auth', EnsureUserIsActive::class, EnsureTemporaryPasswordIsChanged::class])->group(function (): void {
    // Web Push: inscrição do aparelho e o texto do aviso mostrado pelo service worker.
    Route::post('/push/inscrever', [PushSubscriptionController::class, 'store'])->name('push.store');
    Route::delete('/push/inscrever', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');
    Route::get('/push/ultimo', [PushSubscriptionController::class, 'latest'])->name('push.latest');
    Route::get('/inicio', ConsultaInicio::class)->name('consulta.inicio');
    Route::view('/sobre', 'consulta.sobre')->name('consulta.sobre');
    Route::get('/consulta', ConsultaPesquisa::class)->name('consulta.pesquisa');
    Route::get('/consulta/{consultation}', ConsultaResultado::class)->name('consulta.resultado');
    Route::get('/historico', ConsultaHistorico::class)->name('consulta.historico');
    Route::get('/perguntar', ConsultaPerguntar::class)->name('consulta.perguntar');
    Route::get('/perfil', ConsultaPerfil::class)->name('consulta.perfil');
    Route::get('/historico/perguntas/{question}', ConsultaPerguntaRegistrada::class)->name('consulta.pergunta');
    Route::get('/bula/{medicine}', ConsultaBula::class)->name('consulta.bula');
    Route::get('/receitas/nova', ConsultaReceitaNova::class)->name('consulta.receita.nova');
    Route::get('/receitas/{prescription}/revisar', ConsultaReceitaRevisar::class)->name('consulta.receita.revisar');
    // Programação do tratamento e avisos (quinta onda).
    Route::get('/hoje', ConsultaTratamentoHoje::class)->name('consulta.hoje');
    Route::get('/avisos', ConsultaAvisos::class)->name('consulta.avisos');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', EnsureUserIsActive::class, 'admin', EnsureTemporaryPasswordIsChanged::class])
    ->group(function (): void {
        Route::get('/knowledge', KnowledgeDashboard::class)->name('knowledge.dashboard');
        Route::get('/knowledge/services/health', ServiceHealthController::class)->name('knowledge.services.health');
        Route::get('/knowledge/stream', KnowledgeStreamController::class)->name('knowledge.stream');
        Route::get('/knowledge/queue', KnowledgeQueueStatus::class)->name('knowledge.queue');
        Route::get('/knowledge/upload', KnowledgeUpload::class)->name('knowledge.upload');
        Route::get('/knowledge/sources', KnowledgeSourceIndex::class)->name('knowledge.sources.index');
        Route::get('/knowledge/sources/create', KnowledgeSourceForm::class)->name('knowledge.sources.create');
        Route::get('/knowledge/sources/{source}', KnowledgeSourceShow::class)->name('knowledge.sources.show');
        Route::get('/knowledge/sources/{source}/edit', KnowledgeSourceForm::class)->name('knowledge.sources.edit');
        Route::get('/knowledge/documents', AdminDocumentIndex::class)->name('knowledge.documents.index');
        Route::get('/knowledge/documents/create', AdminDocumentCreate::class)->name('knowledge.documents.create');
        Route::get('/knowledge/documents/{document}', AdminDocumentShow::class)->name('knowledge.documents.show');
        Route::get('/knowledge/batches', KnowledgeBatchIndex::class)->name('knowledge.batches.index');
        Route::get('/knowledge/batches/{batch}', KnowledgeBatchShow::class)->name('knowledge.batches.show');
        Route::get('/knowledge/catalog', KnowledgeCatalogIndex::class)->name('knowledge.catalog.index');
        Route::get('/knowledge/interactions', KnowledgeInteractionsIndex::class)->name('knowledge.interactions.index');
        Route::get('/knowledge/pending', KnowledgePendingIndex::class)->name('knowledge.pending.index');
        Route::get('/knowledge/unmatched-medicines', KnowledgeUnmatchedMedicinesIndex::class)->name('knowledge.unmatched.index');
        Route::get('/knowledge/unanswered-questions', KnowledgeUnansweredQuestionsIndex::class)->name('knowledge.unanswered.index');
        Route::get('/knowledge/batches/{batch}/items/{item}/review', KnowledgePendingReview::class)
            ->scopeBindings()
            ->name('knowledge.pending.review');
        Route::get('/knowledge/batches/{batch}/items/{item}/file', [KnowledgeBatchItemFileController::class, 'inline'])->name('knowledge.items.file');
        Route::get('/knowledge/batches/{batch}/items/{item}/download', [KnowledgeBatchItemFileController::class, 'download'])->name('knowledge.items.download');
        Route::get('/users', UserIndex::class)->name('users.index');
        Route::get('/users/create', UserForm::class)->name('users.create');
        Route::get('/users/{user}', UserShow::class)->name('users.show');
        Route::get('/users/{user}/edit', UserForm::class)->name('users.edit');

        // Analytics e auditoria das respostas da IA (dados anonimizados; só métricas técnicas e evidências).
        Route::get('/analytics', AnalyticsDashboard::class)->name('analytics.index');
        Route::get('/analytics/consultas', AnalyticsConsultationIndex::class)->name('analytics.consultations.index');
        Route::get('/analytics/consultas/{consultation:uuid}', AnalyticsConsultationShow::class)->name('analytics.consultations.show');
        Route::get('/analytics/perguntas', AnalyticsQuestionIndex::class)->name('analytics.questions.index');
        Route::get('/analytics/perguntas/{question:uuid}', AnalyticsQuestionShow::class)->name('analytics.questions.show');
        Route::get('/analytics/bulas', AnalyticsLeafletIndex::class)->name('analytics.leaflets.index');
        Route::get('/analytics/bulas/{summary:uuid}', AnalyticsLeafletShow::class)->name('analytics.leaflets.show');
        // Consumo de IA por provedor e por usuário (base para planos de preço).
        Route::get('/analytics/consumo-ia', AnalyticsAiUsageIndex::class)->name('analytics.ai-usage.index');
        Route::get('/analytics/consumo-ia/usuarios/{user}', AnalyticsAiUsageShow::class)->name('analytics.ai-usage.show');

        // Relatórios gerados sob demanda (App\Services\Reports\ReportGenerator).
        Route::get('/relatorios', AdminReportIndex::class)->name('reports.index');
        Route::get('/relatorios/{report:uuid}/baixar', ReportDownloadController::class)->name('reports.download');

        // Compatibility aliases for existing bookmarks and tests.
        Route::get('/documents', AdminDocumentIndex::class)->name('documents.index');
        Route::get('/documents/{document}', AdminDocumentShow::class)->name('documents.show');
    });
