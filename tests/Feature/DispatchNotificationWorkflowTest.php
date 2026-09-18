<?php

namespace Tests\Feature;

use App\Contracts\DispatchNotificationSender;
use App\Enums\AppRole;
use App\Exceptions\DispatchNotificationException;
use App\Filament\Pages\LogisticsLoadBuilder;
use App\Jobs\SendDispatchNotification;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\DispatchNotificationAttempt;
use App\Models\InventorySoh;
use App\Models\OrderAllocation;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Models\User;
use App\Services\Dispatch\DispatchNotification;
use App\Services\Dispatch\DispatchNotificationFactory;
use App\Services\Dispatch\DispatchNotificationManager;
use App\Services\Dispatch\DispatchNotificationResult;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DispatchNotificationWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_dispatcher_can_queue_a_depot_scoped_notification_with_valid_recipients(): void
    {
        $depot = Depot::factory()->create();
        $dispatcher = User::factory()->withAppRoles(AppRole::LogisticsDispatcher)->create();
        $dispatcher->depots()->attach($depot);
        $load = $this->readyLoad($depot);
        Queue::fake([SendDispatchNotification::class]);

        Livewire::actingAs($dispatcher)
            ->test(LogisticsLoadBuilder::class)
            ->set('dispatchLoadId', $load->id)
            ->assertSee('Send dispatch notification')
            ->set('dispatchRecipients', 'CUSTOMER@example.com; carrier@example.com')
            ->call('sendDispatchNotification')
            ->assertHasNoErrors();

        $attempt = DispatchNotificationAttempt::query()->sole();
        $this->assertSame(['customer@example.com', 'carrier@example.com'], $attempt->recipients);
        $this->assertSame($dispatcher->id, $attempt->actor_user_id);
        $this->assertSame('queued', $attempt->status);
        Queue::assertPushed(
            SendDispatchNotification::class,
            fn (SendDispatchNotification $job): bool => $job->attemptId === $attempt->id,
        );
    }

    public function test_notification_action_rejects_view_only_cross_depot_and_invalid_recipient_requests(): void
    {
        $assignedDepot = Depot::factory()->create();
        $otherDepot = Depot::factory()->create();
        $dispatcher = User::factory()->withAppRoles(AppRole::LogisticsDispatcher)->create();
        $dispatcher->depots()->attach($assignedDepot);
        $executive = User::factory()->withAppRoles(AppRole::ViewerExecutive)->create();
        $assignedLoad = $this->readyLoad($assignedDepot);
        $otherLoad = $this->readyLoad($otherDepot);
        Queue::fake([SendDispatchNotification::class]);

        Livewire::actingAs($executive)
            ->test(LogisticsLoadBuilder::class)
            ->set('dispatchLoadId', $assignedLoad->id)
            ->assertDontSee('Send dispatch notification')
            ->set('dispatchRecipients', 'viewer@example.com')
            ->call('sendDispatchNotification')
            ->assertForbidden();

        Livewire::actingAs($dispatcher)
            ->test(LogisticsLoadBuilder::class)
            ->set('dispatchLoadId', $otherLoad->id)
            ->set('dispatchRecipients', 'dispatcher@example.com')
            ->call('sendDispatchNotification')
            ->assertNotFound();

        Livewire::actingAs($dispatcher)
            ->test(LogisticsLoadBuilder::class)
            ->set('dispatchLoadId', $assignedLoad->id)
            ->set('dispatchRecipients', 'not-an-email')
            ->call('sendDispatchNotification')
            ->assertHasErrors(['dispatchRecipients']);

        $this->assertDatabaseCount('dispatch_notification_attempts', 0);
        Queue::assertNothingPushed();
    }

    public function test_planner_admin_can_queue_without_a_depot_assignment_but_an_empty_load_is_rejected(): void
    {
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        $depot = Depot::factory()->create();
        $load = DispatchLoad::factory()->create([
            'depot_id' => $depot->id,
            'dispatch_date' => now()->addDay(),
        ]);
        Queue::fake([SendDispatchNotification::class]);

        Livewire::actingAs($planner)
            ->test(LogisticsLoadBuilder::class)
            ->set('dispatchLoadId', $load->id)
            ->assertSee('Send dispatch notification')
            ->set('dispatchRecipients', 'planner@example.com')
            ->call('sendDispatchNotification')
            ->assertHasErrors(['dispatchRecipients']);

        $this->assertDatabaseCount('dispatch_notification_attempts', 0);
        Queue::assertNothingPushed();
    }

    public function test_idempotency_blocks_duplicate_requests_and_allows_intentional_failed_retry(): void
    {
        $depot = Depot::factory()->create();
        $actor = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        $load = $this->readyLoad($depot);
        Queue::fake([SendDispatchNotification::class]);
        $manager = app(DispatchNotificationManager::class);

        $first = $manager->queue($load, ['first@example.com'], $actor);
        $duplicate = $manager->queue($load, ['duplicate@example.com'], $actor);

        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(['first@example.com'], $duplicate->recipients);
        $this->assertDatabaseCount('dispatch_notification_attempts', 1);
        Queue::assertPushed(SendDispatchNotification::class, 1);

        $first->update([
            'status' => 'failed',
            'failure_category' => 'provider-rejected',
            'failure_message' => 'The provider rejected the notification.',
        ]);
        $retry = $manager->queue($load, ['retry@example.com'], $actor);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame('queued', $retry->status);
        $this->assertSame(['retry@example.com'], $retry->recipients);
        Queue::assertPushed(SendDispatchNotification::class, 2);
    }

    public function test_job_records_success_and_builds_a_practical_escaped_load_payload(): void
    {
        $depot = Depot::factory()->create(['code' => 'MEL', 'name' => 'Melbourne']);
        $load = $this->readyLoad($depot, [
            'load_reference' => 'LOAD-200',
            'capacity_tonnes' => 4,
            'current_weight_tonnes' => 2,
        ], [
            'erp_order_reference' => 'SO-200',
            'customer_name' => '<script>Customer</script>',
            'customer_code' => 'C-200',
            'delivery_address' => '1 Test Road',
        ]);
        $attempt = DispatchNotificationAttempt::factory()->create([
            'dispatch_load_id' => $load->id,
            'recipients' => ['customer@example.com'],
            'actor_user_id' => User::factory(),
        ]);
        $sender = $this->mock(DispatchNotificationSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->withArgs(function (DispatchNotification $notification): bool {
                $this->assertSame(['customer@example.com'], $notification->recipients);
                $this->assertStringContainsString('LOAD-200', $notification->subject);
                $this->assertStringContainsString('SO-200', $notification->htmlBody);
                $this->assertStringContainsString('50.0%', $notification->htmlBody);
                $this->assertStringContainsString('&lt;script&gt;Customer&lt;/script&gt;', $notification->htmlBody);
                $this->assertStringNotContainsString('<script>Customer</script>', $notification->htmlBody);

                return true;
            })
            ->andReturn(new DispatchNotificationResult('graph-request-200'));

        (new SendDispatchNotification($attempt->id))->handle(
            $sender,
            app(DispatchNotificationFactory::class),
        );
        (new SendDispatchNotification($attempt->id))->handle(
            $sender,
            app(DispatchNotificationFactory::class),
        );

        $attempt->refresh();
        $this->assertSame('sent', $attempt->status);
        $this->assertSame(1, $attempt->attempt_count);
        $this->assertSame('graph-request-200', $attempt->provider_request_id);
        $this->assertNotNull($attempt->sent_at);
        $this->assertSame('sent', $load->fresh()->notification_status);
    }

    public function test_job_records_only_sanitized_failure_details(): void
    {
        $load = $this->readyLoad(Depot::factory()->create());
        $attempt = DispatchNotificationAttempt::factory()->create([
            'dispatch_load_id' => $load->id,
            'actor_user_id' => User::factory(),
        ]);
        $sender = $this->mock(DispatchNotificationSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->andThrow(DispatchNotificationException::authentication());

        (new SendDispatchNotification($attempt->id))->handle(
            $sender,
            app(DispatchNotificationFactory::class),
        );

        $attempt->refresh();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('authentication', $attempt->failure_category);
        $this->assertSame('The dispatch email provider rejected its credentials.', $attempt->failure_message);
        $this->assertStringNotContainsString('secret', mb_strtolower($attempt->failure_message));
        $this->assertSame('failed', $load->fresh()->notification_status);
    }

    /**
     * @param  array<string, mixed>  $loadAttributes
     * @param  array<string, mixed>  $orderAttributes
     */
    private function readyLoad(
        Depot $depot,
        array $loadAttributes = [],
        array $orderAttributes = [],
    ): DispatchLoad {
        $sku = SkuMaster::factory()->create([
            'sku_code' => "PIPE-{$depot->id}",
            'unit_weight_tonnes' => 1,
        ]);
        $order = SalesOrder::factory()->create(array_merge([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
        ], $orderAttributes));
        $inventory = InventorySoh::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
        ]);
        $allocation = OrderAllocation::factory()->create([
            'sales_order_id' => $order->id,
            'inventory_soh_id' => $inventory->id,
            'quantity' => 2,
        ]);
        $load = DispatchLoad::factory()->create(array_merge([
            'depot_id' => $depot->id,
            'dispatch_date' => now()->addDay(),
            'capacity_tonnes' => 4,
            'current_weight_tonnes' => 2,
        ], $loadAttributes));
        DispatchLoadItem::factory()->create([
            'dispatch_load_id' => $load->id,
            'order_allocation_id' => $allocation->id,
            'quantity' => 2,
            'weight_tonnes' => 2,
        ]);

        return $load;
    }
}
