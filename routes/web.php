<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Livewire\Admin\Documents\Index as AdminDocumentIndex;
use App\Livewire\Admin\Documents\Show as AdminDocumentShow;
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
        Route::get('/documents', AdminDocumentIndex::class)->name('documents.index');
        Route::get('/documents/{document}', AdminDocumentShow::class)->name('documents.show');
    });
