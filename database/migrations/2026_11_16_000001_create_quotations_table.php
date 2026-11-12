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
        if (! Schema::hasTable('quotations')) {
            Schema::create('quotations', function (Blueprint $table) {
                $table->string('id', 255)->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('quotation_number', 120)->nullable()->index();
                $table->string('business_id', 255)->nullable()->index();
                $table->string('outlet_id', 255)->nullable()->index();
                $table->string('customer_id', 255)->nullable()->index();
                $table->string('customer_name', 255)->nullable();
                $table->string('customer_phone', 100)->nullable();
                $table->string('status', 100)->default('SAVED')->index();
                $table->string('converted_invoice_id', 255)->nullable()->index();
                $table->string('converted_invoice_number', 120)->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->bigInteger('grand_total_paise')->default(0);
                $table->date('valid_until')->nullable();
                $table->text('notes')->nullable();
                $table->string('created_by', 255)->nullable();
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
        Schema::dropIfExists('quotations');
    }
};
