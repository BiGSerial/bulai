<?php

use App\Http\Controllers\Api\V1\EvaluationController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Internal\KnowledgeBatchController;
use App\Http\Controllers\Internal\KnowledgeFileController;
use App\Http\Controllers\Internal\KnowledgeImportController;
use App\Http\Controllers\Internal\KnowledgePendingItemAssociationController;
use App\Http\Middleware\AuthenticateIngestionToken;
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
        Route::get('/questions', [QuestionController::class, 'index'])->name('questions.index');
        Route::get('/questions/{uuid}', [QuestionController::class, 'show'])->whereUuid('uuid')->name('questions.show');
    });

    Route::middleware([AuthenticateIngestionToken::class.':'.IngestionToken::SCOPE_API_EVALUATE, 'throttle:api-evaluate'])->group(function (): void {
        Route::post('/evaluation/ask', [EvaluationController::class, 'ask'])->name('evaluation.ask');
    });
});
