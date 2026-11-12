<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_attributes')) {
            Schema::table('product_attributes', function (Blueprint $table) {
                if (! Schema::hasColumn('product_attributes', 'category_id')) {
                    $table->string('category_id', 100)->nullable()->index();
                }
                if (! Schema::hasColumn('product_attributes', 'attribute_key')) {
                    $table->string('attribute_key', 100)->nullable()->index();
                }
                if (! Schema::hasColumn('product_attributes', 'sort_order')) {
                    $table->integer('sort_order')->default(100);
                }
            });
        }

        if (Schema::hasTable('product_attribute_options')) {
            Schema::table('product_attribute_options', function (Blueprint $table) {
                if (! Schema::hasColumn('product_attribute_options', 'category_id')) {
                    $table->string('category_id', 100)->nullable()->index();
                }
                if (! Schema::hasColumn('product_attribute_options', 'attribute_key')) {
                    $table->string('attribute_key', 100)->nullable()->index();
                }
                if (! Schema::hasColumn('product_attribute_options', 'sort_order')) {
                    $table->integer('sort_order')->default(100);
                }
            });
        }
    }

    public function down(): void
    {
        // Additive metadata columns preserved for backward compatibility.
    }
};
