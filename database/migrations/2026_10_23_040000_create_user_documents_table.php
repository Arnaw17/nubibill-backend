<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // AADHAAR, PAN, VOTER
            $table->string('file_path');
            $table->text('number_encrypted')->nullable();
            $table->string('status')->default('PENDING'); // PENDING, APPROVED, REJECTED
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // Unique index handling for SQLite, PostgreSQL, and MySQL
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX user_documents_user_id_type_unique ON user_documents (user_id, type) WHERE deleted_at IS NULL;');
        } elseif ($driver === 'mysql') {
            try {
                // MySQL 8.0+ functional unique index
                DB::statement('CREATE UNIQUE INDEX user_documents_user_id_type_unique ON user_documents (user_id, type, (CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END));');
            } catch (Throwable) {
                Schema::table('user_documents', function (Blueprint $table) {
                    $table->unique(['user_id', 'type'], 'user_documents_user_id_type_unique');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS user_documents_user_id_type_unique;');
        } elseif ($driver === 'mysql') {
            Schema::table('user_documents', function (Blueprint $table) {
                $table->dropIndex('user_documents_user_id_type_unique');
            });
        }
        Schema::dropIfExists('user_documents');
    }
};
