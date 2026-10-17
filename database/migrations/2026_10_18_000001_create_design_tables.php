<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->string('type', 16)->default('page')->index();
            // Relógio do canvas: sobe a cada mudança (o "ao vivo" só compara este número).
            $table->unsignedInteger('design_revision')->default(0);
        });

        Schema::create('design_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('title', 120)->nullable();
            $table->string('kind', 10)->default('fixed');
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->unsignedInteger('w');
            $table->unsignedInteger('h');
            $table->boolean('interactive')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['artifact_id', 'name']);
        });

        Schema::create('design_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('kind', 10)->default('sticky');
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->unsignedInteger('w')->nullable();
            $table->text('text');
            $table->string('color', 10)->nullable();
            $table->timestamps();

            $table->unique(['artifact_id', 'key']);
        });

        Schema::create('design_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('label', 120)->nullable();
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('board_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['artifact_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_snapshots');
        Schema::dropIfExists('design_notes');
        Schema::dropIfExists('design_boards');

        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'design_revision']);
        });
    }
};
