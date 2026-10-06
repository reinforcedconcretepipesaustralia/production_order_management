<?php

namespace Tests\Feature;

use App\Enums\DeliveryConfirmationStage;
use App\Models\DeliveryConfirmationLineResponse;
use App\Models\DeliveryConfirmationSend;
use App\Services\DeliveryConfirmation\DeliveryConfirmationReplyService;
use App\Services\DeliveryConfirmation\GraphMailboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

class DeliveryConfirmationReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenciaSource $tencia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tencia = FakeTenciaSource::install()->createTables();
        config()->set('delivery-reminders.overdue_max_days_past', 14);
        config()->set('database.connections.rcpa_sql_write.username', 'writer');
        config()->set('database.connections.rcpa_sql_write.database', 'local');
    }

    public function test_confirm_records_locally_without_writing_tencia(): void
    {
        $this->seedOrderLine(101, '2026-10-10 00:00:00');
        $send = $this->sentReminder();
        $today = CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne');

        $counts = app(DeliveryConfirmationReplyService::class)->applyReply(
            $send,
            $this->reply('LINE 101 CONFIRM'),
            $today,
        );

        $this->assertSame(1, $counts['confirmed']);
        $response = DeliveryConfirmationLineResponse::query()->sole();
        $this->assertSame('confirmed', $response->outcome);
        $this->assertFalse($response->needs_rep_approval);
        $this->assertSame(
            '2026-10-10 00:00:00',
            DB::table($this->tencia->stateTableName('RCPVIC', 'SOR_LINES'))->where('UNIQUE_ID', 101)->value('DELIVERY_DATE'),
        );
    }

    public function test_earlier_date_inside_two_week_window_is_held_for_rep_approval(): void
    {
        $this->seedOrderLine(101, '2026-10-05 00:00:00');
        $send = $this->sentReminder(stage: DeliveryConfirmationStage::Weeks12);
        $today = CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne');

        $counts = app(DeliveryConfirmationReplyService::class)->applyReply(
            $send,
            $this->reply('LINE 101 NEW DATE: 01/10/2026'),
            $today,
        );

        $this->assertSame(1, $counts['pending_approval']);
        $response = DeliveryConfirmationLineResponse::query()->sole();
        $this->assertSame('pending_rep_approval', $response->outcome);
        $this->assertTrue($response->needs_rep_approval);
        $this->assertSame('2026-10-01', $response->requested_delivery_date->toDateString());
        $this->assertSame(
            '2026-10-05 00:00:00',
            DB::table($this->tencia->stateTableName('RCPVIC', 'SOR_LINES'))->where('UNIQUE_ID', 101)->value('DELIVERY_DATE'),
        );
    }

    public function test_later_date_inside_two_week_window_writes_only_that_line(): void
    {
        $this->seedOrderLine(101, '2026-10-05 00:00:00');
        $this->seedOrderLine(102, '2026-10-08 00:00:00', reference: '136510');
        $send = $this->sentReminder(stage: DeliveryConfirmationStage::Weeks12);
        $today = CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne');

        $counts = app(DeliveryConfirmationReplyService::class)->applyReply(
            $send,
            $this->reply('LINE 101 NEW DATE: 20/10/2026'),
            $today,
        );

        $this->assertSame(1, $counts['written']);
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

    public function test_earlier_date_outside_two_week_window_writes_to_tencia(): void
    {
        $this->seedOrderLine(101, '2026-10-20 00:00:00');
        $send = $this->sentReminder(stage: DeliveryConfirmationStage::Weeks34);
        $today = CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne');

        $counts = app(DeliveryConfirmationReplyService::class)->applyReply(
            $send,
            $this->reply('LINE 101 NEW DATE: 10/10/2026'),
            $today,
        );

        $this->assertSame(1, $counts['written']);
        $this->assertSame(
            '2026-10-10 00:00:00',
            DB::table($this->tencia->stateTableName('RCPVIC', 'SOR_LINES'))->where('UNIQUE_ID', 101)->value('DELIVERY_DATE'),
        );
    }

    public function test_ambiguous_reply_is_stored_and_writes_nothing(): void
    {
        $this->seedOrderLine(101, '2026-10-20 00:00:00');
        $send = $this->sentReminder();
        $today = CarbonImmutable::parse('2026-09-30', 'Australia/Melbourne');

        $counts = app(DeliveryConfirmationReplyService::class)->applyReply(
            $send,
            $this->reply("Please move it earlier.\nMaybe 10/10/2026?"),
            $today,
        );

        $this->assertSame(1, $counts['unresolved']);
        $this->assertSame(0, DeliveryConfirmationLineResponse::query()->count());
        $this->assertSame('unresolved', $send->fresh()->reply_outcome);
        $this->assertSame(
            '2026-10-20 00:00:00',
            DB::table($this->tencia->stateTableName('RCPVIC', 'SOR_LINES'))->where('UNIQUE_ID', 101)->value('DELIVERY_DATE'),
        );
    }

    private function seedOrderLine(int $lineId, string $deliveryDate, string $reference = '136510'): void
    {
        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => $lineId,
            'REFERENCE_NBR' => $reference,
            'STOCK_CODE' => 'R0600-4',
            'BACKORDER_QTY' => 10,
            'LINE_DELIVERY_DATE' => $deliveryDate,
            'EMAIL_ADDRESS' => 'buyer@example.com',
            'CUSTOMER_NAME' => 'Acme',
        ]);
    }

    private function sentReminder(DeliveryConfirmationStage $stage = DeliveryConfirmationStage::Weeks34): DeliveryConfirmationSend
    {
        return DeliveryConfirmationSend::query()->create([
            'tracking_token' => 'DC-TESTTOKEN0001',
            'entity' => 'RCPVIC',
            'reference_nbr' => '136510',
            'reference_duplicate' => 0,
            'customer_code' => '1008',
            'customer_name' => 'Acme',
            'recipient_email' => 'buyer@example.com',
            'sender_mailbox' => 'vicsales@rcpa.com.au',
            'stage' => $stage,
            'target_delivery_date' => '2026-10-10',
            'line_count' => 1,
            'total_outstanding_qty' => 10,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    private function reply(string $body): GraphMailboxMessage
    {
        return new GraphMailboxMessage(
            id: 'graph-msg-1',
            subject: 'Re: RCPA delivery reminder — order 136510 (4 weeks) [DC-TESTTOKEN0001]',
            bodyText: $body."\n\nTracking code: DC-TESTTOKEN0001",
            receivedAt: now()->toIso8601String(),
            fromAddress: 'buyer@example.com',
            mailbox: 'vicsales@rcpa.com.au',
        );
    }
}
