<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a plan run will consume, per plant per week per material.
 *
 * Kept apart from steel_forecasts, which holds the buyer's manual stocktake inputs — coils counted,
 * coils on order, transfers, the safety threshold. Conflating computed demand with counted stock
 * loses the ability to say which of the two moved when a recommendation changes.
 *
 * Weekly rather than monthly because that is the cadence wire is ordered on, and it is what the
 * workbook's Steel Forecast tab shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->date('week_starting');
            // 'wire' is ordered in kilograms; 'concrete' is batched in cubic metres.
            $table->string('kind', 20);
            // A wire stock code such as WIREPL6.23, or a mix code such as YAT-BIDI.
            $table->string('material_code', 60);
            $table->string('unit', 10);
            $table->decimal('quantity', 16, 4)->default(0);
            $table->unsignedInteger('planned_pipes')->default(0);
            $table->timestamps();

            $table->unique(
                ['plan_run_id', 'depot_id', 'week_starting', 'material_code'],
                'material_requirements_unique',
            );
            $table->index(['depot_id', 'week_starting']);
            $table->index(['kind', 'material_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requirements');
    }
};
