<?php

use App\Models\Artifact;
use App\Models\ArtifactPreview;
use App\Models\ArtifactUpload;
use App\Models\WhiteboardPresence;
use App\Whiteboards\WhiteboardStore;
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

// Whiteboards: elementos apagados há mais de ward.whiteboards.purge_deleted_days saem do banco,
// e presenças velhas (quem fechou o quadro sem avisar) também.
Artisan::command('ward:purge-whiteboards', function (WhiteboardStore $store) {
    $removed = 0;
    Artifact::where('type', 'whiteboard')->each(function (Artifact $board) use ($store, &$removed) {
        $removed += $store->purge($board);
    });
    WhiteboardPresence::where('seen_at', '<', now()->subHour())->delete();
    $this->info("{$removed} elemento(s) apagado(s) removido(s).");
})->purpose('Limpa elementos apagados e presenças velhas dos whiteboards');

Schedule::command('ward:purge-whiteboards')->daily();
