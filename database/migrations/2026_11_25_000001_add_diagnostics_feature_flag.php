<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('app_remote_feature_flags')) {
            return;
        }

        $now = now();
        DB::table('app_remote_feature_flags')->insertOrIgnore([
            'feature_key' => 'diagnostics.visible',
            'feature_name' => 'Runtime & Sync Diagnostics',
            'description' => 'Controls visibility of developer runtime diagnostics, API telemetry, and sync diagnostic panels in client apps.',
            'category' => 'SYSTEM',
            'is_enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('app_remote_feature_flags')) {
            DB::table('app_remote_feature_flags')->where('feature_key', 'diagnostics.visible')->delete();
        }
    }
};
