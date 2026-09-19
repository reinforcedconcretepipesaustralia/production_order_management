<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depots', function (Blueprint $table) {
            // Matches the ENTITY column on the TenciaRCP_View vw* views so imports can
            // attribute a row to a plant without hardcoding stock-category prefixes.
            $table->string('erp_entity_code', 20)->nullable()->after('state');
            $table->json('erp_stock_categories')->nullable()->after('erp_entity_code');
            $table->json('prime_stock_locations')->nullable()->after('erp_stock_categories');
            $table->json('rework_stock_locations')->nullable()->after('prime_stock_locations');
            // A plant stays out of plan generation until its reference data is complete.
            $table->boolean('is_planning_enabled')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('depots', function (Blueprint $table) {
            $table->dropColumn([
                'erp_entity_code',
                'erp_stock_categories',
                'prime_stock_locations',
                'rework_stock_locations',
                'is_planning_enabled',
            ]);
        });
    }
};
