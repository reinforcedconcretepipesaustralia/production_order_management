<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One generated production plan: what was asked for, what came out, and who approved it.
     *
     * A run is the unit a planner reviews and signs off. Keeping the request parameters and the
     * resulting metrics on the row means a plan can be explained and compared against the one
     * before it, which the workbooks cannot do — they only ever hold the current state.
     */
    public function up(): void
    {
        Schema::create('plan_runs', function (Blueprint $table) {
            $table->id();
            $table->date('horizon_from');
            $table->date('horizon_to');
            // The demand snapshot the plan was built on, so it stays reproducible.
            $table->date('as_of_date');
            $table->json('depot_ids');
            $table->string('status', 20)->default('draft');
            $table->json('parameters')->nullable();
            $table->json('metrics')->nullable();
            // Plain-English summary written for the review screen.
            $table->text('narrative')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'horizon_from']);
            $table->index('as_of_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_runs');
    }
};
