<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Live App User Sessions & Screening
        if (! Schema::hasTable('app_user_sessions')) {
            Schema::create('app_user_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('session_id')->unique();
                $table->string('user_id')->nullable()->index();
                $table->string('user_email')->nullable();
                $table->string('user_name')->nullable();
                $table->string('role')->nullable();
                $table->string('business_id')->nullable()->index();
                $table->string('business_name')->nullable();
                $table->string('outlet_id')->nullable()->index();
                $table->string('outlet_name')->nullable();
                $table->string('device_platform')->default('windows');
                $table->string('device_info')->nullable();
                $table->string('app_version')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('current_screen')->default('Dashboard');
                $table->string('current_action')->default('Active');
                $table->string('status')->default('ONLINE'); // ONLINE, IDLE, LOGGED_OUT, TERMINATED
                $table->dateTime('login_at');
                $table->dateTime('last_heartbeat_at')->index();
                $table->dateTime('logout_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        // 2. Real-time Screen Tracking Stream
        if (! Schema::hasTable('app_screen_traces')) {
            Schema::create('app_screen_traces', function (Blueprint $table) {
                $table->id();
                $table->string('session_id')->index();
                $table->string('user_id')->nullable()->index();
                $table->string('business_id')->nullable();
                $table->string('outlet_id')->nullable();
                $table->string('screen_name')->index();
                $table->string('previous_screen')->nullable();
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->string('device_platform')->nullable();
                $table->json('metadata')->nullable();
                $table->dateTime('created_at')->index();
            });
        }

        // 3. Remote Feature Flags & Kill Switch
        if (! Schema::hasTable('app_remote_feature_flags')) {
            Schema::create('app_remote_feature_flags', function (Blueprint $table) {
                $table->id();
                $table->string('feature_key')->unique();
                $table->string('feature_name');
                $table->text('description')->nullable();
                $table->string('category')->default('MODULE'); // MODULE, SYSTEM, SECURITY, COMPLIANCE
                $table->boolean('is_enabled')->default(true);
                $table->string('target_business_id')->nullable()->index();
                $table->string('target_platform')->nullable();
                $table->string('min_app_version')->nullable();
                $table->string('disabled_reason')->nullable();
                $table->string('updated_by')->nullable();
                $table->timestamps();
            });

            // Seed standard initial feature flags
            $now = now();
            DB::table('app_remote_feature_flags')->insert([
                [
                    'feature_key' => 'pos_billing',
                    'feature_name' => 'POS Billing & Quick Checkout',
                    'description' => 'Main Point-of-Sale billing interface and receipt generation.',
                    'category' => 'MODULE',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'feature_key' => 'staff_attendance_face',
                    'feature_name' => 'Biometric Face Recognition Attendance',
                    'description' => 'Offline on-device biometric neural embeddings for shift punch.',
                    'category' => 'MODULE',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'feature_key' => 'staff_payroll_khata',
                    'feature_name' => 'Staff Salary & Daily Wage Khata',
                    'description' => 'Khata ledger, salary disbursal, and advance tracking.',
                    'category' => 'MODULE',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'feature_key' => 'reports_download',
                    'feature_name' => 'Dynamic Report PDF & Excel Export',
                    'description' => 'One-click financial reconciliation, sales, and KPI downloads.',
                    'category' => 'MODULE',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'feature_key' => 'expenses_management',
                    'feature_name' => 'Expense Management & Categorization',
                    'description' => 'Operational business expense tracking.',
                    'category' => 'MODULE',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'feature_key' => 'app_maintenance_mode',
                    'feature_name' => 'Global App Maintenance Mode',
                    'description' => 'When enabled, displays maintenance screen and halts remote sync.',
                    'category' => 'SYSTEM',
                    'is_enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'feature_key' => 'inventory_batches',
                    'feature_name' => 'Inventory Batch & Expiry Tracking',
                    'description' => 'Batch-level stock tracking and godown movements.',
                    'category' => 'MODULE',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'feature_key' => 'credit_sales',
                    'feature_name' => 'Customer Credit Sales & Udhar',
                    'description' => 'Allows credit sales recorded to customer ledger.',
                    'category' => 'MODULE',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_remote_feature_flags');
        Schema::dropIfExists('app_screen_traces');
        Schema::dropIfExists('app_user_sessions');
    }
};
