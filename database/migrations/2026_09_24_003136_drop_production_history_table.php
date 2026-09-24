<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closed production runs are read live from dbo.vwProduction. The local copy is unused.
     */
    public function up(): void
    {
        Schema::dropIfExists('production_history');
    }

    public function down(): void
    {
        Schema::create('production_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sku_master_id')->nullable()->constrained('sku_master')->nullOnDelete();
            $table->string('erp_entity_code', 20);
            $table->string('erp_order_number', 40);
            $table->unsignedInteger('erp_order_duplicate')->default(0);
            $table->string('sku_code', 50);
            $table->date('produced_on');
            $table->unsignedInteger('good_quantity')->default(0);
            $table->unsignedInteger('rejected_quantity')->default(0);
            $table->decimal('tonnes_produced', 12, 4)->default(0);
            $table->string('status', 40);
            $table->timestamps();

            $table->unique(['erp_entity_code', 'erp_order_number', 'erp_order_duplicate'], 'production_history_erp_key');
            $table->index(['depot_id', 'produced_on']);
            $table->index(['sku_master_id', 'produced_on']);
        });
    }
};
