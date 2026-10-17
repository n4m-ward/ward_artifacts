<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug', 100);
            $table->string('visibility', 16)->default('owner');
            $table->timestamp('public_until')->nullable()->index();
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('artifact_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('kind', 16);
            $table->string('storage_path');
            $table->string('entry_path')->default('index.html');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('file_count')->default(1);
            $table->char('sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['artifact_id', 'number']);
        });

        Schema::create('artifact_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_viewed_at');
            $table->timestamp('last_viewed_at');

            $table->unique(['user_id', 'artifact_id']);
        });

        Schema::create('artifact_uploads', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artifact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('project')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_uploads');
        Schema::dropIfExists('artifact_views');
        Schema::dropIfExists('artifact_versions');
        Schema::dropIfExists('artifacts');
    }
};
