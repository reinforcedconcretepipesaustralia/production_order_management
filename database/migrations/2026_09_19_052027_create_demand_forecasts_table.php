<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A dated snapshot of the demand picture per plant per SKU — the 'Pipes to Manufacture'
     * sheet, persisted.
     *
     * Snapshots exist so a plan is reproducible. The workbook recalculates against live
     * queries, so reopening it next week silently changes the numbers a decision was made on;
     * here a plan run points at an as_of_date and the figures behind it never move.
     */
    public function up(): void
    {
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_master_id')->constrained('sku_master')->cascadeOnDelete();
            $table->date('as_of_date');

            // Velocity: average monthly eaches over the trailing 3, 6 and 12 months.
            $table->decimal('r3m_monthly_ea', 12, 4)->default(0);
            $table->decimal('r6m_monthly_ea', 12, 4)->default(0);
            $table->decimal('r12m_monthly_ea', 12, 4)->default(0);

            // Order book. outstanding_by_month buckets the next four delivery months, keyed by
            // the first of the month, mirroring workbook columns V to Y.
            $table->unsignedInteger('outstanding_qty')->default(0);
            $table->unsignedInteger('overdue_qty')->default(0);
            $table->unsignedInteger('current_month_qty')->default(0);
            $table->json('outstanding_by_month')->nullable();
            $table->date('earliest_due_date')->nullable();

            // Position.
            $table->unsignedInteger('prime_soh_qty')->default(0);
            $table->unsignedInteger('rework_soh_qty')->default(0);
            // prime_soh_qty - outstanding_qty; negative means the order book is uncovered.
            $table->integer('net_position_qty')->default(0);

            // Coverage. months_on_hand uses R3M; equivalent_months is outstanding / R12M and is
            // the workbook's column U — above 3 it flags demand that may be rising.
            $table->decimal('months_on_hand', 10, 4)->nullable();
            $table->decimal('equivalent_months', 10, 4)->nullable();
            $table->boolean('has_demand_signal')->default(false);

            // Fast-mover classification. 'A' SKUs make up the top 80% of R3M eaches and are the
            // only ones eligible for safety stock.
            $table->string('abc_class', 1)->nullable();
            $table->decimal('abc_cumulative_share', 8, 6)->nullable();

            // Requirement, split by the rule the planners work to: order-backed work is made
            // first, and replenishment only once the order book is covered.
            $table->unsignedInteger('order_backed_qty')->default(0);
            $table->unsignedInteger('replenishment_qty')->default(0);

            $table->timestamps();

            $table->unique(['depot_id', 'sku_master_id', 'as_of_date']);
            $table->index(['as_of_date', 'depot_id']);
            $table->index(['as_of_date', 'abc_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_forecasts');
    }
};
