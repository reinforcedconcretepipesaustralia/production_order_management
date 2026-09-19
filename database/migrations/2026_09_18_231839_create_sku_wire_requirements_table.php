<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Steel per pipe, broken out by wire gauge.
     *
     * A pipe consumes several gauges at once — the BOM tab carries one column per gauge
     * (AJ:AQ for 3.97, 4.95, 5.55, 6.00, 6.23, 7.50, 8.50, 9.00 mm), each a SUMIFS over
     * BOM_LINES for that Tencia wire stock code. sku_master.wire_code and
     * wire_kg_per_pipe hold only a single gauge and cannot represent that.
     */
    public function up(): void
    {
        Schema::create('sku_wire_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_master_id')->constrained('sku_master')->cascadeOnDelete();
            // Tencia stock code, e.g. WIREPL3.97, WIREDE7.50.
            $table->string('wire_code', 50);
            $table->decimal('wire_gauge_mm', 6, 2)->nullable();
            $table->decimal('kg_per_pipe', 12, 3);
            $table->timestamps();

            $table->unique(['sku_master_id', 'wire_code']);
            $table->index('wire_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_wire_requirements');
    }
};
