<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for the planner to say why a line is the way it is.
 *
 * changeover_notes already exists but belongs to the engine — it explains a mould change. A planner
 * overruling the plan needs their own field, or the two get written over each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            $table->text('planner_notes')->nullable()->after('changeover_notes');
        });
    }

    public function down(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            $table->dropColumn('planner_notes');
        });
    }
};
