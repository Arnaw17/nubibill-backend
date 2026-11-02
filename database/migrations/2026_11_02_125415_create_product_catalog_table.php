<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'master_product_id')) {
                $table->string('master_product_id')->nullable()->unique();
            }
            if (!Schema::hasColumn('products', 'product_name')) {
                $table->string('product_name')->nullable();
            }
            if (!Schema::hasColumn('products', 'brand_name')) {
                $table->string('brand_name')->nullable();
            }
            if (!Schema::hasColumn('products', 'category_name')) {
                $table->string('category_name')->nullable();
            }
            if (!Schema::hasColumn('products', 'subcategory_name')) {
                $table->string('subcategory_name')->nullable();
            }
            if (!Schema::hasColumn('products', 'pack_sizes')) {
                $table->string('pack_sizes')->nullable();
            }
            if (!Schema::hasColumn('products', 'finish')) {
                $table->string('finish')->nullable();
            }
            if (!Schema::hasColumn('products', 'colour')) {
                $table->string('colour')->nullable();
            }
            if (!Schema::hasColumn('products', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('products', 'image_url')) {
                $table->text('image_url')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = [
                'master_product_id',
                'product_name',
                'brand_name',
                'category_name',
                'subcategory_name',
                'pack_sizes',
                'finish',
                'colour',
                'description',
                'image_url'
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};