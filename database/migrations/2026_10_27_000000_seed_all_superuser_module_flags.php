<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a remote flag for every client module without changing existing flag state.
     */
    public function up(): void
    {
        if (! Schema::hasTable('app_remote_feature_flags')) {
            return;
        }

        $now = now();
        $flags = [
            ['feature_key' => 'sales', 'feature_name' => 'Sales & Billing History'],
            ['feature_key' => 'purchase', 'feature_name' => 'Purchase Management'],
            ['feature_key' => 'inventory', 'feature_name' => 'Inventory Management'],
            ['feature_key' => 'products', 'feature_name' => 'Product Catalogue'],
            ['feature_key' => 'barcode', 'feature_name' => 'Barcode Management'],
            ['feature_key' => 'customers', 'feature_name' => 'Customer Management'],
            ['feature_key' => 'suppliers', 'feature_name' => 'Supplier Management'],
            ['feature_key' => 'supplier_ledger', 'feature_name' => 'Supplier Ledger'],
            ['feature_key' => 'employee_activity', 'feature_name' => 'Employee Activity'],
            ['feature_key' => 'staff', 'feature_name' => 'Users & Staff'],
            ['feature_key' => 'settings', 'feature_name' => 'Settings'],
            ['feature_key' => 'godowns', 'feature_name' => 'Godowns'],
            ['feature_key' => 'racks', 'feature_name' => 'Outlet Racks'],
            ['feature_key' => 'businesses', 'feature_name' => 'Businesses & Outlets'],
        ];

        foreach ($flags as $flag) {
            DB::table('app_remote_feature_flags')->insertOrIgnore([
                ...$flag,
                'description' => "Remote control for the {$flag['feature_name']} module.",
                'category' => 'MODULE',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Remove only the flags introduced by this migration.
     */
    public function down(): void
    {
        if (! Schema::hasTable('app_remote_feature_flags')) {
            return;
        }

        DB::table('app_remote_feature_flags')->whereIn('feature_key', [
            'sales',
            'purchase',
            'inventory',
            'products',
            'barcode',
            'customers',
            'suppliers',
            'supplier_ledger',
            'employee_activity',
            'staff',
            'settings',
            'godowns',
            'racks',
            'businesses',
        ])->delete();
    }
};
