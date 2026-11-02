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
        if (! Schema::hasTable('employee_outlet_allocations')) {
            Schema::create('employee_outlet_allocations', function (Blueprint $table): void {
                $table->string('id', 255)->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('business_id', 100);
                $table->string('employee_id', 255);
                $table->string('status', 100)->default('ACTIVE')->index();
                $table->dateTime('effective_from', 6);
                $table->dateTime('effective_to', 6)->nullable();
                $table->json('outlet_basis_points');
                $table->unsignedSmallInteger('allocation_total_basis_points')->default(10000);
                $table->string('created_by', 255)->nullable();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps(6);

                $table->index(['business_id', 'employee_id'], 'employee_alloc_scope_idx');
                $table->unique(
                    ['business_id', 'employee_id', 'effective_from'],
                    'employee_alloc_effective_unique',
                );
                $table->foreign('business_id', 'employee_alloc_business_fk')
                    ->references('id')->on('businesses')->restrictOnDelete();
                $table->foreign('employee_id', 'employee_alloc_employee_fk')
                    ->references('id')->on('employees')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('staff_attendance_events')) {
            Schema::create('staff_attendance_events', function (Blueprint $table): void {
                $table->string('id', 255)->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('attendance_id', 255);
                $table->string('tenant_id', 255);
                $table->string('business_id', 100);
                $table->string('outlet_id', 255);
                $table->string('employee_id', 255);
                $table->string('event_type', 30);
                $table->dateTime('occurred_at', 6);
                $table->date('work_date');
                $table->string('work_timezone_id', 100)->nullable();
                $table->string('attendance_method', 50);
                $table->string('kiosk_device_id', 255)->nullable();
                $table->string('recognition_model_version', 100)->nullable();
                $table->string('liveness_check_version', 100)->nullable();
                $table->decimal('gps_latitude', 10, 7)->nullable();
                $table->decimal('gps_longitude', 10, 7)->nullable();
                $table->decimal('gps_accuracy', 10, 2)->nullable();
                $table->boolean('is_mock_location')->default(false);
                $table->decimal('geofence_distance_meters', 10, 2)->nullable();
                $table->decimal('geofence_radius_meters', 10, 2)->nullable();
                $table->json('liveness_challenges')->nullable();
                $table->string('verification_status', 50)->nullable();
                $table->json('flagged_reasons')->nullable();
                $table->string('recorded_by', 255)->nullable();
                $table->boolean('immutable')->default(true);
                $table->string('status', 100)->default('RECORDED')->index();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps(6);

                $table->unique(['attendance_id', 'event_type'], 'attendance_event_once_unique');
                $table->index(
                    ['tenant_id', 'business_id', 'employee_id', 'work_date'],
                    'attendance_event_scope_idx',
                );
                $table->foreign('attendance_id', 'attendance_event_record_fk')
                    ->references('id')->on('staff_attendance')->restrictOnDelete();
                $table->foreign('business_id', 'attendance_event_business_fk')
                    ->references('id')->on('businesses')->restrictOnDelete();
                $table->foreign('outlet_id', 'attendance_event_outlet_fk')
                    ->references('id')->on('outlets')->restrictOnDelete();
                $table->foreign('employee_id', 'attendance_event_employee_fk')
                    ->references('id')->on('employees')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('supplier_refunds')) {
            Schema::create('supplier_refunds', function (Blueprint $table): void {
                $table->string('id', 255)->primary();
                $table->string('document_path', 500)->nullable();
                $table->string('tenant_id', 255);
                $table->string('business_id', 100);
                $table->string('outlet_id', 255);
                $table->string('supplier_id', 255);
                $table->string('supplier_business_id', 100);
                $table->unsignedBigInteger('amount_paise');
                $table->dateTime('refund_date', 6);
                $table->string('payment_mode', 50);
                $table->string('reference_no', 255)->nullable();
                $table->text('reason');
                $table->text('notes')->nullable();
                $table->string('status', 50)->default('POSTED')->index();
                $table->string('financial_transaction_id', 255);
                $table->string('created_by_uid', 255)->nullable();
                $table->string('request_id', 100);
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps(6);

                $table->unique(
                    ['tenant_id', 'business_id', 'request_id'],
                    'supplier_refund_request_unique',
                );
                $table->unique('financial_transaction_id', 'supplier_refund_financial_unique');
                $table->index(
                    ['business_id', 'supplier_business_id', 'refund_date'],
                    'supplier_refund_supplier_date_idx',
                );
                $table->foreign('business_id', 'supplier_refund_business_fk')
                    ->references('id')->on('businesses')->restrictOnDelete();
                $table->foreign('outlet_id', 'supplier_refund_outlet_fk')
                    ->references('id')->on('outlets')->restrictOnDelete();
                $table->foreign('supplier_id', 'supplier_refund_supplier_fk')
                    ->references('id')->on('suppliers')->restrictOnDelete();
                $table->foreign('supplier_business_id', 'supplier_refund_balance_fk')
                    ->references('id')->on('supplier_balances')->restrictOnDelete();
                // financial_transactions.id is varchar(100), while generated refund
                // transaction IDs can exceed 100 characters. Keep this as an indexed
                // logical reference instead of imposing a truncating FK constraint.
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_refunds');
        Schema::dropIfExists('staff_attendance_events');
        Schema::dropIfExists('employee_outlet_allocations');
    }
};
