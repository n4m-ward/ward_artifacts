<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imagens de um design: {uuid}/assets/{name}, usadas nos artboards como "assets/{name}".
        Schema::create('design_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('original_name', 160)->nullable();
            $table->string('mime', 40);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['artifact_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_assets');
    }
};
