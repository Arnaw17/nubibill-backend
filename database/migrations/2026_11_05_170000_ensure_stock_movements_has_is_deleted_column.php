<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_movements', 'is_deleted')) {
                    $table->tinyInteger('is_deleted')->default(0)->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (Schema::hasColumn('stock_movements', 'is_deleted')) {
                    $table->dropColumn('is_deleted');
                }
            });
        }
    }
};
