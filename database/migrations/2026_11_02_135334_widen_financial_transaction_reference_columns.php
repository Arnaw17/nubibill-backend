<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->string('id', 255)->change();
            $table->string('reference_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep the wider columns to avoid truncating existing linked transactions.
    }
};
