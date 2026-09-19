<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_master', function (Blueprint $table) {
            // Preferred machine from the BOM 'Machine' column (e.g. KILMORE, BiDi 36).
            $table->string('machine_preference', 50)->nullable()->after('bom_reference');
            // 'A' SKUs are the top 80% of R3M eaches and are made to stock; see DemandModel.
            $table->string('abc_class', 1)->nullable()->after('machine_preference');
            $table->unsignedSmallInteger('minimum_run_quantity')->default(0)->after('abc_class');
            $table->decimal('target_months_on_hand', 6, 2)->nullable()->after('minimum_run_quantity');

            $table->index(['abc_class', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('sku_master', function (Blueprint $table) {
            $table->dropIndex(['abc_class', 'is_active']);
            $table->dropColumn([
                'machine_preference',
                'abc_class',
                'minimum_run_quantity',
                'target_months_on_hand',
            ]);
        });
    }
};
