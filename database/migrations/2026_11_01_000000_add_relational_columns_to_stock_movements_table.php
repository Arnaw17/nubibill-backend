<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'product_id')) {
                $table->string('product_id', 100)->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'variant_id')) {
                $table->string('variant_id', 100)->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'type')) {
                $table->string('type', 50)->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'qty_change')) {
                $table->decimal('qty_change', 12, 3)->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'balance_after')) {
                $table->decimal('balance_after', 12, 3)->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'reference_type')) {
                $table->string('reference_type', 50)->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'reference_id')) {
                $table->string('reference_id', 100)->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'condition')) {
                $table->string('condition', 30)->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'note')) {
                $table->text('note')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'created_by')) {
                $table->string('created_by', 100)->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'payload')) {
                $table->json('payload')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'document_path')) {
                $table->string('document_path', 500)->nullable();
            }
        });

        // Make payload nullable if it exists on MySQL
        try {
            DB::statement('ALTER TABLE stock_movements MODIFY payload JSON NULL;');
        } catch (\Throwable $e) {
            // Ignore if driver does not support or already nullable
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $cols = [
                'product_id',
                'variant_id',
                'type',
                'qty_change',
                'balance_after',
                'reference_type',
                'reference_id',
                'condition',
                'note',
                'created_by',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('stock_movements', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
