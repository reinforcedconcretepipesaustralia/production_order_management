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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->time('starts_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('prestart_minutes')->default(0);
            $table->unsignedSmallInteger('cleaning_minutes')->default(0);
            $table->decimal('performance_target_percent', 5, 2)->default(100);
            $table->decimal('availability_target_percent', 5, 2)->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['depot_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
