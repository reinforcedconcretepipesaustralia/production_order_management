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
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sku_master_id')->constrained('sku_master')->restrictOnDelete();
            $table->string('erp_order_reference', 80);
            $table->string('erp_line_reference', 40);
            $table->string('customer_code', 60);
            $table->string('customer_name');
            $table->string('delivery_address');
            $table->unsignedInteger('ordered_quantity');
            $table->unsignedInteger('allocated_quantity')->default(0);
            $table->date('requested_delivery_date');
            $table->dateTime('ordered_at');
            $table->string('status', 30)->default('open');
            $table->timestamps();

            $table->unique(['erp_order_reference', 'erp_line_reference']);
            $table->index(['status', 'ordered_at', 'id']);
            $table->index(['depot_id', 'requested_delivery_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
