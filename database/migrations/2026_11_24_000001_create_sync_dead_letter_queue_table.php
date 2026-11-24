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
        if (!Schema::hasTable('sync_dead_letter_queue')) {
            Schema::create('sync_dead_letter_queue', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 64)->index();
                $table->string('business_id', 64)->nullable()->index();
                $table->string('collection', 64)->index();
                $table->string('document_id', 128)->index();
                $table->string('batch_id', 64)->nullable()->index();
                $table->string('error_code', 64)->nullable();
                $table->text('error_message');
                $table->longText('payload');
                $table->string('status', 32)->default('FAILED')->index(); // FAILED, REPROCESSED, DISCARDED
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_dead_letter_queue');
    }
};
