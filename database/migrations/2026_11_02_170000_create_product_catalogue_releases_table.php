<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_catalogue_releases')) {
            Schema::create('product_catalogue_releases', function (Blueprint $table) {
                $table->string('id', 255)->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('business_id', 255)->nullable()->index();
                $table->string('outlet_id', 255)->nullable()->index();
                $table->string('tenant_id', 255)->nullable()->index();
                $table->string('scope_type', 100)->nullable()->index();
                $table->string('status', 100)->default('active')->index();
                $table->tinyInteger('is_deleted')->default(0)->index();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalogue_releases');
    }
};
