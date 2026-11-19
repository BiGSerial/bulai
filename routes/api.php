<?php

use App\Http\Controllers\Api\Mobile\AccountController as MobileAccountController;
use App\Http\Controllers\Api\Mobile\AgendaController as MobileAgendaController;
use App\Http\Controllers\Api\Mobile\AuthController as MobileAuthController;
use App\Http\Controllers\Api\Mobile\CaregiverController as MobileCaregiverController;
use App\Http\Controllers\Api\Mobile\DeviceController as MobileDeviceController;
use App\Http\Controllers\Api\Mobile\InteractionController as MobileInteractionController;
use App\Http\Controllers\Api\Mobile\LeafletController as MobileLeafletController;
use App\Http\Controllers\Api\Mobile\MedicineController as MobileMedicineController;
use App\Http\Controllers\Api\Mobile\NoticeController as MobileNoticeController;
use App\Http\Controllers\Api\Mobile\PrescriptionController as MobilePrescriptionController;
use App\Http\Controllers\Api\Mobile\ProfileController as MobileProfileController;
use App\Http\Controllers\Api\Mobile\QuestionController as MobileQuestionController;
use App\Http\Controllers\Api\Mobile\TreatmentController as MobileTreatmentController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\EvaluationController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Internal\KnowledgeBatchController;
use App\Http\Controllers\Internal\KnowledgeFileController;
use App\Http\Controllers\Internal\KnowledgeImportController;
use App\Http\Controllers\Internal\KnowledgePendingItemAssociationController;
use App\Http\Middleware\AuthenticateIngestionToken;
use App\Http\Middleware\AuthenticateMobileDevice;
use App\Models\Knowledge\IngestionToken;
use Illuminate\Support\Facades\Route;

Route::prefix('internal')
    ->middleware([AuthenticateIngestionToken::class.':knowledge:ingest', 'throttle:knowledge-ingest'])
    ->group(function (): void {
        Route::post('/knowledge/import', KnowledgeImportController::class);
        Route::post('/knowledge/{document}/file', KnowledgeFileController::class);
        Route::post('/knowledge/batches', KnowledgeBatchController::class);
        Route::post('/knowledge/batches/{batch}/items/{item}/associate', KnowledgePendingItemAssociationController::class)
            ->scopeBindings();
    });

// API de estatística e avaliação (docs/api.md). Token emitido em Admin › Acesso à API; revogação vale na hora.
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware([AuthenticateIngestionToken::class.':'.implode(',', IngestionToken::API_SCOPES), 'throttle:api-read'])->group(function (): void {
        Route::get('/me', [StatsController::class, 'me'])->name('me');
        Route::get('/stats/overview', [StatsController::class, 'overview'])->name('stats.overview');
        Route::get('/stats/ai-usage', [StatsController::class, 'aiUsage'])->name('stats.ai-usage');
        Route::get('/stats/knowledge', [StatsController::class, 'knowledge'])->name('stats.knowledge');
        Route::get('/questions', [QuestionController::class, 'index'])->name('questions.index');
        Route::get('/questions/{uuid}', [QuestionController::class, 'show'])->whereUuid('uuid')->name('questions.show');

        // Auditoria da aplicação para o TCC (docs/api.md › Auditoria).
        Route::prefix('audit')->name('audit.')->group(function (): void {
            Route::get('/tcc', [AuditController::class, 'tcc'])->name('tcc');
            Route::get('/pipeline', [AuditController::class, 'pipeline'])->name('pipeline');
            Route::get('/embeddings', [AuditController::class, 'embeddings'])->name('embeddings');
            Route::get('/quality', [AuditController::class, 'quality'])->name('quality');
            Route::get('/documents', [AuditController::class, 'documents'])->name('documents');
            Route::get('/documents/{documentId}/pdf', [AuditController::class, 'pdf'])->whereNumber('documentId')->name('documents.pdf');
            Route::get('/readability', [AuditController::class, 'readability'])->name('readability');
            Route::get('/evaluations', [AuditController::class, 'evaluations'])->name('evaluations');
            Route::get('/evaluations/{runId}', [AuditController::class, 'evaluation'])->where('runId', '[0-9a-f-]{8,36}')->name('evaluations.show');
            // Consultas para analisar o sistema sem tinker na VPS (auditoria de 16/11/2026).
            Route::get('/catalog', [AuditController::class, 'catalog'])->name('catalog');
            Route::get('/ingredients', [AuditController::class, 'ingredients'])->name('ingredients');
            Route::get('/interaction', [AuditController::class, 'interaction'])->name('interaction');
            Route::get('/leaflet-summaries', [AuditController::class, 'leafletSummaries'])->name('leaflet-summaries');
            Route::get('/documents/{documentId}/chunks', [AuditController::class, 'chunks'])->whereNumber('documentId')->name('documents.chunks');
        });
    });

    Route::middleware([AuthenticateIngestionToken::class.':'.IngestionToken::SCOPE_API_EVALUATE, 'throttle:api-evaluate'])->group(function (): void {
        Route::post('/evaluation/ask', [EvaluationController::class, 'ask'])->name('evaluation.ask');
    });
});

// Aplicativo Android nativo (docs/app-android.md). Conta: e-mail e senha geram o token do aparelho (Bearer bulai_app_…).
Route::prefix('mobile/v1')->name('api.mobile.')->group(function (): void {
    Route::post('/entrar', [MobileAuthController::class, 'login'])->middleware('throttle:mobile-login')->name('login');
    Route::post('/cadastro', [MobileAuthController::class, 'register'])->middleware('throttle:mobile-register')->name('register');
    Route::post('/esqueci-senha', [MobileAuthController::class, 'forgotPassword'])->middleware('throttle:mobile-password-reset')->name('password.forgot');

    // Também com a senha provisória pendente: ver a conta, trocar a senha e sair.
    Route::middleware([AuthenticateMobileDevice::class.':'.AuthenticateMobileDevice::FIRST_ACCESS, 'throttle:mobile-api'])->group(function (): void {
        Route::get('/eu', [MobileAuthController::class, 'me'])->name('me');
        Route::post('/primeiro-acesso', [MobileAuthController::class, 'firstAccess'])->name('first-access');
        Route::delete('/aparelho', [MobileDeviceController::class, 'destroy'])->name('device.destroy');
    });

    Route::middleware([AuthenticateMobileDevice::class, 'throttle:mobile-api'])->group(function (): void {
        // Alarmes do aparelho (sincronização em segundo plano) e marcação pela notificação/despertador/widget.
        Route::get('/agenda', [MobileAgendaController::class, 'index'])->name('agenda');
        Route::post('/doses', [MobileAgendaController::class, 'mark'])->name('doses.mark');

        Route::get('/inicio', [MobileTreatmentController::class, 'home'])->name('home');
        Route::get('/dia', [MobileTreatmentController::class, 'day'])->name('day');
        Route::post('/dia/doses', [MobileTreatmentController::class, 'mark'])->name('day.mark');
        Route::delete('/dia/doses', [MobileTreatmentController::class, 'unmark'])->name('day.unmark');
        Route::post('/dia/se-necessario', [MobileTreatmentController::class, 'takeAsNeeded'])->name('day.as-needed');

        Route::get('/avisos', [MobileNoticeController::class, 'index'])->name('notices');
        Route::get('/avisos/novos', [MobileNoticeController::class, 'fresh'])->name('notices.fresh');
        Route::post('/avisos/lidos', [MobileNoticeController::class, 'markAllRead'])->name('notices.read-all');
        Route::put('/avisos/preferencias', [MobileNoticeController::class, 'preferences'])->name('notices.preferences');
        Route::delete('/avisos', [MobileNoticeController::class, 'destroyAll'])->name('notices.destroy-all');
        Route::post('/avisos/{notice}/abrir', [MobileNoticeController::class, 'open'])->whereUuid('notice')->name('notices.open');
        Route::delete('/avisos/{notice}', [MobileNoticeController::class, 'destroy'])->whereUuid('notice')->name('notices.destroy');

        Route::get('/remedios', [MobileMedicineController::class, 'search'])->name('medicines.search');

        // Quem cuida de mim (paciente): convite com QR Code, aprovar/recusar com permissões, mudar, tirar acesso.
        Route::get('/cuidadores', [MobileCaregiverController::class, 'index'])->name('caregivers');
        Route::post('/cuidadores/convite', [MobileCaregiverController::class, 'invite'])->name('caregivers.invite');
        Route::delete('/cuidadores/convite', [MobileCaregiverController::class, 'cancelInvite'])->name('caregivers.invite.cancel');
        Route::post('/cuidadores/{link}/aprovar', [MobileCaregiverController::class, 'approve'])->whereNumber('link')->name('caregivers.approve');
        Route::post('/cuidadores/{link}/recusar', [MobileCaregiverController::class, 'reject'])->whereNumber('link')->name('caregivers.reject');
        Route::put('/cuidadores/{link}/permissoes', [MobileCaregiverController::class, 'updatePermissions'])->whereNumber('link')->name('caregivers.permissions');
        Route::delete('/cuidadores/{link}', [MobileCaregiverController::class, 'revoke'])->whereNumber('link')->name('caregivers.revoke');

        // Cuidar de alguém (quem cuida): ler o QR/código, escolher a pessoa, ver e marcar as doses dela, deixar de acompanhar.
        Route::get('/cuidando', [MobileCaregiverController::class, 'caring'])->name('caring');
        Route::post('/cuidando', [MobileCaregiverController::class, 'accept'])->middleware('throttle:mobile-password-change')->name('caring.accept');
        Route::delete('/cuidando/{link}', [MobileCaregiverController::class, 'leave'])->whereNumber('link')->name('caring.leave');
        Route::get('/cuidando/{link}/dia', [MobileCaregiverController::class, 'day'])->whereNumber('link')->name('caring.day');
        Route::post('/cuidando/{link}/dia/doses', [MobileCaregiverController::class, 'mark'])->whereNumber('link')->name('caring.mark');
        Route::delete('/cuidando/{link}/dia/doses', [MobileCaregiverController::class, 'unmark'])->whereNumber('link')->name('caring.unmark');
        Route::post('/cuidando/{link}/dia/se-necessario', [MobileCaregiverController::class, 'takeAsNeeded'])->whereNumber('link')->name('caring.as-needed');

        Route::get('/cuidando/{link}/remedios', [MobileCaregiverController::class, 'medications'])->whereNumber('link')->name('caring.medications');
        Route::put('/cuidando/{link}/remedios/{medication}', [MobileCaregiverController::class, 'updateMedication'])->whereNumber(['link', 'medication'])->name('caring.medications.update');

        // Meus remédios (perfil de saúde): consentimento, remédios com dose e horários, receitas e alergias.
        Route::get('/perfil', [MobileProfileController::class, 'show'])->name('profile');
        Route::post('/perfil/consentimento', [MobileProfileController::class, 'consent'])->name('profile.consent');
        Route::delete('/perfil/dados-de-saude', [MobileProfileController::class, 'deleteHealthData'])->name('profile.health-data.destroy');
        Route::post('/perfil/remedios', [MobileProfileController::class, 'addMedication'])->name('profile.medications.store');
        Route::put('/perfil/remedios/{medication}', [MobileProfileController::class, 'updateMedication'])->whereNumber('medication')->name('profile.medications.update');
        Route::delete('/perfil/remedios/{medication}', [MobileProfileController::class, 'removeMedication'])->whereNumber('medication')->name('profile.medications.destroy');
        Route::post('/perfil/receitas', [MobileProfileController::class, 'addPrescription'])->name('profile.prescriptions.store');
        Route::delete('/perfil/receitas/{prescription}', [MobileProfileController::class, 'removePrescription'])->whereNumber('prescription')->name('profile.prescriptions.destroy');
        Route::post('/perfil/alergias', [MobileProfileController::class, 'addAllergy'])->name('profile.allergies.store');
        Route::delete('/perfil/alergias/{allergy}', [MobileProfileController::class, 'removeAllergy'])->whereNumber('allergy')->name('profile.allergies.destroy');
        Route::post('/posologia/sugestao', [MobileProfileController::class, 'suggestSchedule'])->name('posology.suggest');

        // Interações e lista de bulas simples.
        Route::get('/interacoes', [MobileInteractionController::class, 'index'])->name('interactions');
        Route::get('/interacoes/remedios/{medicine}', [MobileInteractionController::class, 'medicine'])->whereNumber('medicine')->name('interactions.medicine');
        Route::post('/interacoes', [MobileInteractionController::class, 'store'])->name('interactions.store');
        Route::get('/interacoes/{uuid}', [MobileInteractionController::class, 'show'])->whereUuid('uuid')->name('interactions.show');
        Route::post('/interacoes/{uuid}/repetir', [MobileInteractionController::class, 'repeat'])->whereUuid('uuid')->name('interactions.repeat');
        Route::delete('/interacoes/{uuid}', [MobileInteractionController::class, 'destroy'])->whereUuid('uuid')->name('interactions.destroy');
        Route::get('/bulas', [MobileInteractionController::class, 'leaflets'])->name('leaflets');

        Route::get('/resumo.pdf', [MobileCaregiverController::class, 'summary'])->name('summary');

        // Minha conta: nome de chamada e troca de senha (este aparelho continua conectado).
        Route::get('/conta', [MobileAccountController::class, 'show'])->name('account');
        Route::put('/conta/nome', [MobileAccountController::class, 'updateName'])->name('account.name');
        Route::put('/conta/senha', [MobileAccountController::class, 'updatePassword'])->middleware('throttle:mobile-password-change')->name('account.password');
        Route::delete('/conta', [MobileAccountController::class, 'destroy'])->middleware('throttle:mobile-password-change')->name('account.destroy');

        // Bula humanizada: GET responde na hora (pronta ou "carregando"); POST …/gerar prepara o resumo (limite por hora do site).
        Route::get('/bulas/{medicine}', [MobileLeafletController::class, 'show'])->whereNumber('medicine')->name('leaflets.show');
        Route::post('/bulas/{medicine}/gerar', [MobileLeafletController::class, 'generate'])->whereNumber('medicine')->name('leaflets.generate');

        // Perguntar: a conversa vive no aparelho (só os uuid das trocas); o limite por hora é o mesmo do site.
        Route::get('/perguntar', [MobileQuestionController::class, 'start'])->name('questions.start');
        Route::post('/perguntar', [MobileQuestionController::class, 'ask'])->name('questions.ask');
        Route::get('/perguntas', [MobileQuestionController::class, 'history'])->name('questions.history');
        Route::get('/perguntas/{question}', [MobileQuestionController::class, 'show'])->whereUuid('question')->name('questions.show');
        Route::delete('/perguntas/{question}', [MobileQuestionController::class, 'destroy'])->whereUuid('question')->name('questions.destroy');

        // Receita por foto: envio, leitura (consulta periódica), horários sugeridos, confirmação e descarte.
        Route::post('/receitas', [MobilePrescriptionController::class, 'store'])->name('prescriptions.store');
        Route::post('/receitas/horarios', [MobilePrescriptionController::class, 'schedule'])->name('prescriptions.schedule');
        Route::get('/receitas/{prescription}', [MobilePrescriptionController::class, 'show'])->whereNumber('prescription')->name('prescriptions.show');
        Route::post('/receitas/{prescription}/confirmar', [MobilePrescriptionController::class, 'confirm'])->whereNumber('prescription')->name('prescriptions.confirm');
        Route::delete('/receitas/{prescription}', [MobilePrescriptionController::class, 'destroy'])->whereNumber('prescription')->name('prescriptions.destroy');
    });
});
