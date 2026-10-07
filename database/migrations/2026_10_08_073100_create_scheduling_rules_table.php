<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('category', 40);
            $table->text('description');
            $table->decimal('value', 10, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();

        DB::table('scheduling_rules')->insert([
            [
                'code' => 'max_runs_per_shift',
                'name' => 'Max products per shift',
                'category' => 'capacity',
                'description' => 'A machine may be set up for this many products in one shift. The plants pay for every change-over in lost time.',
                'value' => 2,
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'minimum_run_fraction',
                'name' => 'Minimum share of a shift for a new product',
                'category' => 'capacity',
                'description' => 'Do not start a new product unless at least this fraction of the shift remains. Stops a shift being split into slivers.',
                'value' => 0.25,
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'target_months_on_hand',
                'name' => 'Months of cover for fast movers',
                'category' => 'inventory',
                'description' => 'Stock cover held for an A-class SKU that has no target of its own.',
                'value' => 1,
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'include_replenishment',
                'name' => 'Plan safety stock as well as orders',
                'category' => 'inventory',
                'description' => '1 plans order-backed work and replenishment. 0 plans orders only, which is what to use when the order book is long.',
                'value' => 1,
                'sort_order' => 40,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'qualified_machines_only',
                'name' => 'Only schedule on a qualified machine',
                'category' => 'sequencing',
                'description' => 'A product may only run on a machine that has a proven run rate. No guessed rates.',
                'value' => null,
                'sort_order' => 50,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'keep_locked_lines',
                'name' => 'Never overwrite locked or hand-entered lines',
                'category' => 'sequencing',
                'description' => 'Lines a planner locked or typed in stay put and count against demand before anything new is scheduled.',
                'value' => null,
                'sort_order' => 60,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'stay_on_product',
                'name' => 'Stay on the same product to cut change-overs',
                'category' => 'sequencing',
                'description' => 'Fill a shift with the most urgent work the machine is qualified for. A requirement that spans shifts keeps the machine on that product.',
                'value' => null,
                'sort_order' => 70,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_rules');
    }
};
