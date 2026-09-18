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
        Schema::create('sku_master', function (Blueprint $table) {
            $table->id();
            $table->string('sku_code', 50)->unique();
            $table->string('description');
            $table->unsignedInteger('diameter_mm')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->decimal('unit_weight_tonnes', 10, 4);
            $table->string('wire_code', 50);
            $table->decimal('wire_kg_per_pipe', 12, 3)->default(0);
            $table->decimal('concrete_volume_m3', 10, 4)->default(0);
            $table->decimal('standard_run_rate_per_hour', 10, 3);
            $table->string('bom_reference', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['diameter_mm', 'is_active']);
            $table->index(['wire_code', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sku_master');
    }
};
