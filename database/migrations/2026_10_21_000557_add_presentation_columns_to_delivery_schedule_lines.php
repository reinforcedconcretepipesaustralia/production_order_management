<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_schedule_lines', function (Blueprint $table) {
            $table->string('erp_order_reference', 40)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('delivery_name')->nullable();
            $table->string('suburb', 80)->nullable();
            $table->string('zone_code', 40)->nullable();
            $table->string('customer_order', 80)->nullable();
            $table->string('stock_code', 40)->nullable();
            $table->unsignedInteger('backorder_qty')->default(0);
            $table->unsignedInteger('prime_soh')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_schedule_lines', function (Blueprint $table) {
            $table->dropColumn([
                'erp_order_reference',
                'customer_name',
                'contact_name',
                'phone',
                'delivery_name',
                'suburb',
                'zone_code',
                'customer_order',
                'stock_code',
                'backorder_qty',
                'prime_soh',
            ]);
        });
    }
};
