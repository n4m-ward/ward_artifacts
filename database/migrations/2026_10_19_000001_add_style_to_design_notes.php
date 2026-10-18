<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_notes', function (Blueprint $table) {
            // Tamanho do texto (s, m, l, xl) e negrito: editáveis no canvas.
            $table->string('size', 4)->nullable()->after('color');
            $table->boolean('bold')->default(false)->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('design_notes', function (Blueprint $table) {
            $table->dropColumn(['size', 'bold']);
        });
    }
};
