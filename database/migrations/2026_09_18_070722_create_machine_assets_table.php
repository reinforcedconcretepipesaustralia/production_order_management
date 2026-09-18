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
        Schema::create('machine_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('machine_type', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['depot_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_assets');
    }
};
