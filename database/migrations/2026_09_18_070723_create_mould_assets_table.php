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
        Schema::create('mould_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('current_depot_id')->nullable()->constrained('depots')->nullOnDelete();
            $table->string('code', 50)->unique();
            $table->unsignedInteger('diameter_mm');
            $table->string('mould_type', 50);
            $table->boolean('is_shared')->default(false);
            $table->string('machine_head_constraint', 100)->nullable();
            $table->string('shared_component_constraint', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['current_depot_id', 'diameter_mm', 'mould_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mould_assets');
    }
};
