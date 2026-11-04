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
        if (! Schema::hasTable('system_error_analytics')) {
            Schema::create('system_error_analytics', function (Blueprint $table) {
                $table->string('id', 255)->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('business_id', 255)->nullable()->index();
                $table->string('outlet_id', 255)->nullable()->index();
                $table->string('tenant_id', 255)->nullable()->index();
                $table->string('status', 100)->default('ACTIVE')->index();
                $table->tinyInteger('is_deleted')->default(0)->index();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_error_analytics');
    }
};
