<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stock code only identifies a product within its Tencia entity. R0600-4 exists in both VIC and
 * NSW with different bills of material — VIC's consumes WIREDE6.00, which NSW does not stock at all
 * — so a globally unique sku_code collapses the two and gives one of them the wrong steel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_master', function (Blueprint $table) {
            $table->string('erp_entity_code', 20)->nullable()->after('sku_code');
        });

        // Rows loaded by the workbook stopgap carry no entity; sync:products adopts them by code.
        Schema::table('sku_master', function (Blueprint $table) {
            $table->dropUnique(['sku_code']);
            $table->unique(['erp_entity_code', 'sku_code']);
        });
    }

    public function down(): void
    {
        Schema::table('sku_master', function (Blueprint $table) {
            $table->dropUnique(['erp_entity_code', 'sku_code']);
            $table->unique(['sku_code']);
            $table->dropColumn('erp_entity_code');
        });
    }
};
