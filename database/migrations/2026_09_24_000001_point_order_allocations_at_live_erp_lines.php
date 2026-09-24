<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_allocations', function (Blueprint $table) {
            $table->string('erp_entity_code', 20)->nullable();
            $table->string('erp_order_reference', 80)->nullable();
            $table->string('erp_line_reference', 40)->nullable();
            $table->string('stock_location', 40)->nullable();
            $table->foreignId('depot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sku_master_id')->nullable()->constrained('sku_master')->nullOnDelete();
        });

        Schema::table('order_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('depot_id');
            $table->dropConstrainedForeignId('sku_master_id');
            $table->dropColumn([
                'erp_entity_code', 'erp_order_reference', 'erp_line_reference', 'stock_location',
            ]);
        });
    }
};
