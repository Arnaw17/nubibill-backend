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
                if (! Schema::hasColumn('returns', 'business_signature_url')) {
                    $table->string('business_signature_url', 512)->nullable()->after('has_business_logo_snapshot');
                }
                if (! Schema::hasColumn('returns', 'business_signature_path')) {
                    $table->string('business_signature_path', 512)->nullable()->after('business_signature_url');
                }
                if (! Schema::hasColumn('returns', 'business_signature_version')) {
                    $table->string('business_signature_version', 80)->nullable()->after('business_signature_path');
                }
                if (! Schema::hasColumn('returns', 'business_signature_hash')) {
                    $table->string('business_signature_hash', 128)->nullable()->after('business_signature_version');
                }
                if (! Schema::hasColumn('returns', 'business_signatory_name')) {
                    $table->string('business_signatory_name', 160)->nullable()->after('business_signature_hash');
                }
                if (! Schema::hasColumn('returns', 'business_signatory_designation')) {
                    $table->string('business_signatory_designation', 160)->nullable()->after('business_signatory_name');
                }
                if (! Schema::hasColumn('returns', 'business_signature_enabled')) {
                    $table->boolean('business_signature_enabled')->default(false)->after('business_signatory_designation');
                }
                if (! Schema::hasColumn('returns', 'has_business_signature_snapshot')) {
                    $table->boolean('has_business_signature_snapshot')->default(false)->after('business_signature_enabled');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('returns')) {
            Schema::table('returns', function (Blueprint $table) {
                $columns = [
                    'has_business_signature_snapshot',
                    'business_signature_enabled',
                    'business_signatory_designation',
                    'business_signatory_name',
                    'business_signature_hash',
                    'business_signature_version',
                    'business_signature_path',
                    'business_signature_url',
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
