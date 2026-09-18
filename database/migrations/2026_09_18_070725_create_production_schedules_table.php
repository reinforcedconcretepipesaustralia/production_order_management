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
        Schema::create('production_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('machine_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('sku_master_id')->constrained('sku_master')->restrictOnDelete();
            $table->foreignId('mould_asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('schedule_date');
            $table->dateTime('planned_start_at');
            $table->dateTime('planned_end_at');
            $table->unsignedInteger('planned_quantity');
            $table->decimal('planned_oee_percent', 5, 2)->default(100);
            $table->decimal('effective_hours', 8, 3);
            $table->string('status', 30)->default('draft');
            $table->unsignedSmallInteger('changeover_minutes')->default(0);
            $table->string('changeover_from_sku_code', 50)->nullable();
            $table->text('changeover_notes')->nullable();
            $table->timestamps();

            $table->index(['depot_id', 'schedule_date', 'status']);
            $table->index(['machine_asset_id', 'planned_start_at', 'planned_end_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_schedules');
    }
};
