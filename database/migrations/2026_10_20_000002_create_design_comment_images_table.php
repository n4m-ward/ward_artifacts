<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imagens anexadas a comentários do canvas (prints colados, fotos). Ficam à parte das
        // imagens do design: o arquivo vai em {uuid}/comments/{name}. Sem comment_id = enviada e
        // ainda não publicada (some depois de um dia se ninguém publicar).
        Schema::create('design_comment_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained('design_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('mime', 40);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size_bytes');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['artifact_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_comment_images');
    }
};
