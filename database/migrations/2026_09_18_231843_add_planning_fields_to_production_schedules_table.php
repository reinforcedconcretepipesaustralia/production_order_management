<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            // Excel 'Prod Plan' column P: the share of the shift this run occupies, so a
            // machine can be split across products within one shift (e.g. BiDi 36 for 50%
            // of dayshift). Planned pipes = capacity for the shift x this fraction.
            $table->decimal('shift_fraction', 5, 4)->default(1)->after('planned_quantity');
            // 'system' lines are replaceable on the next plan run; 'manual' lines are not.
            $table->string('origin', 20)->default('manual')->after('status');
            // Why the planner scheduled this line — surfaced in the review UI.
            $table->string('reason_code', 40)->nullable()->after('origin');
            $table->json('reason_context')->nullable()->after('reason_code');
            // A planner's explicit decision. Regeneration never touches a locked line.
            $table->boolean('is_locked')->default(false)->after('reason_context');
            // Excel 'Prod Plan' columns Q and R.
            $table->unsignedSmallInteger('priority')->nullable()->after('is_locked');
            $table->boolean('is_steamed')->default(false)->after('priority');

            $table->index(['origin', 'is_locked']);
        });
    }

    public function down(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            $table->dropIndex(['origin', 'is_locked']);
            $table->dropColumn([
                'shift_fraction',
                'origin',
                'reason_code',
                'reason_context',
                'is_locked',
                'priority',
                'is_steamed',
            ]);
        });
    }
};
