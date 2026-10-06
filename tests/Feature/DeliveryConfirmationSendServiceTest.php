<?php

namespace Tests\Feature;

use App\Models\DeliveryConfirmationSend;
use App\Services\DeliveryConfirmation\DeliveryConfirmationMessage;
use App\Services\DeliveryConfirmation\DeliveryConfirmationSendService;
use App\Services\DeliveryConfirmation\MicrosoftGraphDeliveryMailClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

class DeliveryConfirmationSendServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenciaSource $tencia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tencia = FakeTenciaSource::install()->createTables();
        config()->set('delivery-reminders.overdue_max_days_past', 14);
    }

    public function test_send_is_a_no_op_when_disabled_unless_forced(): void
    {
        config()->set('delivery-reminders.enabled', false);
        $this->seedDueOrder('RCPVIC');

        $counts = app(DeliveryConfirmationSendService::class)->sendDue(
            CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne'),
        );

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $counts);
        $this->assertSame(0, DeliveryConfirmationSend::query()->count());
    }

    public function test_force_send_uses_the_entity_shared_mailbox_and_records_it(): void
    {
        config()->set('delivery-reminders.enabled', false);
        $this->seedDueOrder('RCPVIC');

        $mail = Mockery::mock(MicrosoftGraphDeliveryMailClient::class);
        $mail->shouldReceive('send')
            ->once()
            ->withArgs(function (DeliveryConfirmationMessage $message, string $mailbox): bool {
                $this->assertSame('buyer@example.com', $message->recipient);
                $this->assertSame('vicsales@rcpa.com.au', $mailbox);
                $this->assertStringContainsString('LINE 101', $message->htmlBody);
                $this->assertStringContainsString('LINE 102', $message->htmlBody);

                return true;
            })
            ->andReturn('graph-req-1');
        $this->app->instance(MicrosoftGraphDeliveryMailClient::class, $mail);

        $today = CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne');
        $first = app(DeliveryConfirmationSendService::class)->sendDue($today, force: true);
        $second = app(DeliveryConfirmationSendService::class)->sendDue($today, force: true);

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $send = DeliveryConfirmationSend::query()->where('status', 'sent')->sole();
        $this->assertSame('vicsales@rcpa.com.au', $send->sender_mailbox);
    }

    public function test_qld_orders_send_from_the_queensland_shared_mailbox(): void
    {
        config()->set('delivery-reminders.enabled', true);
        $this->seedDueOrder('RCPQLD');

        $mail = Mockery::mock(MicrosoftGraphDeliveryMailClient::class);
        $mail->shouldReceive('send')
            ->once()
            ->withArgs(fn (DeliveryConfirmationMessage $message, string $mailbox): bool => $mailbox === 'salesqld@rcpa.com.au')
            ->andReturn('graph-qld-1');
        $this->app->instance(MicrosoftGraphDeliveryMailClient::class, $mail);

        $counts = app(DeliveryConfirmationSendService::class)->sendDue(
            CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne'),
            force: true,
        );

        $this->assertSame(1, $counts['sent']);
        $this->assertSame(
            'salesqld@rcpa.com.au',
            DeliveryConfirmationSend::query()->sole()->sender_mailbox,
        );
    }

    public function test_unmapped_entity_is_skipped_without_sending(): void
    {
        config()->set('delivery-reminders.enabled', true);
        config()->set('delivery-reminders.mailboxes.RCPVIC', null);
        config()->set('delivery-reminders.mailboxes.SRCVIC', null);
        config()->set('delivery-reminders.mailboxes.SRCVIV', null);
        $this->seedDueOrder('RCPVIC');

        $mail = Mockery::mock(MicrosoftGraphDeliveryMailClient::class);
        $mail->shouldNotReceive('send');
        $this->app->instance(MicrosoftGraphDeliveryMailClient::class, $mail);

        $counts = app(DeliveryConfirmationSendService::class)->sendDue(
            CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne'),
            force: true,
        );

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 1], $counts);
        $this->assertSame('skipped', DeliveryConfirmationSend::query()->sole()->status);
        $this->assertNull(DeliveryConfirmationSend::query()->sole()->sender_mailbox);
    }

    public function test_mark_seen_baselines_candidates_without_emailing(): void
    {
        $this->seedDueOrder('RCPVIC');

        $mail = Mockery::mock(MicrosoftGraphDeliveryMailClient::class);
        $mail->shouldNotReceive('send');
        $this->app->instance(MicrosoftGraphDeliveryMailClient::class, $mail);

        $counts = app(DeliveryConfirmationSendService::class)->markSeen(
            CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne'),
        );

        $this->assertSame(1, $counts['baselined']);
        $baseline = DeliveryConfirmationSend::query()->sole();
        $this->assertSame('baseline', $baseline->status);
        $this->assertSame('vicsales@rcpa.com.au', $baseline->sender_mailbox);
    }

    private function seedDueOrder(string $entity): void
    {
        $this->tencia->addOrderLine($entity, [
            'UNIQUE_ID' => 101,
            'REFERENCE_NBR' => '136510',
            'STOCK_CODE' => 'R0600-4',
            'BACKORDER_QTY' => 10,
            'LINE_DELIVERY_DATE' => '2026-10-20 00:00:00',
            'EMAIL_ADDRESS' => 'buyer@example.com',
        ]);
        $this->tencia->addOrderLine($entity, [
            'UNIQUE_ID' => 102,
            'REFERENCE_NBR' => '136510',
            'STOCK_CODE' => 'R0750-4',
            'BACKORDER_QTY' => 4,
            'LINE_DELIVERY_DATE' => '2026-10-22 00:00:00',
            'EMAIL_ADDRESS' => 'buyer@example.com',
        ]);
    }
}
