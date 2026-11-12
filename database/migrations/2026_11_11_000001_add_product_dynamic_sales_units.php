<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend central units table with metadata columns (non-destructive)
        if (Schema::hasTable('units')) {
            Schema::table('units', function (Blueprint $table) {
                if (! Schema::hasColumn('units', 'code')) {
                    $table->string('code', 60)->nullable()->after('id');
                }
                if (! Schema::hasColumn('units', 'name')) {
                    $table->string('name', 120)->nullable()->after('code');
                }
                if (! Schema::hasColumn('units', 'display_name')) {
                    $table->string('display_name', 120)->nullable()->after('name');
                }
                if (! Schema::hasColumn('units', 'symbol')) {
                    $table->string('symbol', 40)->nullable()->after('display_name');
                }
                if (! Schema::hasColumn('units', 'allow_fractional')) {
                    $table->boolean('allow_fractional')->default(false)->after('symbol');
                }
                if (! Schema::hasColumn('units', 'decimal_precision')) {
                    $table->unsignedTinyInteger('decimal_precision')->default(0)->after('allow_fractional');
                }
                if (! Schema::hasColumn('units', 'measurement_dimension')) {
                    $table->string('measurement_dimension', 40)->nullable()->after('decimal_precision');
                }
            });

            $this->seedStandardUnits();
        }

        // 2. Extend products and product_variants tables with unit configuration columns
        foreach (['products', 'product_variants'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'base_unit_id')) {
                    $table->string('base_unit_id', 64)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'purchase_unit_id')) {
                    $table->string('purchase_unit_id', 64)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'purchase_conversion_factor')) {
                    $table->decimal('purchase_conversion_factor', 16, 6)->default(1.0);
                }
                if (! Schema::hasColumn($tableName, 'default_sales_unit_id')) {
                    $table->string('default_sales_unit_id', 64)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'sales_conversion_factor')) {
                    $table->decimal('sales_conversion_factor', 16, 6)->default(1.0);
                }
                if (! Schema::hasColumn($tableName, 'purchase_price_per_unit')) {
                    $table->bigInteger('purchase_price_per_unit')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'selling_price_per_unit')) {
                    $table->bigInteger('selling_price_per_unit')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'allow_fractional_quantity')) {
                    $table->boolean('allow_fractional_quantity')->default(false);
                }
                if (! Schema::hasColumn($tableName, 'allow_manual_unit_override')) {
                    $table->boolean('allow_manual_unit_override')->default(false);
                }
                if (! Schema::hasColumn($tableName, 'unit_display_name')) {
                    $table->string('unit_display_name', 120)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'unit_symbol')) {
                    $table->string('unit_symbol', 40)->nullable();
                }
            });
        }

        // 3. Extend transactional line tables with unit & conversion snapshots
        foreach (['invoice_items', 'purchase_bill_items', 'return_items'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'unit_id')) {
                    $table->string('unit_id', 64)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'unit_symbol')) {
                    $table->string('unit_symbol', 40)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'unit_display_name')) {
                    $table->string('unit_display_name', 120)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'conversion_factor')) {
                    $table->decimal('conversion_factor', 16, 6)->default(1.0);
                }
                if (! Schema::hasColumn($tableName, 'base_quantity')) {
                    $table->decimal('base_quantity', 16, 3)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['invoice_items', 'purchase_bill_items', 'return_items'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['unit_id', 'unit_symbol', 'unit_display_name', 'conversion_factor', 'base_quantity'] as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        foreach (['products', 'product_variants'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach ([
                    'base_unit_id',
                    'purchase_unit_id',
                    'purchase_conversion_factor',
                    'default_sales_unit_id',
                    'sales_conversion_factor',
                    'purchase_price_per_unit',
                    'selling_price_per_unit',
                    'allow_fractional_quantity',
                    'allow_manual_unit_override',
                    'unit_display_name',
                    'unit_symbol',
                ] as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('units')) {
            Schema::table('units', function (Blueprint $table) {
                foreach (['code', 'name', 'display_name', 'symbol', 'allow_fractional', 'decimal_precision', 'measurement_dimension'] as $col) {
                    if (Schema::hasColumn('units', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }

    private function seedStandardUnits(): void
    {
        $now = now();
        $iso = $now->toIso8601String();
        $units = [
            ['id' => 'SYS_UNIT_CFT', 'code' => 'cft', 'name' => 'CFT', 'displayName' => 'Cubic Feet (CFT)', 'symbol' => 'CFT', 'allowFractional' => true, 'precision' => 2, 'dimension' => 'VOLUME'],
            ['id' => 'SYS_UNIT_KG', 'code' => 'kg', 'name' => 'KG', 'displayName' => 'Kilogram (KG)', 'symbol' => 'KG', 'allowFractional' => true, 'precision' => 3, 'dimension' => 'WEIGHT'],
            ['id' => 'SYS_UNIT_GRAM', 'code' => 'g', 'name' => 'Gram', 'displayName' => 'Gram', 'symbol' => 'g', 'allowFractional' => true, 'precision' => 2, 'dimension' => 'WEIGHT'],
            ['id' => 'SYS_UNIT_METRIC_TON', 'code' => 'MT', 'name' => 'Metric Tonne', 'displayName' => 'Metric Tonne (MT)', 'symbol' => 'MT', 'allowFractional' => true, 'precision' => 3, 'dimension' => 'WEIGHT'],
            ['id' => 'SYS_UNIT_PIECE', 'code' => 'pcs', 'name' => 'Piece (PCS)', 'displayName' => 'Piece (PCS)', 'symbol' => 'PCS', 'allowFractional' => false, 'precision' => 0, 'dimension' => 'COUNT'],
            ['id' => 'SYS_UNIT_BAG', 'code' => 'bag', 'name' => 'Bag', 'displayName' => 'Bag', 'symbol' => 'Bag', 'allowFractional' => false, 'precision' => 0, 'dimension' => 'COUNT'],
            ['id' => 'SYS_UNIT_LITRE', 'code' => 'L', 'name' => 'Litre', 'displayName' => 'Litre', 'symbol' => 'L', 'allowFractional' => true, 'precision' => 2, 'dimension' => 'VOLUME'],
            ['id' => 'SYS_UNIT_ML', 'code' => 'ML', 'name' => 'ML', 'displayName' => 'Millilitre (ML)', 'symbol' => 'ML', 'allowFractional' => true, 'precision' => 2, 'dimension' => 'VOLUME'],
            ['id' => 'SYS_UNIT_BOX', 'code' => 'box', 'name' => 'Box', 'displayName' => 'Box', 'symbol' => 'Box', 'allowFractional' => false, 'precision' => 0, 'dimension' => 'COUNT'],
            ['id' => 'SYS_UNIT_PACKET', 'code' => 'pkt', 'name' => 'Packet', 'displayName' => 'Packet', 'symbol' => 'Pkt', 'allowFractional' => false, 'precision' => 0, 'dimension' => 'COUNT'],
            ['id' => 'SYS_UNIT_BUNDLE', 'code' => 'bdl', 'name' => 'Bundle', 'displayName' => 'Bundle', 'symbol' => 'Bdl', 'allowFractional' => false, 'precision' => 0, 'dimension' => 'COUNT'],
            ['id' => 'SYS_UNIT_METRE', 'code' => 'm', 'name' => 'Metre', 'displayName' => 'Metre', 'symbol' => 'm', 'allowFractional' => true, 'precision' => 2, 'dimension' => 'LENGTH'],
            ['id' => 'SYS_UNIT_SQFT', 'code' => 'sqft', 'name' => 'Square Feet', 'displayName' => 'Square Feet', 'symbol' => 'SqFt', 'allowFractional' => true, 'precision' => 2, 'dimension' => 'AREA'],
            ['id' => 'SYS_UNIT_CUBIC_FEET', 'code' => 'cuft', 'name' => 'Cubic Feet', 'displayName' => 'Cubic Feet', 'symbol' => 'CuFt', 'allowFractional' => true, 'precision' => 2, 'dimension' => 'VOLUME'],
            ['id' => 'SYS_UNIT_DOZEN', 'code' => 'doz', 'name' => 'Dozen', 'displayName' => 'Dozen', 'symbol' => 'Dozen', 'allowFractional' => false, 'precision' => 0, 'dimension' => 'COUNT'],
            ['id' => 'SYS_UNIT_BRASS', 'code' => 'brass', 'name' => 'Brass (100 cft)', 'displayName' => 'Brass (100 CFT)', 'symbol' => 'Brass', 'allowFractional' => true, 'precision' => 2, 'dimension' => 'VOLUME'],
            ['id' => 'SYS_UNIT_THOUSAND_NOS', 'code' => '1000 nos', 'name' => '1000 Pieces (Nos)', 'displayName' => '1000 Pieces', 'symbol' => '1000 PCS', 'allowFractional' => false, 'precision' => 0, 'dimension' => 'COUNT'],
        ];

        foreach ($units as $u) {
            $exists = DB::table('units')->where('id', $u['id'])->exists();
            if ($exists) {
                DB::table('units')->where('id', $u['id'])->update([
                    'code' => $u['code'],
                    'name' => $u['name'],
                    'display_name' => $u['displayName'],
                    'symbol' => $u['symbol'],
                    'allow_fractional' => $u['allowFractional'] ? 1 : 0,
                    'decimal_precision' => $u['precision'],
                    'measurement_dimension' => $u['dimension'],
                ]);
                continue;
            }

            DB::table('units')->insert([
                'id' => $u['id'],
                'document_path' => "units/{$u['id']}",
                'code' => $u['code'],
                'name' => $u['name'],
                'display_name' => $u['displayName'],
                'symbol' => $u['symbol'],
                'allow_fractional' => $u['allowFractional'] ? 1 : 0,
                'decimal_precision' => $u['precision'],
                'measurement_dimension' => $u['dimension'],
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'payload' => json_encode([
                    '_documentId' => $u['id'],
                    '_documentPath' => "units/{$u['id']}",
                    'fields' => [
                        'unitId' => $u['id'],
                        'code' => $u['code'],
                        'name' => $u['name'],
                        'displayName' => $u['displayName'],
                        'symbol' => $u['symbol'],
                        'allowFractional' => $u['allowFractional'],
                        'precision' => $u['precision'],
                        'measurementDimension' => $u['dimension'],
                        'scopeType' => 'SYSTEM',
                        'status' => 'ACTIVE',
                        'createdAt' => $iso,
                        'updatedAt' => $iso,
                    ],
                ]),
            ]);
        }
    }
};
