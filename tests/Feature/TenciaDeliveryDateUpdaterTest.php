<?php

namespace Tests\Feature;

use App\Services\DeliveryConfirmation\TenciaDeliveryDateUpdater;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

class TenciaDeliveryDateUpdaterTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenciaSource $tencia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tencia = FakeTenciaSource::install()->createTables();
        config()->set('database.connections.rcpa_sql_write.username', 'writer');
        config()->set('database.connections.rcpa_sql_write.database', 'local');
    }

    public function test_updates_one_line_and_sets_header_to_earliest_open_line_date(): void
    {
        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 101,
            'REFERENCE_NBR' => '136510',
            'STOCK_CODE' => 'R0600-4',
            'BACKORDER_QTY' => 10,
            'LINE_DELIVERY_DATE' => '2026-10-05 00:00:00',
            'EMAIL_ADDRESS' => 'buyer@example.com',
        ]);
        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 102,
            'REFERENCE_NBR' => '136510',
            'STOCK_CODE' => 'R0750-4',
            'BACKORDER_QTY' => 4,
            'LINE_DELIVERY_DATE' => '2026-10-08 00:00:00',
            'EMAIL_ADDRESS' => 'buyer@example.com',
        ]);

        $result = app(TenciaDeliveryDateUpdater::class)->updateLine(
            entity: 'RCPVIC',
            referenceNbr: '136510',
            referenceDuplicate: 0,
            lineUniqueId: 101,
            newDate: CarbonImmutable::parse('2026-10-20'),
        );

        $this->assertSame(1, $result['lines_updated']);
        $this->assertTrue($result['header_updated']);
        $this->assertSame('2026-10-08', $result['header_date']);
        $this->assertSame(
            '2026-10-20 00:00:00',
            DB::table($this->tencia->stateTableName('RCPVIC', 'SOR_LINES'))->where('UNIQUE_ID', 101)->value('DELIVERY_DATE'),
        );
        $this->assertSame(
            '2026-10-08 00:00:00',
            DB::table($this->tencia->stateTableName('RCPVIC', 'SOR_LINES'))->where('UNIQUE_ID', 102)->value('DELIVERY_DATE'),
        );
        $this->assertSame(
            '2026-10-08 00:00:00',
            DB::table($this->tencia->stateTableName('RCPVIC', 'SOR_MASTER'))->where('REFERENCE_NBR', '136510')->value('DELIVERY_DATE'),
        );
    }
}
