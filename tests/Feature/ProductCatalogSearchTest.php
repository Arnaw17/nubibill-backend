<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_global_catalog_rows_by_scalar_columns_and_master_id(): void
    {
        $this->withoutMiddleware();
        $this->insertProduct([
            'id' => 41001,
            'master_product_id' => 'catalog-item-41001',
            'product_name' => 'Catalog Search Widget',
            'brand_name' => 'Northwind Brand',
            'category_name' => 'Workshop Supplies',
        ]);

        foreach (['Catalog Search Widget', 'Northwind Brand', 'Workshop Supplies', 'catalog-item-41001'] as $search) {
            $this->getJson('/api/v1/products?business_id=business-search-test&search='.urlencode($search))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.masterProductId', 'catalog-item-41001')
                ->assertJsonPath('data.0.name', 'Catalog Search Widget')
                ->assertJsonPath('data.0.brand', 'Northwind Brand')
                ->assertJsonPath('data.0.category', 'Workshop Supplies');
        }
    }

    public function test_search_keeps_legacy_payload_products_and_business_scope(): void
    {
        $this->withoutMiddleware();
        $this->insertProduct([
            'id' => 41002,
            'business_id' => 'business-search-test',
            'payload' => json_encode(['fields' => [
                'productId' => 'legacy-widget',
                'productName' => 'Legacy Search Widget',
                'brand' => 'Legacy Brand',
            ]]),
        ]);
        $this->insertProduct([
            'id' => 41003,
            'business_id' => 'different-business',
            'payload' => json_encode(['fields' => [
                'productId' => 'private-widget',
                'productName' => 'Legacy Search Widget Private',
            ]]),
        ]);

        $this->getJson('/api/v1/products?business_id=business-search-test&search=Legacy%20Search%20Widget')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.productId', 'legacy-widget');
    }

    /** @param array<string, mixed> $attributes */
    private function insertProduct(array $attributes): void
    {
        DB::table('products')->insert(array_merge([
            'document_path' => null,
            'business_id' => null,
            'outlet_id' => null,
            'tenant_id' => null,
            'product_id' => null,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
