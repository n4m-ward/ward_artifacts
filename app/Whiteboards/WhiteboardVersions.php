<?php

namespace App\Whiteboards;

use App\Models\Artifact;
use App\Models\DesignSnapshot;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Versões de um quadro: o arquivo .excalidraw do quadro inteiro, numerado (mesma
 * tabela das versões do design). Guarda as mais recentes (ward.whiteboards.keep_versions)
 * e restaura uma versão gravando os elementos dela por cima do quadro atual.
 */
class WhiteboardVersions
{
    public function __construct(private WhiteboardStore $store, private WhiteboardFiles $files) {}

    /**
     * O quadro no formato de arquivo do Excalidraw (para versões e para baixar).
     *
     * @return array<string, mixed>
     */
    public function document(Artifact $board, bool $withFiles = false): array
    {
        $files = [];

        if ($withFiles) {
            foreach ($board->whiteboardFiles()->get() as $file) {
                $bytes = $this->files->disk()->get($file->storage_path);
                if ($bytes !== null) {
                    $files[$file->file_id] = [
                        'id' => $file->file_id,
                        'mimeType' => $file->mime,
                        'dataURL' => 'data:'.$file->mime.';base64,'.base64_encode($bytes),
                        'created' => $file->created_at?->getTimestampMs(),
                    ];
                }
            }
        }

        return [
            'type' => 'excalidraw',
            'version' => 2,
            'source' => url('/'),
            'elements' => $this->store->all($board),
            'appState' => ['viewBackgroundColor' => '#ffffff', 'gridSize' => 20],
            'files' => (object) $files,
        ];
    }

    public function save(Artifact $board, User $author, ?string $label = null): DesignSnapshot
    {
        $document = $this->document($board);

        if ($document['elements'] === []) {
            throw new InvalidWhiteboard('O quadro está vazio: nada para salvar numa versão.');
        }

        $number = (int) $board->snapshots()->max('number') + 1;
        $path = "{$board->uuid}/whiteboard/versions/{$number}.excalidraw";
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->files->disk()->put($path, $json);

        $snapshot = $board->snapshots()->create([
            'number' => $number,
            'label' => $label !== null && trim($label) !== '' ? Str::limit(Str::squish($label), 120, '') : null,
            'storage_path' => $path,
            'size_bytes' => strlen($json),
            'board_count' => count($document['elements']),
            'created_by' => $author->id,
        ]);

        $keep = max(1, (int) config('ward.whiteboards.keep_versions'));
        $board->snapshots()->where('number', '<=', $number - $keep)->get()->each(function (DesignSnapshot $old) {
            $this->files->disk()->delete($old->storage_path);
            $old->delete();
        });

        return $snapshot;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Artifact $board): array
    {
        return $board->snapshots()->with([])->orderByDesc('number')->get()->map(fn (DesignSnapshot $snapshot) => [
            'number' => $snapshot->number,
            'label' => $snapshot->label,
            'elements' => $snapshot->board_count,
            'at' => $snapshot->created_at?->toIso8601String(),
            'by' => $snapshot->created_by ? User::find($snapshot->created_by)?->name : null,
        ])->values()->all();
    }

    /**
     * Volta o quadro para uma versão: os elementos dela são gravados de novo e o
     * que não existia nela é apagado (dá para desfazer restaurando outra versão).
     */
    public function restore(Artifact $board, User $user, int $number): int
    {
        $snapshot = $board->snapshots()->where('number', $number)->first()
            ?? throw new InvalidWhiteboard("Versão {$number} não encontrada.");

        $document = json_decode((string) $this->files->disk()->get($snapshot->storage_path), true);
        $elements = $document['elements'] ?? [];
        $keep = array_flip(array_column($elements, 'id'));

        foreach ($this->store->all($board) as $current) {
            if (! isset($keep[$current['id']])) {
                $elements[] = ['isDeleted' => true] + $current;
                $elements[array_key_last($elements)]['isDeleted'] = true;
            }
        }

        return $this->store->put($board, $user, $elements)['seq'];
    }
}
