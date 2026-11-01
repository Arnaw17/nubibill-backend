<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * All core document-backed tables supporting Products, Inventory, Storage, Suppliers,
     * Customers, Employees, Attendance, and Business Configuration.
     */
    protected array $tables = [
        'products',
        'product_variants',
        'outlet_products',
        'storage_locations',
        'godown_code_reservations',
        'suppliers',
        'supplier_businesses',
        'supplier_ledger_entries',
        'customers',
        'customer_ledger_entries',
        'payments',
        'staff_attendance',
        'employees',
        'business_types',
        'business_sub_types',
        'business_name_reservations',
        'inventory_batches',
        'inventory_batch_movements',
        'brands',
        'product_categories',
        'product_subcategories',
        'units',
        'hsn_codes',
        'pack_sizes',
        'central_product_master_verified',
        'subcategory_attribute_templates',
        'goods_receipts',
        'audit_logs',
        'roles',
        'permissions',
        'document_sequences',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) {
                    $table->string('id', 255)->primary();
                    $table->string('document_path', 500)->nullable();
                    $table->string('business_id', 255)->nullable()->index();
                    $table->string('outlet_id', 255)->nullable()->index();
                    $table->string('tenant_id', 255)->nullable()->index();
                    $table->string('product_id', 255)->nullable()->index();
                    $table->string('status', 100)->default('active')->index();
                    $table->tinyInteger('is_deleted')->default(0)->index();
                    $table->json('payload')->nullable();
                    $table->timestamps();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
