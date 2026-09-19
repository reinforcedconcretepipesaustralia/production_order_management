<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned OEE settings, replacing the static 'OEE Settings' tab in each workbook.
     *
     * Effective hours are derived, not stored: shift length less prestart, paid break,
     * unpaid break, in-shift cleaning and end-of-shift cleaning. Planned pipes are then
     * rated run rate x performance x availability x effective hours x share of shift.
     *
     * Resolution is most specific first — machine, then shift, then depot — so a plant
     * can hold one target while a single machine is tracked separately.
     */
    public function up(): void
    {
        Schema::create('oee_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('machine_asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('performance_percent', 5, 2);
            $table->decimal('availability_percent', 5, 2);
            // Quality is tracked but excluded from planned capacity: the workbooks plan on
            // performance x availability and account for rejects separately as dumped pipes.
            $table->decimal('quality_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('prestart_minutes')->default(0);
            $table->unsignedSmallInteger('paid_break_minutes')->default(0);
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0);
            $table->unsignedSmallInteger('in_shift_cleaning_minutes')->default(0);
            $table->unsignedSmallInteger('end_of_shift_cleaning_minutes')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['depot_id', 'effective_from']);
            $table->index(['shift_id', 'machine_asset_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oee_settings');
    }
};
