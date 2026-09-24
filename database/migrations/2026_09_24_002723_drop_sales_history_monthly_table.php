<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoiced sales history is read live from dbo.vwSALES. The local monthly copy is unused.
     */
    public function up(): void
    {
        Schema::dropIfExists('sales_history_monthly');
    }

    public function down(): void
    {
        Schema::create('sales_history_monthly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_master_id')->constrained('sku_master')->cascadeOnDelete();
            $table->date('month');
            $table->integer('quantity_ea')->default(0);
            $table->decimal('tonnes', 14, 4)->default(0);
            $table->decimal('net_value_ex_tax', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['depot_id', 'sku_master_id', 'month']);
            $table->index(['month', 'depot_id']);
        });
    }
};
