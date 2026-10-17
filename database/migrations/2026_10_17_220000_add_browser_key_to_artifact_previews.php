<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifact_previews', function (Blueprint $table) {
            // Hash da chave gravada em cookie no navegador que usou o link: só ele
            // pode recarregar a pré-visualização.
            $table->char('browser_key_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artifact_previews', function (Blueprint $table) {
            $table->dropColumn('browser_key_hash');
        });
    }
};
