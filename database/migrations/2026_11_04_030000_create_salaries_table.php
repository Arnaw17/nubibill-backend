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
        if (! Schema::hasTable('salaries')) {
            Schema::create('salaries', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('period_id')->nullable()->index();
                $table->string('business_id')->nullable()->index();
                $table->string('outlet_id')->nullable()->index();
                $table->string('employee_id')->nullable()->index();
                $table->string('profile_id')->nullable()->index();
                $table->string('period_start')->nullable();
                $table->string('period_end')->nullable();
                $table->integer('month')->nullable();
                $table->integer('year')->nullable();
                $table->integer('days_in_month')->default(30);

                // Monetary figures in standard decimal (Rupees)
                $table->decimal('monthly_gross_salary', 14, 2)->default(0.00);
                $table->decimal('daily_rate', 14, 2)->default(0.00);
                $table->decimal('unpaid_leave_days', 8, 2)->default(0.00);
                $table->decimal('deduction_amount', 14, 2)->default(0.00);
                $table->decimal('unpaid_leave_deduction', 14, 2)->default(0.00);
                $table->decimal('other_deductions', 14, 2)->default(0.00);
                $table->decimal('bonus', 14, 2)->default(0.00);
                $table->decimal('overtime', 14, 2)->default(0.00);
                $table->decimal('net_payable', 14, 2)->default(0.00);

                // Exact integer Paise figures
                $table->bigInteger('monthly_gross_salary_paise')->default(0);
                $table->bigInteger('daily_rate_paise')->default(0);
                $table->bigInteger('deduction_amount_paise')->default(0);
                $table->bigInteger('unpaid_leave_deduction_paise')->default(0);
                $table->bigInteger('other_deductions_paise')->default(0);
                $table->bigInteger('bonus_paise')->default(0);
                $table->bigInteger('overtime_paise')->default(0);
                $table->bigInteger('net_payable_paise')->default(0);

                $table->string('status', 100)->default('APPROVED')->index();
                $table->string('created_by')->nullable();
                $table->boolean('is_deleted')->default(false)->index();
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
        Schema::dropIfExists('salaries');
    }
};
