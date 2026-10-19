<?php

use App\Designs\DesignStorage;
use App\Models\DesignBoard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_boards', function (Blueprint $table) {
            // Ligações do protótipo lidas do HTML (board:, data-ward-to, data-ward-overlay).
            $table->json('links')->nullable()->after('includes');
        });

        // Artboards que já existem: lê as ligações do HTML atual.
        $storage = app(DesignStorage::class);
        DesignBoard::query()->where('storage_path', '!=', '')->chunkById(100, function ($boards) use ($storage) {
            foreach ($boards as $board) {
                try {
                    $links = DesignBoard::linksIn($storage->html($board));
                } catch (Throwable) {
                    continue;
                }
                if ($links !== []) {
                    $board->timestamps = false;
                    $board->forceFill(['links' => $links])->saveQuietly();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('design_boards', fn (Blueprint $table) => $table->dropColumn('links'));
    }
};
