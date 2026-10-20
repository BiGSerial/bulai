<?php

use App\Http\Controllers\Admin\KnowledgeBatchItemFileController;
use App\Http\Controllers\Admin\ServiceHealthController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Livewire\Admin\Documents\Create as AdminDocumentCreate;
use App\Livewire\Admin\Documents\Index as AdminDocumentIndex;
use App\Livewire\Admin\Documents\Show as AdminDocumentShow;
use App\Livewire\Admin\Knowledge\Batches\Index as KnowledgeBatchIndex;
use App\Livewire\Admin\Knowledge\Batches\Show as KnowledgeBatchShow;
use App\Livewire\Admin\Knowledge\Catalog\Index as KnowledgeCatalogIndex;
use App\Livewire\Admin\Knowledge\Dashboard as KnowledgeDashboard;
use App\Livewire\Admin\Knowledge\Pending\Index as KnowledgePendingIndex;
use App\Livewire\Admin\Knowledge\Pending\Review as KnowledgePendingReview;
use App\Livewire\Admin\Knowledge\Sources\Form as KnowledgeSourceForm;
use App\Livewire\Admin\Knowledge\Sources\Index as KnowledgeSourceIndex;
use App\Livewire\Admin\Knowledge\Sources\Show as KnowledgeSourceShow;
use App\Livewire\Admin\Knowledge\Upload as KnowledgeUpload;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.documents.index');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'admin'])
    ->group(function (): void {
        Route::get('/knowledge', KnowledgeDashboard::class)->name('knowledge.dashboard');
        Route::get('/knowledge/services/health', ServiceHealthController::class)->name('knowledge.services.health');
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
        Route::get('/knowledge/pending', KnowledgePendingIndex::class)->name('knowledge.pending.index');
        Route::get('/knowledge/batches/{batch}/items/{item}/review', KnowledgePendingReview::class)
            ->scopeBindings()
            ->name('knowledge.pending.review');
        Route::get('/knowledge/batches/{batch}/items/{item}/file', [KnowledgeBatchItemFileController::class, 'inline'])->name('knowledge.items.file');
        Route::get('/knowledge/batches/{batch}/items/{item}/download', [KnowledgeBatchItemFileController::class, 'download'])->name('knowledge.items.download');

        // Compatibility aliases for existing bookmarks and tests.
        Route::get('/documents', AdminDocumentIndex::class)->name('documents.index');
        Route::get('/documents/{document}', AdminDocumentShow::class)->name('documents.show');
    });
