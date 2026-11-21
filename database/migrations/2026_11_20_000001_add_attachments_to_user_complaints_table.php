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
                if (! Schema::hasColumn('user_complaints', 'attachment_url')) {
                    $table->string('attachment_url', 1000)->nullable()->after('system_logs');
                }
                if (! Schema::hasColumn('user_complaints', 'attachment_name')) {
                    $table->string('attachment_name', 255)->nullable()->after('attachment_url');
                }
                if (! Schema::hasColumn('user_complaints', 'attachment_data')) {
                    $table->longText('attachment_data')->nullable()->after('attachment_name');
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
                $columns = ['attachment_url', 'attachment_name', 'attachment_data'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('user_complaints', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
