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
        if (Schema::hasTable('user_complaints')) {
            Schema::table('user_complaints', function (Blueprint $table) {
                if (! Schema::hasColumn('user_complaints', 'page_name')) {
                    $table->string('page_name', 255)->nullable()->after('description');
                }
                if (! Schema::hasColumn('user_complaints', 'device_platform')) {
                    $table->string('device_platform', 100)->nullable()->after('page_name');
                }
                if (! Schema::hasColumn('user_complaints', 'app_version')) {
                    $table->string('app_version', 100)->nullable()->after('device_platform');
                }
                if (! Schema::hasColumn('user_complaints', 'error_summary')) {
                    $table->text('error_summary')->nullable()->after('app_version');
                }
                if (! Schema::hasColumn('user_complaints', 'error_details')) {
                    $table->text('error_details')->nullable()->after('error_summary');
                }
                if (! Schema::hasColumn('user_complaints', 'system_logs')) {
                    $table->json('system_logs')->nullable()->after('error_details');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_complaints')) {
            Schema::table('user_complaints', function (Blueprint $table) {
                $columns = ['page_name', 'device_platform', 'app_version', 'error_summary', 'error_details', 'system_logs'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('user_complaints', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
