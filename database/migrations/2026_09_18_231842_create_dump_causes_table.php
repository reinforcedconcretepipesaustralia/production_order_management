<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Root causes from the 'DP Reason-Cause' tab column C — the machine, mix, handling or
     * operator condition behind a dumped pipe. Reason answers 'what was wrong with it',
     * cause answers 'why'. Kept separate because the workbook records both per line.
     */
    public function up(): void
    {
        Schema::create('dump_causes', function (Blueprint $table) {
            $table->id();
            $table->string('description')->unique();
            $table->string('category', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dump_causes');
    }
};
