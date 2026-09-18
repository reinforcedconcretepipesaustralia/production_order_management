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
        Schema::create('dispatch_loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->string('load_reference', 80)->unique();
            $table->date('dispatch_date');
            $table->decimal('capacity_tonnes', 10, 3);
            $table->decimal('current_weight_tonnes', 10, 3)->default(0);
            $table->string('status', 30)->default('planned');
            $table->string('notification_status', 30)->default('pending');
            $table->dateTime('notified_at')->nullable();
            $table->timestamps();

            $table->index(['depot_id', 'dispatch_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatch_loads');
    }
};
