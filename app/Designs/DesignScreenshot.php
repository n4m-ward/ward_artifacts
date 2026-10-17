<?php

namespace App\Designs;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\DesignBoard;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Prints para a IA conferir o próprio trabalho. Um Chrome headless no servidor
 * abre a mesma URL que o canvas usa (o artboard no host de conteúdo, ou o
 * canvas inteiro em modo estático) e devolve um JPEG.
 *
 * Um print por vez no servidor: a hospedagem compartilhada limita processos.
 */
class DesignScreenshot
{
    public function __construct(private ContentGateway $gateway) {}

    public function available(): bool
    {
        $chrome = config('ward.designs.screenshots.chrome');

        return is_string($chrome) && $chrome !== '' && is_file($chrome) && is_executable($chrome);
    }

    /**
     * Um artboard sozinho, no tamanho dele (com escala de tela retina quando cabe).
     */
    public function board(DesignBoard $board): Screenshot
    {
        $config = config('ward.designs.screenshots');
        $scale = min(2, $config['board_max_width'] / $board->w, $config['board_max_height'] / $board->h);

        return $this->capture($this->gateway->boardUrl($board, 10), $board->w, $board->h, max(0.1, $scale));
    }

    /**
     * O canvas inteiro, enquadrado como no botão "ajustar tudo".
     */
    public function design(Artifact $design): Screenshot
    {
        $config = config('ward.designs.screenshots');
        $width = (int) $config['design_width'];
        $height = $this->designHeight($design, $width, (int) $config['design_max_height']);

        // Assinatura relativa + raiz do APP_URL: o host do pedido MCP pode ser
        // outro (ex.: 127.0.0.1), e o conteúdo só aceita moldura do APP_URL.
        $path = URL::temporarySignedRoute('designs.snapshot', now()->addMinutes(10), ['artifact' => $design->uuid], absolute: false);
        $url = rtrim((string) config('app.url'), '/').$path;

        return $this->capture($url, $width, $height, 1);
    }

    /**
     * Altura da janela que acompanha a proporção do canvas (como o fit() do JS).
     */
    private function designHeight(Artifact $design, int $width, int $max): int
    {
        $boxes = $design->boards()->get(['x', 'y', 'w', 'h'])
            ->map(fn (DesignBoard $board) => [$board->x, $board->y, $board->x + $board->w, $board->y + $board->h])
            ->concat($design->notes()->get(['x', 'y', 'w'])->map(fn ($note) => [$note->x, $note->y, $note->x + ($note->w ?: 260), $note->y + 100]));

        if ($boxes->isEmpty()) {
            return 900;
        }

        $boxWidth = max(1, $boxes->max(2) - $boxes->min(0));
        $boxHeight = max(1, $boxes->max(3) - $boxes->min(1));

        return (int) min($max, max(600, round(($width - 128) * $boxHeight / $boxWidth) + 128));
    }

    private function capture(string $url, int $width, int $height, float $scale): Screenshot
    {
        if (! $this->available()) {
            throw new ScreenshotFailed('O print pelo servidor não está configurado aqui.');
        }

        $config = config('ward.designs.screenshots');
        $dir = storage_path('app/screenshots/'.Str::random(20));
        $file = $dir.'/shot.png';
        File::ensureDirectoryExists($dir);

        // A espera pelo lock e pelo Chrome passa do limite padrão do PHP (30 s).
        set_time_limit((int) $config['timeout'] + 45);

        try {
            Cache::lock('ward:screenshot', $config['timeout'] + 10)->block(30, function () use ($config, $url, $width, $height, $scale, $dir, $file) {
                $result = Process::timeout($config['timeout'])
                    ->env(array_filter(['LD_LIBRARY_PATH' => $config['library_path'], 'HOME' => $dir]))
                    ->run([
                        $config['chrome'],
                        '--headless',
                        '--no-sandbox',
                        '--no-zygote',
                        '--disable-gpu',
                        '--disable-dev-shm-usage',
                        '--disable-extensions',
                        '--disable-background-networking',
                        '--disable-crash-reporter',
                        '--no-first-run',
                        '--no-default-browser-check',
                        '--mute-audio',
                        // Sem chaveiro do sistema (no Mac ele abriria uma janela pedindo acesso).
                        '--use-mock-keychain',
                        '--password-store=basic',
                        '--hide-scrollbars',
                        '--user-data-dir='.$dir.'/profile',
                        '--window-size='.$width.','.$height,
                        '--force-device-scale-factor='.round($scale, 3),
                        '--virtual-time-budget='.$config['budget_ms'],
                        '--screenshot='.$file,
                        $url,
                    ]);

                if (! is_file($file)) {
                    $detail = Str::of($result->errorOutput())->explode("\n")->map(fn ($line) => trim($line))
                        ->reject(fn ($line) => $line === '' || str_contains($line, 'pthread_create'))->last();

                    throw new ScreenshotFailed('O Chrome não gerou o print'.($detail ? ": {$detail}" : '.'));
                }
            });

            return Screenshot::fromPng(File::get($file), (int) $config['jpeg_quality']);
        } catch (LockTimeoutException) {
            throw new ScreenshotFailed('Outro print está sendo gerado. Tente de novo em alguns segundos.');
        } catch (ProcessTimedOutException) {
            throw new ScreenshotFailed('O print demorou demais.');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
