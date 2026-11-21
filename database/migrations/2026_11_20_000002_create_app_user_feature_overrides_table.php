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
        if (! Schema::hasTable('app_user_feature_overrides')) {
            Schema::create('app_user_feature_overrides', function (Blueprint $table) {
                $table->id();
                $table->string('user_id')->index();
                $table->string('user_email')->nullable();
                $table->string('user_name')->nullable();
                $table->string('feature_key')->index(); // 'maintenance_mode' or individual feature key like 'pos_billing'
                $table->boolean('is_enabled')->default(false);
                $table->string('disabled_reason')->nullable();
                $table->string('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'feature_key']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_user_feature_overrides');
    }
};
