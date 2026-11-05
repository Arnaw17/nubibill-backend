<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables with tenant_id column to backfill.
     */
    protected array $tables = [
        'products',
        'product_variants',
        'outlet_products',
        'customers',
        'customer_balances',
        'customer_ledger_entries',
        'suppliers',
        'supplier_businesses',
        'supplier_balances',
        'supplier_ledger_entries',
        'invoices',
        'invoice_items',
        'payments',
        'purchase_bills',
        'purchase_bill_items',
        'goods_receipts',
        'inventory_batches',
        'inventory_batch_movements',
        'stock_balances',
        'stock_movements',
        'storage_locations',
        'employees',
        'staff_attendance',
        'document_sequences',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $businessOwners = DB::table('businesses')->get()->mapWithKeys(function ($b) {
            $p = json_decode($b->payload, true);
            $owner = $p['fields']['ownerUid'] ?? null;
            return [$b->id => $owner];
        })->toArray();

        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $rows = DB::table($table)->whereNull('tenant_id')->get();
            foreach ($rows as $row) {
                $tenantId = null;
                if (!empty($row->payload)) {
                    $p = json_decode($row->payload, true);
                    $tenantId = $p['fields']['tenantId']
                        ?? $p['fields']['ownerUid']
                        ?? $p['fields']['actorUid']
                        ?? $p['fields']['userId']
                        ?? null;
                }

                if (!$tenantId && !empty($row->business_id) && isset($businessOwners[$row->business_id])) {
                    $tenantId = $businessOwners[$row->business_id];
                }

                if ($tenantId) {
                    DB::table($table)->where('id', $row->id)->update(['tenant_id' => $tenantId]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
