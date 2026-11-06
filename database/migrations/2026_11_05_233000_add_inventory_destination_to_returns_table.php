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
        if (Schema::hasTable('returns')) {
            Schema::table('returns', function (Blueprint $table) {
                if (! Schema::hasColumn('returns', 'inventory_destination')) {
                    $table->string('inventory_destination', 50)->default('NEW_INVENTORY')->after('refund_mode')->index();
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_movements', 'batch_id')) {
                    $table->string('batch_id', 100)->nullable()->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('returns')) {
            Schema::table('returns', function (Blueprint $table) {
                if (Schema::hasColumn('returns', 'inventory_destination')) {
                    $table->dropColumn('inventory_destination');
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (Schema::hasColumn('stock_movements', 'batch_id')) {
                    $table->dropColumn('batch_id');
                }
            });
        }
    }
};
