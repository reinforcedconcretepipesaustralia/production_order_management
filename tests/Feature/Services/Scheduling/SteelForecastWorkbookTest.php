<?php

namespace Tests\Feature\Services\Scheduling;

use App\Models\Depot;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use App\Models\SteelForecast;
use App\Services\Scheduling\SteelForecastWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SteelForecastWorkbookTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_renders_weekly_tonnes_and_planned_order_in_workbook_layout(): void
    {
        $this->travelTo('2026-11-09 09:00:00');

        $depot = Depot::factory()->create(['code' => 'MEL']);
        $otherDepot = Depot::factory()->create(['code' => 'SYD']);
        $sku = $this->sku(['WIREPL4.95' => '10.000', 'WIREDE7.50' => '5.000']);

        SteelForecast::factory()->for($depot)->create([
            'forecast_date' => '2026-11-09',
            'wire_code' => 'WIREPL4.95',
            'on_hand_coils' => 10,
            'average_coil_weight_tonnes' => '1.0000',
            'on_order_coils' => 2,
            'transfer_in_coils' => 0,
            'transfer_out_coils' => 0,
            'safety_threshold_coils' => 2,
        ]);
        SteelForecast::factory()->for($depot)->create([
            'forecast_date' => '2026-11-09',
            'wire_code' => 'WIREDE7.50',
            'on_hand_coils' => 0,
            'average_coil_weight_tonnes' => '2.0000',
            'on_order_coils' => 0,
            'safety_threshold_coils' => 1,
        ]);
        SteelForecast::factory()->for($otherDepot)->create([
            'forecast_date' => '2026-11-09',
            'wire_code' => 'WIRE-HIDDEN',
            'average_coil_weight_tonnes' => '1.0000',
        ]);

        $this->schedule($depot, $sku, '2026-11-11', 100);
        $this->schedule($depot, $sku, '2026-11-17', 200);
        $this->schedule($depot, $sku, '2026-11-11', 50, status: 'cancelled');

        $tables = app(SteelForecastWorkbook::class)->tables(
            collect([$depot]),
            $depot->steelForecasts()->with('depot')->get(),
            ProductionSchedule::query(),
        );

        $this->assertCount(1, $tables);

        $table = $tables->first();

        $this->assertSame('MEL', $table['depot']->code);
        $this->assertSame(['WIREPL4.95', 'WIREDE7.50'], $table['wire_codes']);
        $this->assertCount(8, $table['weeks']);
        $this->assertSame('9/11/2026', $table['weeks'][0]['from']->format('j/n/Y'));
        $this->assertSame('15/11/2026', $table['weeks'][0]['to']->format('j/n/Y'));
        $this->assertSame('16/11/2026', $table['weeks'][1]['from']->format('j/n/Y'));
        $this->assertSame('23/11/2026', $table['weeks'][2]['from']->format('j/n/Y'));
        $this->assertSame('28/12/2026', $table['weeks'][7]['from']->format('j/n/Y'));
        $this->assertSame('1.0', $table['weeks'][0]['tonnes']['WIREPL4.95']);
        $this->assertSame('2.0', $table['weeks'][1]['tonnes']['WIREPL4.95']);
        $this->assertSame('', $table['weeks'][2]['tonnes']['WIREPL4.95']);
        $this->assertSame('0.5', $table['weeks'][0]['tonnes']['WIREDE7.50']);
        $this->assertSame('3.0', $table['requirement']['WIREPL4.95']);
        $this->assertSame('1.5', $table['requirement']['WIREDE7.50']);
        $this->assertSame('10.0', $table['coils_on_hand']['WIREPL4.95']);
        $this->assertSame('1.00', $table['average_coil_weight']['WIREPL4.95']);
        $this->assertSame('10.0', $table['tonnes_on_hand']['WIREPL4.95']);
        $this->assertSame('2.0', $table['on_order']['WIREPL4.95']);
        $this->assertSame('9.0', $table['ending_stock']['WIREPL4.95']);
        $this->assertSame('2.0', $table['safety_stock']['WIREPL4.95']);
        $this->assertSame('0', $table['planned_order']['WIREPL4.95']);
        $this->assertSame('-1.5', $table['ending_stock']['WIREDE7.50']);
        $this->assertSame('2.0', $table['safety_stock']['WIREDE7.50']);
        $this->assertSame('4', $table['planned_order']['WIREDE7.50']);
    }

    public function test_uses_two_week_average_safety_when_no_threshold_is_set(): void
    {
        $this->travelTo('2026-11-09 09:00:00');

        $depot = Depot::factory()->create(['code' => 'MEL']);
        $sku = $this->sku(['WIREPL6.23' => '10.000']);

        SteelForecast::factory()->for($depot)->create([
            'forecast_date' => '2026-11-09',
            'wire_code' => 'WIREPL6.23',
            'on_hand_coils' => 0,
            'average_coil_weight_tonnes' => '1.0000',
            'on_order_coils' => 0,
            'safety_threshold_coils' => 0,
        ]);

        $this->schedule($depot, $sku, '2026-11-11', 100);
        $this->schedule($depot, $sku, '2026-11-18', 200);

        $table = app(SteelForecastWorkbook::class)->tables(
            collect([$depot]),
            $depot->steelForecasts()->with('depot')->get(),
            ProductionSchedule::query(),
        )->first();

        $this->assertSame('3.0', $table['requirement']['WIREPL6.23']);
        $this->assertSame('3.0', $table['safety_stock']['WIREPL6.23']);
        $this->assertSame('6', $table['planned_order']['WIREPL6.23']);
    }

    public function test_starts_from_this_monday_even_when_the_stocktake_date_is_later(): void
    {
        $this->travelTo('2026-10-01 09:00:00');

        $depot = Depot::factory()->create(['code' => 'MEL']);
        $sku = $this->sku(['WIREPL6.23' => '10.000']);

        SteelForecast::factory()->for($depot)->create([
            'forecast_date' => '2026-11-09',
            'wire_code' => 'WIREPL6.23',
            'on_hand_coils' => 0,
            'average_coil_weight_tonnes' => '1.0000',
        ]);

        $this->schedule($depot, $sku, '2026-10-02', 100);

        $table = app(SteelForecastWorkbook::class)->tables(
            collect([$depot]),
            $depot->steelForecasts()->with('depot')->get(),
            ProductionSchedule::query(),
        )->first();

        $this->assertSame('28/9/2026', $table['weeks'][0]['from']->format('j/n/Y'));
        $this->assertSame('16/11/2026', $table['weeks'][7]['from']->format('j/n/Y'));
        $this->assertSame('1.0', $table['weeks'][0]['tonnes']['WIREPL6.23']);
    }

    /**
     * @param  array<string, string>  $gauges
     */
    private function sku(array $gauges): SkuMaster
    {
        $sku = SkuMaster::factory()->create();

        foreach ($gauges as $wireCode => $kgPerPipe) {
            SkuWireRequirement::factory()->create([
                'sku_master_id' => $sku->id,
                'wire_code' => $wireCode,
                'kg_per_pipe' => $kgPerPipe,
            ]);
        }

        return $sku;
    }

    private function schedule(Depot $depot, SkuMaster $sku, string $date, int $quantity, string $status = 'scheduled'): ProductionSchedule
    {
        $day = CarbonImmutable::parse($date);

        return ProductionSchedule::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => $date,
            'planned_start_at' => $day->addHours(6),
            'planned_end_at' => $day->addHours(14),
            'planned_quantity' => $quantity,
            'status' => $status,
        ]);
    }
}
