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
        if (! Schema::hasTable('salary_profiles')) {
            Schema::create('salary_profiles', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('business_id')->nullable()->index();
                $table->string('outlet_id')->nullable()->index();
                $table->string('employee_id')->nullable()->index();
                $table->string('status', 100)->nullable()->index();
                $table->dateTime('created_at', 6)->nullable()->index();
                $table->dateTime('updated_at', 6)->nullable();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload');
            });
        }

        if (! Schema::hasTable('salary_periods')) {
            Schema::create('salary_periods', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('business_id')->nullable()->index();
                $table->string('outlet_id')->nullable()->index();
                $table->string('status', 100)->nullable()->index();
                $table->dateTime('created_at', 6)->nullable()->index();
                $table->dateTime('updated_at', 6)->nullable();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload');
            });
        }

        if (! Schema::hasTable('staff_khata_transactions')) {
            Schema::create('staff_khata_transactions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('business_id')->nullable()->index();
                $table->string('outlet_id')->nullable()->index();
                $table->string('employee_id')->nullable()->index();
                $table->string('status', 100)->nullable()->index();
                $table->dateTime('created_at', 6)->nullable()->index();
                $table->dateTime('updated_at', 6)->nullable();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_khata_transactions');
        Schema::dropIfExists('salary_periods');
        Schema::dropIfExists('salary_profiles');
    }
};
