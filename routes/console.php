<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retenção de privacidade: apaga consultas vencidas (prazo em config('bulai.consultation.retention_days')).
Schedule::command('consultations:purge-expired')->dailyAt('03:30')->onOneServer();

// Lembretes de dose e avisos de fim de tratamento (App\Services\Treatment\ReminderService); uma vez por dose, em um só servidor.
Schedule::command('treatment:send-reminders')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

// Saúde da IA local, do OCR e dos embeddings lida em segundo plano: o painel e o SSE só consultam o cache (App\Services\Health\ServiceHealthChecker).
Schedule::command('services:probe-health')->everyFiveSeconds()->onOneServer()->withoutOverlapping();
