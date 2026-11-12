<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version')->index();
            $table->string('channel')->default('stable')->index();
            $table->string('platform')->default('win32-x64')->index();
            $table->boolean('is_mandatory')->default(false);
            $table->string('min_supported_version')->nullable();
            $table->boolean('database_migration_required')->default(false);
            $table->unsignedInteger('target_db_version')->default(1);
            $table->text('release_notes')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('checksum_sha256', 64);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_recalled')->default(false)->index();
            $table->text('recall_reason')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->string('created_by', 36)->nullable()->index();
            $table->timestamps();

            $table->unique(['version', 'channel', 'platform'], 'app_releases_version_channel_platform_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
