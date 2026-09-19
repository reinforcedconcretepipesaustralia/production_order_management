<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whole days a plant does not run: public holidays, Christmas shutdown, stocktake.
     * The capacity calendar drops these before any demand is assigned.
     */
    public function up(): void
    {
        Schema::create('non_production_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('category', 30)->default('holiday');
            $table->string('reason');
            $table->timestamps();

            $table->unique(['depot_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_production_days');
    }
};
