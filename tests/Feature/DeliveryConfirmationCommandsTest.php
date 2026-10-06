<?php

namespace Tests\Feature;

use App\Models\DeliveryConfirmationSend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

class DeliveryConfirmationCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeTenciaSource::install()->createTables()->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 101,
            'REFERENCE_NBR' => '136510',
            'STOCK_CODE' => 'R0600-4',
            'BACKORDER_QTY' => 10,
            'LINE_DELIVERY_DATE' => '2026-10-20 00:00:00',
            'EMAIL_ADDRESS' => 'buyer@example.com',
        ]);
        config()->set('delivery-reminders.enabled', false);
        config()->set('delivery-reminders.overdue_max_days_past', 14);
    }

    public function test_mark_seen_command_baselines_current_candidates(): void
    {
        $this->artisan('delivery-reminders:mark-seen', ['--date' => '2026-09-30'])
            ->assertSuccessful();

        $this->assertSame(1, DeliveryConfirmationSend::query()->where('status', 'baseline')->count());
    }

    public function test_send_command_stays_quiet_when_disabled(): void
    {
        $this->artisan('delivery-reminders:send', ['--date' => '2026-09-30'])
            ->assertSuccessful();

        $this->assertSame(0, DeliveryConfirmationSend::query()->count());
    }
}
