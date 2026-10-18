<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            // Design system: um design por projeto, sempre visível para quem está logado.
            $table->boolean('design_system')->default(false)->index()->after('type');
            $table->text('description')->nullable()->after('title');
            $table->mediumText('docs')->nullable()->after('design_tokens');
            // Design que usa o tema (tokens) de um design system.
            $table->foreignId('uses_design_system_id')->nullable()->after('docs')->constrained('artifacts')->nullOnDelete();
        });

        Schema::table('design_boards', function (Blueprint $table) {
            // Componentes do design system: o que é e onde usar; tags para o filtro.
            $table->text('description')->nullable()->after('title');
            $table->string('tags', 200)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('design_boards', fn (Blueprint $table) => $table->dropColumn(['description', 'tags']));
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uses_design_system_id');
            $table->dropColumn(['design_system', 'description', 'docs']);
        });
    }
};
