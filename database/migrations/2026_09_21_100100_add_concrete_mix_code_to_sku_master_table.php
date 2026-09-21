<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which mix a pipe is batched from, e.g. YAT-BIDI or YAT-EKO.
 *
 * The bill of material already carries it alongside the volume, and mixes differ in cement content,
 * so keeping only the cubic metres would make cement demand unanswerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_master', function (Blueprint $table) {
            $table->string('concrete_mix_code', 60)->nullable()->after('concrete_volume_m3');
        });
    }

    public function down(): void
    {
        Schema::table('sku_master', function (Blueprint $table) {
            $table->dropColumn('concrete_mix_code');
        });
    }
};
