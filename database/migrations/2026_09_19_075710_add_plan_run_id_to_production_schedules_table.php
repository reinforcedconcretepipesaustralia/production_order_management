<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            // Null on delete, not cascade: discarding a draft run removes its own system lines
            // as an explicit step, but an approved schedule must never vanish because the run
            // that produced it was tidied away.
            $table->foreignId('plan_run_id')->nullable()->after('id')->constrained()->nullOnDelete();

            $table->index(['plan_run_id', 'schedule_date']);
        });
    }

    public function down(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            $table->dropIndex(['plan_run_id', 'schedule_date']);
            $table->dropConstrainedForeignId('plan_run_id');
        });
    }
};
