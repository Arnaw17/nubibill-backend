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
        if (!Schema::hasTable('product_attributes')) {
            Schema::create('product_attributes', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('document_path')->nullable();
                $table->string('business_id')->nullable();
                $table->string('outlet_id')->nullable();
                $table->string('tenant_id')->nullable();
                $table->string('scope_type', 50)->default('SYSTEM');
                $table->string('status', 50)->default('ACTIVE');
                $table->boolean('is_deleted')->default(false);
                $table->longText('payload')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_attribute_options')) {
            Schema::create('product_attribute_options', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('document_path')->nullable();
                $table->string('business_id')->nullable();
                $table->string('outlet_id')->nullable();
                $table->string('tenant_id')->nullable();
                $table->string('scope_type', 50)->default('SYSTEM');
                $table->string('status', 50)->default('ACTIVE');
                $table->boolean('is_deleted')->default(false);
                $table->longText('payload')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attribute_options');
        Schema::dropIfExists('product_attributes');
    }
};
