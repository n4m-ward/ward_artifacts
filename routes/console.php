<?php

use App\Models\ArtifactPreview;
use App\Models\ArtifactUpload;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Remove links de upload e de pré-visualização vencidos há mais de um dia.
Schedule::command('model:prune', ['--model' => [ArtifactUpload::class, ArtifactPreview::class]])->daily();

// Pós-deploy na Hostinger (o cron chama o scheduler a cada minuto).
Schedule::command('ward:post-deploy')->everyMinute()->withoutOverlapping();
