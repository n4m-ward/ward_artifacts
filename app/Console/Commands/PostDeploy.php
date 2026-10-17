<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tarefas de pós-deploy. Na Hostinger o deploy é um git pull da main
 * disparado pelo GitHub, sem SSH: o cron roda o scheduler a cada minuto e
 * este comando só age quando o commit em produção muda.
 */
class PostDeploy extends Command
{
    protected $signature = 'ward:post-deploy {--force : Roda mesmo que o commit não tenha mudado}';

    protected $description = 'Aplica migrations e prepara o ambiente depois de um deploy';

    public function handle(): int
    {
        if (! config('ward.post_deploy') && ! $this->option('force')) {
            return self::SUCCESS;
        }

        $revision = $this->currentRevision();
        $appliedFile = storage_path('app/deploy-revision');

        if ($revision === null) {
            $this->warn('Não foi possível ler o commit atual (.git).');

            return self::FAILURE;
        }

        if (! $this->option('force') && File::exists($appliedFile) && trim(File::get($appliedFile)) === $revision) {
            return self::SUCCESS;
        }

        $this->info("Aplicando o deploy {$revision}");

        try {
            foreach (['app/private/artifacts', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
                File::ensureDirectoryExists(storage_path($dir));
            }

            // Chaves do OAuth: geradas uma vez no servidor, fora do git.
            if (! File::exists(storage_path('oauth-private.key'))) {
                $this->call('passport:keys', ['--no-interaction' => true]);
            }

            // Sem cache de config/rotas: depois de um pull o cache antigo ficaria
            // valendo até este comando rodar.
            $this->call('optimize:clear');
            $this->call('migrate', ['--force' => true]);

            if (User::query()->doesntExist() && filled(config('ward.admin.email'))) {
                $this->call('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true]);
            }
        } catch (Throwable $e) {
            Log::error("Pós-deploy {$revision} falhou: {$e->getMessage()}");
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        File::put($appliedFile, $revision);
        Log::info("Deploy {$revision} aplicado.");

        return self::SUCCESS;
    }

    /**
     * Commit do checkout atual, lido direto do .git (sem depender do binário git).
     */
    protected function currentRevision(): ?string
    {
        $git = base_path('.git');
        $head = @file_get_contents("{$git}/HEAD");

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (! str_starts_with($head, 'ref: ')) {
            return $head;
        }

        $ref = substr($head, 5);

        if (is_file("{$git}/{$ref}")) {
            return trim(file_get_contents("{$git}/{$ref}"));
        }

        foreach (@file("{$git}/packed-refs", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_ends_with($line, " {$ref}")) {
                return strtok($line, ' ');
            }
        }

        return null;
    }
}
