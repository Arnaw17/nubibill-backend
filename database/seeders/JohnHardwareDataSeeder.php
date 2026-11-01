<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JohnHardwareDataSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = 'BUS-BFMC378UAMES';
        $outletId = 'OUT-318DJT3737PW';
        $tenantId = '01a168d5-cd65-734c-b20d-34941ef36c8b';
        $now = now();
        $nowStr = $now->toDateTimeString();
        $nowIso = $now->toISOString();

        // 1. Brands
        $brands = [
            ['id' => 'SYS_BRAND_ASIAN_PAINTS', 'name' => 'Asian Paints'],
            ['id' => 'SYS_BRAND_BERGER', 'name' => 'Berger'],
        ];
        foreach ($brands as $b) {
            DB::table('brands')->updateOrInsert(
                ['id' => $b['id']],
                [
                    'document_path' => 'brands/' . $b['id'],
                    'business_id' => $businessId,
                    'status' => 'active',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        'brandId' => $b['id'],
                        'name' => $b['name'],
                        'status' => 'ACTIVE',
                    ]),
                ]
            );
        }

        // 2. Categories
        $categories = [
            ['id' => 'CAT_EXTERIOR_WALL_COATINGS', 'name' => 'Exterior Wall Coatings'],
            ['id' => 'SYS_CATEGORY_PROTECTIVE_INDUSTRIAL_COATINGS', 'name' => 'Protective & Industrial Coatings'],
            ['id' => 'CAT_ADMIXTURES', 'name' => 'Admixtures'],
            ['id' => 'CAT_WATERPROOFING', 'name' => 'Waterproofing'],
        ];
        foreach ($categories as $c) {
            DB::table('product_categories')->updateOrInsert(
                ['id' => $c['id']],
                [
                    'document_path' => 'product_categories/' . $c['id'],
                    'business_id' => $businessId,
                    'status' => 'active',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        'categoryId' => $c['id'],
                        'name' => $c['name'],
                        'status' => 'ACTIVE',
                    ]),
                ]
            );
        }

        // 3. Storage Locations (Godowns & Racks)
        $locations = [
            [
                'id' => 'GODOWN-MAIN',
                'name' => 'Main Godown',
                'code' => 'GD-01',
                'type' => 'GODOWN',
                'parent' => null,
            ],
            [
                'id' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                'name' => 'Rack A-01',
                'code' => 'RACK-01',
                'type' => 'RACK',
                'parent' => 'GODOWN-MAIN',
            ],
            [
                'id' => 'LOC-RACK-02',
                'name' => 'Rack B-01',
                'code' => 'RACK-02',
                'type' => 'RACK',
                'parent' => 'GODOWN-MAIN',
            ],
        ];
        foreach ($locations as $loc) {
            DB::table('storage_locations')->updateOrInsert(
                ['id' => $loc['id']],
                [
                    'document_path' => 'storage_locations/' . $loc['id'],
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'tenant_id' => $tenantId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $loc['id'],
                        'fields' => [
                            'storageLocationId' => $loc['id'],
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'tenantId' => $tenantId,
                            'name' => $loc['name'],
                            'code' => $loc['code'],
                            'type' => $loc['type'],
                            'status' => 'ACTIVE',
                            'parentLocationId' => $loc['parent'],
                            'godownScope' => 'OUTLET',
                        ],
                    ]),
                ]
            );
        }

        // 4. Products & Variants (4 SKUs)
        $productList = [
            [
                'id' => 'PROD-67KUIS',
                'code' => 'PRD-67KUIS',
                'name' => 'Ace Suprema – High-Performance Exterior Emulsion for Lasting Wall Protection Ready Colour',
                'brand' => 'Asian Paints',
                'brandId' => 'SYS_BRAND_ASIAN_PAINTS',
                'cat' => 'Exterior Wall Coatings',
                'catId' => 'CAT_EXTERIOR_WALL_COATINGS',
                'hsn' => '4220',
                'gst' => 1800,
                'variant' => [
                    'id' => 'VAR-BUY1RM',
                    'sku' => 'SKU-BUY1RM',
                    'barcode' => '700000000001',
                    'name' => 'Ace Suprema – High-Performance Exterior Emulsion - Red - 1 Litre Can',
                    'unit' => 'Can',
                    'mrp' => 500,
                    'selling' => 400,
                    'rate' => 300,
                    'stock' => 6,
                    'valuation' => 1770.35,
                    'rack' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                ],
            ],
            [
                'id' => 'PROD-EZTFWM',
                'code' => 'PRD-EZTFWM',
                'name' => 'Berger PU Surfacer PCN-100',
                'brand' => 'Berger',
                'brandId' => 'SYS_BRAND_BERGER',
                'cat' => 'Protective & Industrial Coatings',
                'catId' => 'SYS_CATEGORY_PROTECTIVE_INDUSTRIAL_COATINGS',
                'hsn' => '2004',
                'gst' => 1800,
                'variant' => [
                    'id' => 'VAR-EZTFWM',
                    'sku' => 'SKU-EZTFWM',
                    'barcode' => '700000000002',
                    'name' => 'Berger PU Surfacer PCN-100 - Can',
                    'unit' => 'Can',
                    'mrp' => 600,
                    'selling' => 480,
                    'rate' => 350,
                    'stock' => 28,
                    'valuation' => 9800.00,
                    'rack' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                ],
            ],
            [
                'id' => 'PROD-NFO8FP',
                'code' => 'PRD-NFO8FP',
                'name' => 'Cementix Plus',
                'brand' => 'Berger',
                'brandId' => 'SYS_BRAND_BERGER',
                'cat' => 'Admixtures',
                'catId' => 'CAT_ADMIXTURES',
                'hsn' => '3824',
                'gst' => 1800,
                'variant' => [
                    'id' => 'VAR-NFO8FP',
                    'sku' => 'SKU-NFO8FP',
                    'barcode' => '700000000003',
                    'name' => 'Cementix Plus - Can',
                    'unit' => 'Can',
                    'mrp' => 300,
                    'selling' => 250,
                    'rate' => 180,
                    'stock' => 41,
                    'valuation' => 7380.00,
                    'rack' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                ],
            ],
            [
                'id' => 'PROD-E1K4G0',
                'code' => 'PRD-E1K4G0',
                'name' => 'ASIAN PAINTS DAMP PROOF',
                'brand' => 'Asian Paints',
                'brandId' => 'SYS_BRAND_ASIAN_PAINTS',
                'cat' => 'Waterproofing',
                'catId' => 'CAT_WATERPROOFING',
                'hsn' => '3214',
                'gst' => 1800,
                'variant' => [
                    'id' => 'VAR-HET0CE',
                    'sku' => 'SKU-HET0CE',
                    'barcode' => 'damproof 20',
                    'name' => 'ASIAN PAINTS DAMP PROOF - 20L Bucket',
                    'unit' => 'Bucket',
                    'mrp' => 1200,
                    'selling' => 1000,
                    'rate' => 800,
                    'stock' => 25,
                    'valuation' => 23600.00,
                    'rack' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                ],
            ],
        ];

        foreach ($productList as $p) {
            DB::table('products')->updateOrInsert(
                ['id' => $p['id']],
                [
                    'document_path' => 'products/' . $p['id'],
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'tenant_id' => $tenantId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $p['id'],
                        'fields' => [
                            'productId' => $p['id'],
                            'productCode' => $p['code'],
                            'productName' => $p['name'],
                            'name' => $p['name'],
                            'brand' => $p['brand'],
                            'brandId' => $p['brandId'],
                            'category' => $p['cat'],
                            'categoryName' => $p['cat'],
                            'categoryId' => $p['catId'],
                            'hsnCode' => $p['hsn'],
                            'defaultGstRateBps' => $p['gst'],
                            'status' => 'ACTIVE',
                            'businessId' => $businessId,
                            'tenantId' => $tenantId,
                            'createdAt' => $nowIso,
                            'updatedAt' => $nowIso,
                        ],
                    ]),
                ]
            );

            // Variant
            $v = $p['variant'];
            DB::table('product_variants')->updateOrInsert(
                ['id' => $v['id']],
                [
                    'document_path' => 'product_variants/' . $v['id'],
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'tenant_id' => $tenantId,
                    'product_id' => $p['id'],
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $v['id'],
                        'fields' => [
                            'variantId' => $v['id'],
                            'productId' => $p['id'],
                            'businessId' => $businessId,
                            'tenantId' => $tenantId,
                            'name' => $v['name'],
                            'displayName' => $v['name'],
                            'sku' => $v['sku'],
                            'barcode' => $v['barcode'],
                            'baseUnitId' => $v['unit'],
                            'commercialUnitName' => $v['unit'],
                            'purchaseRate' => $v['rate'],
                            'sellingPrice' => $v['selling'],
                            'mrp' => $v['mrp'],
                            'defaultGstRateBps' => $p['gst'],
                            'hsnCode' => $p['hsn'],
                            'status' => 'ACTIVE',
                            'rackId' => $v['rack'],
                            'attributeValues' => [
                                'rack' => $v['rack'],
                                'soldAs' => $v['unit'],
                            ],
                            'createdAt' => $nowIso,
                            'updatedAt' => $nowIso,
                        ],
                    ]),
                ]
            );

            // Outlet Placement
            $opId = 'OP_' . substr(md5($p['id'] . $outletId), 0, 16);
            DB::table('outlet_products')->updateOrInsert(
                ['id' => $opId],
                [
                    'document_path' => 'outlet_products/' . $opId,
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'tenant_id' => $tenantId,
                    'product_id' => $p['id'],
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $opId,
                        'fields' => [
                            'outletProductId' => $opId,
                            'productId' => $p['id'],
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'tenantId' => $tenantId,
                            'status' => 'ACTIVE',
                        ],
                    ]),
                ]
            );

            // Stock Balance
            $sbId = "{$tenantId}_{$outletId}_{$v['id']}";
            DB::table('stock_balances')->updateOrInsert(
                ['id' => $sbId],
                [
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'product_id' => $p['id'],
                    'variant_id' => $v['id'],
                    'quantity_on_hand' => $v['stock'],
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $sbId,
                        'fields' => [
                            'stockBalanceId' => $sbId,
                            'variantId' => $v['id'],
                            'productId' => $p['id'],
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'tenantId' => $tenantId,
                            'quantityOnHand' => $v['stock'],
                            'currentStock' => $v['stock'],
                            'totalValue' => $v['valuation'],
                            'averageCost' => $v['rate'],
                            'status' => 'ACTIVE',
                        ],
                    ]),
                ]
            );
        }

        // 5. Suppliers
        $suppliers = [
            [
                'id' => 'SUP-ASIAN-PAINTS',
                'name' => 'Asian Paints',
                'phone' => '9876543220',
                'email' => 'asianpaints@supplier.com',
                'gstin' => '27AAACA1234A1Z5',
                'address' => 'Asian Paints Logistics Hub, Maharashtra',
            ],
            [
                'id' => 'SUP-BERGER-PAINTS',
                'name' => 'Berger Paints',
                'phone' => '9876543221',
                'email' => 'berger@supplier.com',
                'gstin' => '27AAACB1234B1Z4',
                'address' => 'Berger Depot, Industrial Area',
            ],
        ];

        foreach ($suppliers as $s) {
            DB::table('suppliers')->updateOrInsert(
                ['id' => $s['id']],
                [
                    'document_path' => 'suppliers/' . $s['id'],
                    'business_id' => $businessId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $s['id'],
                        'fields' => [
                            'supplierId' => $s['id'],
                            'name' => $s['name'],
                            'mobile' => $s['phone'],
                            'phone' => $s['phone'],
                            'email' => $s['email'],
                            'gstin' => $s['gstin'],
                            'address' => $s['address'],
                            'stateCode' => '27',
                            'status' => 'ACTIVE',
                            'businessId' => $businessId,
                            'tenantId' => $tenantId,
                        ],
                    ]),
                ]
            );

            // Supplier Business Relation
            $sbRelId = "{$s['id']}_{$businessId}";
            DB::table('supplier_businesses')->updateOrInsert(
                ['id' => $sbRelId],
                [
                    'document_path' => 'supplier_businesses/' . $sbRelId,
                    'business_id' => $businessId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $sbRelId,
                        'fields' => [
                            'supplierBusinessId' => $sbRelId,
                            'supplierId' => $s['id'],
                            'businessId' => $businessId,
                            'openingBalance' => 0,
                            'status' => 'ACTIVE',
                        ],
                    ]),
                ]
            );
        }

        // 6. Purchases
        // Bill INV-2026-005 from Asian Paints
        $pb1 = 'PB-2026-005';
        DB::table('purchase_bills')->updateOrInsert(
            ['id' => $pb1],
            [
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'supplier_id' => 'SUP-ASIAN-PAINTS',
                'status' => 'APPROVED',
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'payload' => json_encode([
                    '_documentId' => $pb1,
                    'fields' => [
                        'purchaseBillId' => $pb1,
                        'purchaseNumber' => 'PUR-2026-0001',
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'supplierId' => 'SUP-ASIAN-PAINTS',
                        'supplierName' => 'Asian Paints',
                        'supplierInvoiceNumber' => 'INV-2026-005',
                        'invoiceDate' => '2026-11-01',
                        'purchaseDate' => '2026-11-01',
                        'subtotal' => 1500,
                        'taxableAmount' => 1500,
                        'cgstAmount' => 135,
                        'sgstAmount' => 135,
                        'grandTotal' => 1770,
                        'paymentStatus' => 'PAID',
                        'status' => 'APPROVED',
                        'items' => [
                            [
                                'purchaseBillItemId' => 'PBI-001',
                                'productId' => 'PROD-67KUIS',
                                'variantId' => 'VAR-BUY1RM',
                                'productName' => 'Ace Suprema – High-Performance Exterior Emulsion',
                                'quantity' => 5,
                                'rate' => 300,
                                'mrp' => 500,
                                'sellingPrice' => 400,
                                'taxableAmount' => 1500,
                                'taxAmount' => 270,
                                'lineTotal' => 1770,
                                'storageLocationId' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                                'storageLocationName' => 'Rack A-01',
                            ],
                        ],
                    ],
                ]),
            ]
        );

        // Bill inv-01 from Berger Paints
        $pb2 = 'PB-2026-001';
        DB::table('purchase_bills')->updateOrInsert(
            ['id' => $pb2],
            [
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'supplier_id' => 'SUP-BERGER-PAINTS',
                'status' => 'APPROVED',
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'payload' => json_encode([
                    '_documentId' => $pb2,
                    'fields' => [
                        'purchaseBillId' => $pb2,
                        'purchaseNumber' => 'PUR-2026-0002',
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'supplierId' => 'SUP-BERGER-PAINTS',
                        'supplierName' => 'Berger Paints',
                        'supplierInvoiceNumber' => 'inv-01',
                        'invoiceDate' => '2026-10-30',
                        'purchaseDate' => '2026-10-30',
                        'subtotal' => 40780,
                        'taxableAmount' => 40780,
                        'cgstAmount' => 3670,
                        'sgstAmount' => 3670,
                        'grandTotal' => 48120,
                        'paymentStatus' => 'PAID',
                        'status' => 'APPROVED',
                        'items' => [
                            [
                                'purchaseBillItemId' => 'PBI-002',
                                'productId' => 'PROD-EZTFWM',
                                'variantId' => 'VAR-EZTFWM',
                                'productName' => 'Berger PU Surfacer PCN-100',
                                'quantity' => 28,
                                'rate' => 350,
                                'storageLocationId' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                            ],
                            [
                                'purchaseBillItemId' => 'PBI-003',
                                'productId' => 'PROD-NFO8FP',
                                'variantId' => 'VAR-NFO8FP',
                                'productName' => 'Cementix Plus',
                                'quantity' => 41,
                                'rate' => 180,
                                'storageLocationId' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                            ],
                            [
                                'purchaseBillItemId' => 'PBI-004',
                                'productId' => 'PROD-E1K4G0',
                                'variantId' => 'VAR-HET0CE',
                                'productName' => 'ASIAN PAINTS DAMP PROOF',
                                'quantity' => 25,
                                'rate' => 800,
                                'storageLocationId' => 'LOC-5PRUTIC0ZWX2VDBPWLLWQORX17VKNJT',
                            ],
                        ],
                    ],
                ]),
            ]
        );

        // 7. Customers & Sales
        $customerId = 'CUS-ZPYRUTS0VV9P';
        DB::table('customers')->updateOrInsert(
            ['id' => $customerId],
            [
                'document_path' => 'customers/' . $customerId,
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'tenant_id' => $tenantId,
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'payload' => json_encode([
                    '_documentId' => $customerId,
                    'fields' => [
                        'customerId' => $customerId,
                        'customerName' => 'shiv',
                        'name' => 'shiv',
                        'phone' => '9876543234',
                        'address' => '502 Market Road, Area 1',
                        'stateCode' => '27',
                        'status' => 'ACTIVE',
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'tenantId' => $tenantId,
                    ],
                ]),
            ]
        );

        // Invoice JOHNHA-26-00010
        $invoiceId = 'REQ_1793293954861193';
        DB::table('invoices')->updateOrInsert(
            ['id' => $invoiceId],
            [
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'invoice_number' => 'JOHNHA-26-00010',
                'customer_id' => $customerId,
                'total_amount' => 9440.00,
                'status' => 'PAID',
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'payload' => json_encode([
                    '_documentId' => $invoiceId,
                    'fields' => [
                        'invoiceId' => $invoiceId,
                        'invoiceNumber' => 'JOHNHA-26-00010',
                        'customerId' => $customerId,
                        'customerName' => 'shiv',
                        'customerPhone' => '9876543234',
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'subtotal' => 8000,
                        'taxableAmount' => 8000,
                        'cgstAmount' => 720,
                        'sgstAmount' => 720,
                        'totalAmount' => 9440,
                        'paymentMode' => 'CASH',
                        'status' => 'PAID',
                        'items' => [
                            [
                                'id' => 'ITEM-001',
                                'productId' => 'PROD-67KUIS',
                                'variantId' => 'VAR-BUY1RM',
                                'productName' => 'Ace Suprema – High-Performance Exterior Emulsion',
                                'quantity' => 20,
                                'unitPrice' => 400,
                                'taxRate' => 18,
                                'taxableAmount' => 8000,
                                'cgstAmount' => 720,
                                'sgstAmount' => 720,
                                'lineTotal' => 9440,
                            ],
                        ],
                    ],
                ]),
            ]
        );

        // Return / Credit Note SR-0001
        $returnId = 'a51d06c9-12c4-4d25-b090-19d3af6a70d9';
        DB::table('returns')->updateOrInsert(
            ['id' => $returnId],
            [
                'user_id' => $tenantId,
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'return_no' => 'SR-0001',
                'type' => 'sale_return',
                'original_bill_id' => $invoiceId,
                'original_bill_no' => 'JOHNHA-26-00010',
                'return_date' => '2026-11-01',
                'party_id' => $customerId,
                'party_name' => 'shiv',
                'party_phone' => '9876543234',
                'party_state_code' => '27',
                'business_state_code' => '27',
                'refund_mode' => 'cash',
                'subtotal' => 400.00,
                'cgst_total' => 36.00,
                'sgst_total' => 36.00,
                'tax_total' => 72.00,
                'grand_total' => 472.00,
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 8. Employees & Staff
        $employees = [
            [
                'id' => 'EMP-N0ICGQ19EJDC3NRLF9CKJZWSXW8ZMB2G',
                'name' => 'Arnaw',
                'email' => 'arnaw@gmail.com',
                'mobile' => '+919876523238',
                'role' => 'ROLE_MANAGER',
                'loginEnabled' => true,
            ],
            [
                'id' => 'EMP-CRFVNR1KSMSCKR5XENFW7TRGYUZTSWK0',
                'name' => 'aditya@gmail.cm',
                'email' => 'aditya@gmail.com',
                'mobile' => '+919876534212',
                'role' => 'ROLE_STAFF',
                'loginEnabled' => true,
            ],
            [
                'id' => 'EMP-PWP0VCDVK1ETI5QKMDLNWP1I8AQSLGV3',
                'name' => 'xyz',
                'email' => '',
                'mobile' => '',
                'role' => 'ROLE_STAFF',
                'loginEnabled' => false,
            ],
            [
                'id' => 'EMP-C9E23C679F5D46BC8E9FAF43D82F793B',
                'name' => 'xyz',
                'email' => '',
                'mobile' => '',
                'role' => 'ROLE_TEMPORARY',
                'loginEnabled' => false,
            ],
        ];

        foreach ($employees as $emp) {
            DB::table('employees')->updateOrInsert(
                ['id' => $emp['id']],
                [
                    'document_path' => 'employees/' . $emp['id'],
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'tenant_id' => $tenantId,
                    'status' => 'active',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $emp['id'],
                        'fields' => [
                            'employeeId' => $emp['id'],
                            'name' => $emp['name'],
                            'email' => $emp['email'],
                            'mobile' => $emp['mobile'],
                            'ownerUid' => $tenantId,
                            'businessIds' => [$businessId],
                            'outletIds' => [$outletId],
                            'roleIds' => [$emp['role']],
                            'status' => 'active',
                            'loginEnabled' => $emp['loginEnabled'],
                        ],
                    ]),
                ]
            );

            // Attendance entry for today
            $attId = 'ATT_' . substr(md5($emp['id'] . $now->toDateString()), 0, 16);
            DB::table('staff_attendance')->updateOrInsert(
                ['id' => $attId],
                [
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'active',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        'id' => $attId,
                        'employeeId' => $emp['id'],
                        'employeeName' => $emp['name'],
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'workDate' => $now->toDateString(),
                        'checkIn' => '09:00:00',
                        'checkOut' => '18:00:00',
                        'status' => 'PRESENT',
                    ]),
                ]
            );
        }

        // 9. Expenses
        $expenses = [
            ['id' => 'EXP-UDO9YGKBWD', 'amount' => 50000, 'date' => '2026-10-26'],
            ['id' => 'EXP-F2XYJBT6JZ', 'amount' => 10000, 'date' => '2026-10-29'],
            ['id' => 'EXP-XDRMX5Z09O', 'amount' => 10000, 'date' => '2026-10-31'],
        ];
        foreach ($expenses as $exp) {
            DB::table('expenses')->updateOrInsert(
                ['id' => $exp['id']],
                [
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'title' => 'Staff Refreshments',
                    'amount_paise' => $exp['amount'],
                    'category' => 'Tea & Refreshments',
                    'payment_mode' => 'CASH',
                    'expense_date' => $exp['date'],
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        'expenseId' => $exp['id'],
                        'title' => 'Staff Refreshments',
                        'category' => 'Tea & Refreshments',
                        'amountPaise' => $exp['amount'],
                        'expenseDate' => $exp['date'],
                        'paymentMode' => 'CASH',
                        'status' => 'ACTIVE',
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                    ]),
                ]
            );
        }
    }
}
