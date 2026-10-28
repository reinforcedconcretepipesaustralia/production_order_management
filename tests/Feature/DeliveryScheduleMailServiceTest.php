<?php

namespace Tests\Feature;

use App\Contracts\DispatchNotificationSender;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\TransportCarrier;
use App\Services\Dispatch\DispatchNotification;
use App\Services\Dispatch\DispatchNotificationResult;
use App\Services\Logistics\DeliveryScheduleMailService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeliveryScheduleMailServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_carrier_email_includes_only_that_carriers_confirmed_lines(): void
    {
        $depot = Depot::factory()->create(['code' => 'YAT']);
        $schedule = DeliverySchedule::query()->create([
            'depot_id' => $depot->id,
            'schedule_date' => '2026-10-21',
            'status' => 'draft',
        ]);

        $todds = TransportCarrier::factory()->create(['name' => 'Todds']);
        $todds->emails()->create(['email' => 'todds@example.com', 'is_primary' => true]);

        $dc = TransportCarrier::factory()->create(['name' => 'DC']);
        $dc->emails()->create(['email' => 'dc@example.com', 'is_primary' => true]);

        $pickup = TransportCarrier::factory()->pickup()->create(['name' => 'Ex Works']);
        $pickup->emails()->create(['email' => 'pickup@example.com']);

        $this->line($schedule, $todds, 'SO-TODDS', confirmed: true);
        $this->line($schedule, $dc, 'SO-DC', confirmed: true);
        $this->line($schedule, $todds, 'SO-UNCONFIRMED', confirmed: false);
        $this->line($schedule, $pickup, 'SO-PICKUP', confirmed: true);

        $bodies = [];
        $sender = $this->mock(DispatchNotificationSender::class);
        $sender->shouldReceive('send')
            ->twice()
            ->withArgs(function (DispatchNotification $notification) use (&$bodies): bool {
                $bodies[$notification->recipients[0]] = $notification->htmlBody;
                $this->assertStringNotContainsString('Est. freight', $notification->htmlBody);

                return true;
            })
            ->andReturn(new DispatchNotificationResult('graph-1'));

        $sent = app(DeliveryScheduleMailService::class)->sendToCarriers($schedule->fresh());

        $this->assertEqualsCanonicalizing(['todds@example.com', 'dc@example.com'], $sent);
        $this->assertStringContainsString('SO-TODDS', $bodies['todds@example.com']);
        $this->assertStringNotContainsString('SO-DC', $bodies['todds@example.com']);
        $this->assertStringNotContainsString('SO-UNCONFIRMED', $bodies['todds@example.com']);
        $this->assertStringNotContainsString('SO-PICKUP', $bodies['todds@example.com']);
        $this->assertStringContainsString('SO-DC', $bodies['dc@example.com']);
        $this->assertStringNotContainsString('SO-TODDS', $bodies['dc@example.com']);
    }

    public function test_internal_copy_includes_every_line_and_freight(): void
    {
        config(['logistics.internal_schedule_recipients' => ['planner@example.com']]);

        $depot = Depot::factory()->create(['code' => 'YAT']);
        $schedule = DeliverySchedule::query()->create([
            'depot_id' => $depot->id,
            'schedule_date' => '2026-10-21',
            'status' => 'published',
        ]);
        $carrier = TransportCarrier::factory()->create(['name' => 'Todds']);
        $this->line($schedule, $carrier, 'SO-INTERNAL', confirmed: false);

        $sender = $this->mock(DispatchNotificationSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->withArgs(function (DispatchNotification $notification): bool {
                $this->assertSame(['planner@example.com'], $notification->recipients);
                $this->assertStringContainsString('SO-INTERNAL', $notification->htmlBody);
                $this->assertStringContainsString('Est. freight', $notification->htmlBody);
                $this->assertStringContainsString('Todds', $notification->htmlBody);

                return true;
            })
            ->andReturn(new DispatchNotificationResult('graph-2'));

        $sent = app(DeliveryScheduleMailService::class)->sendInternalCopy($schedule->fresh());

        $this->assertSame(['planner@example.com'], $sent);
    }

    private function line(DeliverySchedule $schedule, TransportCarrier $carrier, string $reference, bool $confirmed): void
    {
        DeliveryScheduleLine::query()->create([
            'delivery_schedule_id' => $schedule->id,
            'erp_entity_code' => 'RCPQLD',
            'erp_line_reference' => $reference,
            'erp_order_reference' => $reference,
            'customer_name' => 'Acme',
            'transport_carrier_id' => $carrier->id,
            'dispatch_qty' => 1,
            'tonnage' => 1,
            'is_confirmed' => $confirmed,
        ]);
    }
}
