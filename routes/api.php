<?php

use App\Http\Controllers\Internal\KnowledgeFileController;
use App\Http\Controllers\Internal\KnowledgeImportController;
use App\Http\Middleware\AuthenticateIngestionToken;
use Illuminate\Support\Facades\Route;

Route::prefix('internal')
    ->middleware([AuthenticateIngestionToken::class.':knowledge:ingest', 'throttle:knowledge-ingest'])
    ->group(function (): void {
        Route::post('/knowledge/import', KnowledgeImportController::class);
        Route::post('/knowledge/{document}/file', KnowledgeFileController::class);
    });
