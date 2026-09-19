<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly invoiced sales per plant per SKU — the basis for the R3M, R6M and R12M velocity
     * figures on the 'Pipes to Manufacture' sheet (columns Q, R and S).
     *
     * The workbook recomputes those averages with a SUMIFS across a 16,000-row sales extract
     * every refresh. Aggregating once on import keeps the demand model cheap and makes a plan
     * reproducible from a snapshot.
     *
     * Quantities are signed: credit notes reduce a month, exactly as the workbook's
     * STK_TRANS query negates DRCDT rows.
     */
    public function up(): void
    {
        Schema::create('sales_history_monthly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_master_id')->constrained('sku_master')->cascadeOnDelete();
            // Always the first day of the month.
            $table->date('month');
            $table->integer('quantity_ea')->default(0);
            $table->decimal('tonnes', 14, 4)->default(0);
            $table->decimal('net_value_ex_tax', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['depot_id', 'sku_master_id', 'month']);
            $table->index(['month', 'depot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_history_monthly');
    }
};
