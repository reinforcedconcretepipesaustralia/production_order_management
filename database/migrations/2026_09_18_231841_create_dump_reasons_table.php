<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dumped-product reason codes from the 'DP Reason-Cause' tab: 101-124 for pipes,
     * 201-220 for cages. production_actuals.dump_reason is a free-text column today.
     */
    public function up(): void
    {
        Schema::create('dump_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('description');
            // 'pipe' or 'cage' — the workbook keeps two separate code ranges.
            $table->string('product_type', 20)->default('pipe');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dump_reasons');
    }
};
