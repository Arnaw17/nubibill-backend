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
        if (Schema::hasTable('email_otps') && ! Schema::hasColumn('email_otps', 'purpose')) {
            Schema::table('email_otps', function (Blueprint $table) {
                $table->string('purpose', 30)->default('REGISTRATION')->after('email')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('email_otps') && Schema::hasColumn('email_otps', 'purpose')) {
            Schema::table('email_otps', function (Blueprint $table) {
                $table->dropColumn('purpose');
            });
        }
    }
};
