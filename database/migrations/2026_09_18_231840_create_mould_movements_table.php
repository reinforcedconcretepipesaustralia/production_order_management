<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Movement history for moulds and gear shared between plants, replacing the
     * 'Gear movement' tab. mould_assets.current_depot_id is the current position; this
     * is the audit trail, and it lets the planner see that a shared mould is in transit
     * rather than available.
     */
    public function up(): void
    {
        Schema::create('mould_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mould_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_depot_id')->nullable()->constrained('depots')->nullOnDelete();
            $table->foreignId('to_depot_id')->nullable()->constrained('depots')->nullOnDelete();
            $table->date('dispatched_on')->nullable();
            $table->date('expected_on')->nullable();
            $table->date('received_on')->nullable();
            $table->string('status', 20)->default('planned');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['mould_asset_id', 'status']);
            $table->index(['to_depot_id', 'expected_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mould_movements');
    }
};
