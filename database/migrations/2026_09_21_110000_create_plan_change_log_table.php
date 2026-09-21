<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every planner change to a generated plan line.
 *
 * The point of the model is that a human reviews and adjusts rather than authors, which only works
 * if the adjustments are visible: where the planner disagreed with the engine, and why. That is also
 * what makes the engine improvable — a pattern of the same correction is a rule the engine is
 * missing.
 *
 * The line is kept by id and by a description, so a log entry still reads after the plan it belonged
 * to has been regenerated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_change_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('line_description');
            $table->string('field', 60);
            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['plan_run_id', 'created_at']);
            $table->index('production_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_log');
    }
};
