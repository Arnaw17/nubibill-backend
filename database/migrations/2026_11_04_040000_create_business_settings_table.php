<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['business_settings', 'inventory_settings'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) {
                    $table->string('id', 255)->primary();
                    $table->string('document_path', 500)->nullable();
                    $table->string('business_id', 255)->nullable()->index();
                    $table->string('outlet_id', 255)->nullable()->index();
                    $table->string('tenant_id', 255)->nullable()->index();
                    $table->string('status', 50)->default('ACTIVE')->index();
                    $table->boolean('is_deleted')->default(false)->index();
                    $table->json('payload')->nullable();
                    $table->timestamps();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('inventory_settings');
    }
};
