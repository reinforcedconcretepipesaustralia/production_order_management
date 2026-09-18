<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_soh_id')->nullable()->constrained('inventory_soh')->restrictOnDelete();
            $table->foreignId('production_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->dateTime('allocated_at');
            $table->string('status', 30)->default('allocated');
            $table->timestamps();

            $table->index(['sales_order_id', 'status']);
            $table->index(['inventory_soh_id', 'status']);
            $table->index(['production_schedule_id', 'status']);
        });

        Schema::create('dispatch_load_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_load_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_allocation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('weight_tonnes', 10, 3);
            $table->timestamps();

            $table->unique(['dispatch_load_id', 'order_allocation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_load_items');
        Schema::dropIfExists('order_allocations');
    }
};
