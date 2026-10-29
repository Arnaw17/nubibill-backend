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
            'feature_key' => 'registration',
            'feature_name' => 'New User Registration',
            'description' => 'Allow new user accounts to register. Existing users can continue to sign in when registration is disabled.',
            'category' => 'SYSTEM',
            'is_enabled' => true,
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
            DB::table('app_remote_feature_flags')->where('feature_key', 'registration')->delete();
        }
    }
};
