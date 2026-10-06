<?php

namespace Tests\Feature;

use App\Enums\DeliveryConfirmationStage;
use App\Services\DeliveryConfirmation\OpenOrderCandidateFinder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

class DeliveryConfirmationOpenOrderCandidateFinderTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenciaSource $tencia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tencia = FakeTenciaSource::install()->createTables();
        config()->set('delivery-reminders.overdue_max_days_past', 14);
    }

    public function test_groups_open_lines_into_one_candidate_per_sales_order_and_stage(): void
    {
        $today = CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne');

        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 101,
            'REFERENCE_NBR' => '136510',
            'STOCK_CODE' => 'R0600-4',
            'BACKORDER_QTY' => 10,
            'LINE_DELIVERY_DATE' => '2026-10-20 00:00:00',
            'EMAIL_ADDRESS' => 'buyer@example.com',
            'CUSTOMER_NAME' => 'Acme',
        ]);
        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 102,
            'REFERENCE_NBR' => '136510',
            'STOCK_CODE' => 'R0750-4',
            'BACKORDER_QTY' => 5,
            'LINE_DELIVERY_DATE' => '2026-10-22 00:00:00',
            'EMAIL_ADDRESS' => 'buyer@example.com',
            'CUSTOMER_NAME' => 'Acme',
        ]);
        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 201,
            'REFERENCE_NBR' => '999999',
            'STOCK_CODE' => 'R0600-4',
            'BACKORDER_QTY' => 2,
            'LINE_DELIVERY_DATE' => '2026-12-01 00:00:00',
            'EMAIL_ADDRESS' => 'later@example.com',
        ]);

        $candidates = app(OpenOrderCandidateFinder::class)->candidates($today);

        $this->assertCount(1, $candidates);
        $group = $candidates->sole();
        $this->assertSame('136510', $group->referenceNbr);
        $this->assertSame(DeliveryConfirmationStage::Weeks34, $group->stage);
        $this->assertSame(2, $group->lineCount());
        $this->assertSame(15.0, $group->totalOutstandingQty);
        $this->assertSame([101, 102], array_column($group->lines, 'line_unique_id'));
    }

    public function test_recent_overdue_is_included_but_stale_overdue_is_not(): void
    {
        $today = CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne');

        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 301,
            'REFERENCE_NBR' => 'OVER-RECENT',
            'STOCK_CODE' => 'R0600-4',
            'BACKORDER_QTY' => 1,
            'LINE_DELIVERY_DATE' => '2026-09-20 00:00:00',
            'EMAIL_ADDRESS' => 'recent@example.com',
        ]);
        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 302,
            'REFERENCE_NBR' => 'OVER-STALE',
            'STOCK_CODE' => 'R0600-4',
            'BACKORDER_QTY' => 1,
            'LINE_DELIVERY_DATE' => '2026-08-01 00:00:00',
            'EMAIL_ADDRESS' => 'stale@example.com',
        ]);

        $candidates = app(OpenOrderCandidateFinder::class)->candidates($today);

        $this->assertCount(1, $candidates);
        $this->assertSame('OVER-RECENT', $candidates->sole()->referenceNbr);
        $this->assertSame(DeliveryConfirmationStage::Overdue, $candidates->sole()->stage);
    }
}
