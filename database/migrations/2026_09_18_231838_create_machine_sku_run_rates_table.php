<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run rate per machine per SKU, mirroring the BOM tab's 'BiDi rated speed' (G),
     * 'Adjustment' (H) and 'Adjusted BiDi rated speed' (I = ROUNDUP(G * H, 0)).
     *
     * sku_master.standard_run_rate_per_hour stays as the fallback for a SKU with no
     * machine-specific rate; the presence of a row here also tells the planner that
     * this machine is capable of running this SKU at all.
     */
    public function up(): void
    {
        Schema::create('machine_sku_run_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_master_id')->constrained('sku_master')->cascadeOnDelete();
            $table->decimal('rated_per_hour', 10, 3);
            $table->decimal('adjustment_factor', 6, 3)->nullable();
            $table->unsignedSmallInteger('changeover_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['machine_asset_id', 'sku_master_id']);
            $table->index(['sku_master_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_sku_run_rates');
    }
};
