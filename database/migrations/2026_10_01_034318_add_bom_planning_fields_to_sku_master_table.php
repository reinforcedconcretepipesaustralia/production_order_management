<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_master', function (Blueprint $table) {
            $table->string('mould_type', 50)->nullable()->after('bom_reference');
            $table->string('reo_cage', 20)->nullable()->after('mould_type');
            $table->unsignedSmallInteger('moulds_count')->nullable()->after('reo_cage');
            $table->unsignedSmallInteger('pallets_count')->nullable()->after('moulds_count');
        });

        // Workbook imports stored mould type in bom_reference before a dedicated column existed.
        DB::table('sku_master')
            ->whereNull('mould_type')
            ->whereNotNull('bom_reference')
            ->update(['mould_type' => DB::raw('bom_reference')]);
    }

    public function down(): void
    {
        Schema::table('sku_master', function (Blueprint $table) {
            $table->dropColumn(['mould_type', 'reo_cage', 'moulds_count', 'pallets_count']);
        });
    }
};
