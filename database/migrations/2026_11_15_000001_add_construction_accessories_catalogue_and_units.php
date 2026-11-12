<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $iso = $now->toIso8601String();

        // 1. Seed the 6 missing standard measurement units for Construction Accessories
        if (Schema::hasTable('units')) {
            $newUnits = [
                [
                    'id' => 'SYS_UNIT_CM',
                    'code' => 'cm',
                    'name' => 'Centimetre',
                    'displayName' => 'Centimetre (cm)',
                    'symbol' => 'cm',
                    'allowFractional' => true,
                    'precision' => 2,
                    'dimension' => 'LENGTH',
                ],
                [
                    'id' => 'SYS_UNIT_FOOT',
                    'code' => 'ft',
                    'name' => 'Foot',
                    'displayName' => 'Foot (ft)',
                    'symbol' => 'ft',
                    'allowFractional' => true,
                    'precision' => 2,
                    'dimension' => 'LENGTH',
                ],
                [
                    'id' => 'SYS_UNIT_ROLL',
                    'code' => 'roll',
                    'name' => 'Roll',
                    'displayName' => 'Roll',
                    'symbol' => 'Roll',
                    'allowFractional' => false,
                    'precision' => 0,
                    'dimension' => 'COUNT',
                ],
                [
                    'id' => 'SYS_UNIT_PAIR',
                    'code' => 'pair',
                    'name' => 'Pair',
                    'displayName' => 'Pair',
                    'symbol' => 'Pair',
                    'allowFractional' => false,
                    'precision' => 0,
                    'dimension' => 'COUNT',
                ],
                [
                    'id' => 'SYS_UNIT_SET',
                    'code' => 'set',
                    'name' => 'Set',
                    'displayName' => 'Set',
                    'symbol' => 'Set',
                    'allowFractional' => false,
                    'precision' => 0,
                    'dimension' => 'COUNT',
                ],
                [
                    'id' => 'SYS_UNIT_CARTON',
                    'code' => 'carton',
                    'name' => 'Carton',
                    'displayName' => 'Carton',
                    'symbol' => 'Carton',
                    'allowFractional' => false,
                    'precision' => 0,
                    'dimension' => 'COUNT',
                ],
            ];

            $hasExtendedCols = Schema::hasColumn('units', 'code')
                && Schema::hasColumn('units', 'measurement_dimension');

            foreach ($newUnits as $u) {
                $exists = DB::table('units')->where('id', $u['id'])->exists();
                if ($exists) {
                    if ($hasExtendedCols) {
                        DB::table('units')->where('id', $u['id'])->update([
                            'code' => $u['code'],
                            'name' => $u['name'],
                            'display_name' => $u['displayName'],
                            'symbol' => $u['symbol'],
                            'allow_fractional' => $u['allowFractional'] ? 1 : 0,
                            'decimal_precision' => $u['precision'],
                            'measurement_dimension' => $u['dimension'],
                            'updated_at' => $now,
                        ]);
                    }
                    continue;
                }

                $row = [
                    'id' => $u['id'],
                    'document_path' => "units/{$u['id']}",
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
                ];

                if ($hasExtendedCols) {
                    $row['code'] = $u['code'];
                    $row['name'] = $u['name'];
                    $row['display_name'] = $u['displayName'];
                    $row['symbol'] = $u['symbol'];
                    $row['allow_fractional'] = $u['allowFractional'] ? 1 : 0;
                    $row['decimal_precision'] = $u['precision'];
                    $row['measurement_dimension'] = $u['dimension'];
                }

                DB::table('units')->insert($row);
            }
        }

        // 2. Ensure SYS_CAT_CONST_ACCESSORIES category exists
        if (Schema::hasTable('product_categories')) {
            $catId = 'SYS_CAT_CONST_ACCESSORIES';
            if (! DB::table('product_categories')->where('id', $catId)->exists()) {
                DB::table('product_categories')->insert([
                    'id' => $catId,
                    'document_path' => "product_categories/{$catId}",
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $catId,
                        '_documentPath' => "product_categories/{$catId}",
                        'fields' => [
                            'categoryId' => $catId,
                            'code' => 'ACCESSORIES',
                            'name' => 'Construction Accessories',
                            'scopeType' => 'SYSTEM',
                            'status' => 'ACTIVE',
                            'createdAt' => $iso,
                            'updatedAt' => $iso,
                        ],
                    ]),
                ]);
            }
        }

        // 3. Seed the 5 Construction Accessories subcategories
        if (Schema::hasTable('product_subcategories')) {
            $subcategories = [
                ['id' => 'SYS_SUB_ACC_PAINT', 'name' => 'Paint Accessories'],
                ['id' => 'SYS_SUB_ACC_FASTENERS', 'name' => 'Fasteners & Hardware'],
                ['id' => 'SYS_SUB_ACC_PLUMBING', 'name' => 'Plumbing Accessories'],
                ['id' => 'SYS_SUB_ACC_ELECTRICAL', 'name' => 'Electrical Accessories'],
                ['id' => 'SYS_SUB_ACC_MASONRY', 'name' => 'Masonry & General Accessories'],
            ];

            foreach ($subcategories as $sub) {
                if (DB::table('product_subcategories')->where('id', $sub['id'])->exists()) {
                    continue;
                }
                DB::table('product_subcategories')->insert([
                    'id' => $sub['id'],
                    'document_path' => "product_subcategories/{$sub['id']}",
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $sub['id'],
                        '_documentPath' => "product_subcategories/{$sub['id']}",
                        'fields' => [
                            'subcategoryId' => $sub['id'],
                            'categoryId' => 'SYS_CAT_CONST_ACCESSORIES',
                            'name' => $sub['name'],
                            'scopeType' => 'SYSTEM',
                            'status' => 'ACTIVE',
                            'createdAt' => $iso,
                            'updatedAt' => $iso,
                        ],
                    ]),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive per repository migration guidelines.
    }
};
