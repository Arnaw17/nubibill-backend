<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('returns')) {
            Schema::table('returns', function (Blueprint $table) {
                if (! Schema::hasColumn('returns', 'business_logo_url')) {
                    $table->string('business_logo_url', 512)->nullable()->after('notes');
                }
                if (! Schema::hasColumn('returns', 'business_logo_path')) {
                    $table->string('business_logo_path', 512)->nullable()->after('business_logo_url');
                }
                if (! Schema::hasColumn('returns', 'business_logo_version')) {
                    $table->string('business_logo_version', 80)->nullable()->after('business_logo_path');
                }
                if (! Schema::hasColumn('returns', 'business_logo_hash')) {
                    $table->string('business_logo_hash', 128)->nullable()->after('business_logo_version');
                }
                if (! Schema::hasColumn('returns', 'has_business_logo_snapshot')) {
                    $table->boolean('has_business_logo_snapshot')->default(false)->after('business_logo_hash');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('returns')) {
            Schema::table('returns', function (Blueprint $table) {
                $columns = [
                    'has_business_logo_snapshot',
                    'business_logo_hash',
                    'business_logo_version',
                    'business_logo_path',
                    'business_logo_url',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('returns', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
