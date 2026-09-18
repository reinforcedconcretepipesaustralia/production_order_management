<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('production_actuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_schedule_id')->constrained()->cascadeOnDelete();
            $table->dateTime('manufactured_at');
            $table->unsignedInteger('good_quantity')->default(0);
            $table->unsignedInteger('dumped_quantity')->default(0);
            $table->string('dump_reason', 100)->nullable();
            $table->decimal('actual_run_rate_per_hour', 10, 3)->nullable();
            $table->decimal('quality_percent', 5, 2)->default(100);
            $table->string('quality_status', 30)->default('curing');
            $table->dateTime('qc_released_at')->nullable();
            $table->string('qc_released_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['production_schedule_id', 'quality_status']);
            $table->index(['quality_status', 'manufactured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_actuals');
    }
};
