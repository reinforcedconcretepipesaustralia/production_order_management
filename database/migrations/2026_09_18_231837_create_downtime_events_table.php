<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planned and unplanned downtime, replacing the 'Maintenance plan' tab (which is
     * entirely #REF! in the current workbooks). The capacity calendar subtracts these
     * minutes from the shift before demand is assigned, so tonnage and steel forecasts
     * stop being optimistic about maintenance and mould change-overs.
     */
    public function up(): void
    {
        Schema::create('downtime_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            // Null machine means the whole plant is down; null shift means it spans shifts.
            $table->foreignId('machine_asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('category', 30);
            $table->string('description');
            $table->boolean('is_planned')->default(true);
            // Change-over rows are emitted by the planner rather than entered by hand.
            $table->string('origin', 20)->default('manual');
            $table->timestamps();

            $table->index(['depot_id', 'starts_at', 'ends_at']);
            $table->index(['machine_asset_id', 'starts_at']);
            $table->index(['category', 'is_planned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_events');
    }
};
