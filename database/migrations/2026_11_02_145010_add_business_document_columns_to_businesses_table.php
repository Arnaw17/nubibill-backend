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
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'document_path')) {
                $table->string('document_path', 500)->nullable();
            }
            if (! Schema::hasColumn('businesses', 'business_id')) {
                $table->string('business_id', 255)->nullable()->index();
            }
            if (! Schema::hasColumn('businesses', 'outlet_id')) {
                $table->string('outlet_id', 255)->nullable()->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep document identifiers and scope values to avoid losing data on rollback.
    }
};
