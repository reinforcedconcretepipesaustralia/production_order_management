<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-weekday shift length, so the planner can model patterns the single
     * shifts.duration_minutes column cannot — e.g. Melton afternoon runs 10 hr on
     * Monday and Friday but 12 hr Tuesday to Thursday.
     */
    public function up(): void
    {
        Schema::create('depot_shift_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            // 0 = Sunday through 6 = Saturday, matching Carbon::dayOfWeek.
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shift_id', 'weekday']);
            $table->index(['depot_id', 'weekday', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depot_shift_patterns');
    }
};
