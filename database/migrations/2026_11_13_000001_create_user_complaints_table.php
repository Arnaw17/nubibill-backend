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
        if (! Schema::hasTable('user_complaints')) {
            Schema::create('user_complaints', function (Blueprint $table) {
                $table->string('id', 255)->primary();
                $table->string('complaint_code', 50)->unique();
                $table->string('business_id', 255)->nullable()->index();
                $table->string('business_name', 255)->nullable();
                $table->string('outlet_id', 255)->nullable()->index();
                $table->string('outlet_name', 255)->nullable();
                $table->string('user_id', 255)->nullable()->index();
                $table->string('user_name', 255)->nullable();
                $table->string('user_email', 255)->nullable()->index();
                $table->string('user_phone', 50)->nullable();
                $table->string('subject', 255);
                $table->string('category', 100)->default('GENERAL')->index();
                $table->string('priority', 50)->default('MEDIUM');
                $table->text('description');
                $table->string('status', 50)->default('SUBMITTED')->index();
                $table->text('admin_notes')->nullable();
                $table->text('resolution_summary')->nullable();
                $table->json('timeline')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_complaints');
    }
};
