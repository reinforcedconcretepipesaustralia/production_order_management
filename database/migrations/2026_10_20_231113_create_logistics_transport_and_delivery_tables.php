<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_carriers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_pickup')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('transport_carrier_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_carrier_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('label', 80)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['transport_carrier_id', 'email']);
        });

        Schema::create('transport_carrier_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_carrier_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('vehicle_kind', 20)->default('other');
            $table->decimal('capacity_tonnes', 10, 3);
            $table->decimal('minimum_billable_tonnes', 10, 3)->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('transport_carrier_zone_rates', function (Blueprint $table) {
            $table->id();
            $table->string('erp_entity_code', 20);
            $table->foreignId('transport_carrier_id')->constrained()->cascadeOnDelete();
            $table->string('zone_code', 40);
            $table->decimal('rate_per_tonne', 12, 4);
            $table->decimal('minimum_charge_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('AUD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['erp_entity_code', 'transport_carrier_id', 'zone_code'], 'transport_zone_rate_unique');
        });

        Schema::create('delivery_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->date('schedule_date');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['depot_id', 'schedule_date']);
        });

        Schema::create('delivery_schedule_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_schedule_id')->constrained()->cascadeOnDelete();
            $table->string('erp_entity_code', 20);
            $table->string('erp_line_reference', 40);
            $table->foreignId('order_allocation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transport_carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transport_carrier_vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('dispatch_qty')->default(0);
            $table->decimal('tonnage', 12, 4)->default(0);
            $table->decimal('estimated_freight_amount', 12, 2)->nullable();
            $table->boolean('is_confirmed')->default(false);
            $table->boolean('is_delayed')->default(false);
            $table->string('delay_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['delivery_schedule_id', 'erp_entity_code', 'erp_line_reference'], 'delivery_schedule_line_erp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_schedule_lines');
        Schema::dropIfExists('delivery_schedules');
        Schema::dropIfExists('transport_carrier_zone_rates');
        Schema::dropIfExists('transport_carrier_vehicles');
        Schema::dropIfExists('transport_carrier_emails');
        Schema::dropIfExists('transport_carriers');
    }
};
