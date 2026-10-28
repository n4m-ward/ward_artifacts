<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ward Whiteboard: artifact type=whiteboard. whiteboard_seq é o relógio do quadro
        // (sobe a cada gravação); whiteboard_state guarda votação, cronômetro e padrões.
        Schema::table('artifacts', function (Blueprint $table) {
            $table->unsignedBigInteger('whiteboard_seq')->default(0)->after('design_revision');
            $table->json('whiteboard_state')->nullable()->after('whiteboard_seq');
        });

        // Um elemento do Excalidraw por linha: duas pessoas mexendo em elementos
        // diferentes nunca se atrapalham. data é o elemento inteiro.
        Schema::create('whiteboard_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->string('element_id', 64);
            $table->string('type', 20);
            $table->unsignedInteger('version');
            $table->bigInteger('version_nonce');
            $table->boolean('is_deleted')->default(false);
            $table->longText('data');
            $table->unsignedBigInteger('seq');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['artifact_id', 'element_id']);
            $table->index(['artifact_id', 'seq']);
        });

        // Imagens do quadro (files do Excalidraw), guardadas no disco de artifacts.
        Schema::create('whiteboard_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->string('file_id', 64);
            $table->string('mime', 40);
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['artifact_id', 'file_id']);
        });

        // Convidados de um artifact (hoje: editores de whiteboards).
        Schema::create('artifact_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('editor');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['artifact_id', 'user_id']);
        });

        // Quem está no quadro agora: cursor, seleção e câmera de cada pessoa.
        Schema::create('whiteboard_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->timestamp('seen_at');

            $table->unique(['artifact_id', 'user_id']);
            $table->index(['artifact_id', 'seen_at']);
        });

        // Votos em elementos (votação aberta pelo dono ou por um editor).
        Schema::create('whiteboard_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->string('element_id', 64);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('count')->default(1);
            $table->timestamps();

            $table->unique(['artifact_id', 'element_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboard_votes');
        Schema::dropIfExists('whiteboard_presences');
        Schema::dropIfExists('artifact_members');
        Schema::dropIfExists('whiteboard_files');
        Schema::dropIfExists('whiteboard_elements');

        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropColumn(['whiteboard_seq', 'whiteboard_state']);
        });
    }
};
