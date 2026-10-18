<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comentários do canvas: um tópico (com número por design, ex.: #3) e respostas.
        // Âncora: um elemento dentro de um artboard (anchor), o artboard, ou um ponto do canvas (x/y).
        Schema::create('design_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('design_comments')->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('board_id')->nullable()->constrained('design_boards')->nullOnDelete();
            $table->string('board_name', 60)->nullable();
            $table->json('anchor')->nullable();
            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
            $table->text('body');
            // web: escrito no canvas; ai: pela IA (MCP).
            $table->string('source', 8)->default('web');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['artifact_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_comments');
    }
};
