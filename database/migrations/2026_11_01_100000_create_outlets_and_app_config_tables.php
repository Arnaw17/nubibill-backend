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
        if (! Schema::hasTable('app_config')) {
            Schema::create('app_config', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('document_path', 500)->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('status', 100)->default('active')->index();
                $table->tinyInteger('is_deleted')->default(0);
                $table->json('payload');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('outlets')) {
            Schema::create('outlets', function (Blueprint $table) {
                $table->string('id', 255)->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('business_id', 255)->nullable()->index();
                $table->string('outlet_id', 255)->nullable()->index();
                $table->string('status', 100)->default('active')->index();
                $table->tinyInteger('is_deleted')->default(0);
                $table->json('payload');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('app_config');
    }
};
