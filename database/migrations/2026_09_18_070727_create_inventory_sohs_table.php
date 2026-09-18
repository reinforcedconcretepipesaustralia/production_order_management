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
        Schema::create('inventory_soh', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_master_id')->constrained('sku_master')->restrictOnDelete();
            $table->string('bin_location', 60);
            $table->unsignedInteger('prime_quantity')->default(0);
            $table->unsignedInteger('rework_quantity')->default(0);
            $table->unsignedInteger('quarantine_quantity')->default(0);
            $table->dateTime('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['depot_id', 'sku_master_id', 'bin_location']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_soh');
    }
};
