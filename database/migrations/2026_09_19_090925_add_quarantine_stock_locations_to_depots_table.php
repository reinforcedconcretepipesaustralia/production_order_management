<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quarantine and reject locations, which are neither saleable nor reworkable stock.
     *
     * The national Tencia extract names them plainly — 'QUARANTNE - SOMERSBY', 'QUARANTINE -
     * THORNTON', 'REJECT' — and inventory_soh already has a quarantine column to hold them.
     * Counting them as rework would overstate what can be recovered.
     */
    public function up(): void
    {
        Schema::table('depots', function (Blueprint $table) {
            $table->json('quarantine_stock_locations')->nullable()->after('rework_stock_locations');
        });
    }

    public function down(): void
    {
        Schema::table('depots', function (Blueprint $table) {
            $table->dropColumn('quarantine_stock_locations');
        });
    }
};
