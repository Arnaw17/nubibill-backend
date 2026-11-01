<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('tenant_id', 100)->nullable()->index();
                $table->string('title', 255);
                $table->string('category', 100)->index();
                $table->bigInteger('amount_paise')->default(0);
                $table->dateTime('expense_date')->index();
                $table->string('payment_mode', 50)->default('CASH');
                $table->string('payee_name', 255)->nullable();
                $table->string('reference_number', 100)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 50)->default('ACTIVE')->index();
                $table->string('created_by', 100)->nullable();
                $table->string('document_path', 255)->nullable();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('financial_transactions')) {
            Schema::create('financial_transactions', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('tenant_id', 100)->nullable()->index();
                $table->string('transaction_type', 50)->index(); // INCOME, EXPENSE, TRANSFER, CUSTOMER_PAYMENT, SUPPLIER_PAYMENT, STAFF_ADVANCE, ADJUSTMENT
                $table->string('category', 100)->nullable();
                $table->bigInteger('amount_paise')->default(0);
                $table->string('payment_mode', 50)->default('CASH')->index();
                $table->string('reference_id', 100)->nullable()->index();
                $table->string('reference_type', 50)->nullable();
                $table->text('description')->nullable();
                $table->dateTime('transaction_date')->index();
                $table->bigInteger('balance_after_paise')->nullable();
                $table->string('recorded_by', 100)->nullable();
                $table->string('status', 50)->default('COMPLETED')->index();
                $table->string('document_path', 255)->nullable();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('expenses');
    }
};
