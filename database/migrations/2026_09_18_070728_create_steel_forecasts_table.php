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
        Schema::create('steel_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->date('forecast_date');
            $table->string('wire_code', 50);
            $table->unsignedInteger('on_hand_coils')->default(0);
            $table->decimal('average_coil_weight_tonnes', 10, 4);
            $table->unsignedInteger('on_order_coils')->default(0);
            $table->unsignedInteger('transfer_in_coils')->default(0);
            $table->unsignedInteger('transfer_out_coils')->default(0);
            $table->unsignedInteger('safety_threshold_coils')->default(0);
            $table->timestamps();

            $table->unique(['depot_id', 'forecast_date', 'wire_code']);
            $table->index(['forecast_date', 'wire_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('steel_forecasts');
    }
};
