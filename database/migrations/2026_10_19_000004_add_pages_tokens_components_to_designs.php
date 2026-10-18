<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Páginas de um design (grupos de artboards). Sem páginas, tudo fica numa só.
        Schema::create('design_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name', 80);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['artifact_id', 'key']);
        });

        Schema::table('design_boards', function (Blueprint $table) {
            $table->string('page', 40)->nullable()->after('kind');
            // Nomes dos artboards usados como componente (<ward-include name="…">).
            $table->json('includes')->nullable()->after('page');
        });

        Schema::table('design_notes', function (Blueprint $table) {
            $table->string('page', 40)->nullable()->after('kind');
        });

        Schema::table('artifacts', function (Blueprint $table) {
            // Tokens do design (variáveis CSS no :root), injetados em todos os artboards.
            $table->text('design_tokens')->nullable()->after('design_revision');
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', fn (Blueprint $table) => $table->dropColumn('design_tokens'));
        Schema::table('design_notes', fn (Blueprint $table) => $table->dropColumn('page'));
        Schema::table('design_boards', fn (Blueprint $table) => $table->dropColumn(['page', 'includes']));
        Schema::dropIfExists('design_pages');
    }
};
