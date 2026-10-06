<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Enums\DeliveryConfirmationStage;
use App\Filament\Pages\DeliveryReminders;
use App\Models\DeliveryConfirmationLineResponse;
use App\Models\DeliveryConfirmationSend;
use App\Models\User;
use App\Services\DeliveryConfirmation\DeliveryReminderDashboardService;
use App\Support\Authorization\ShieldRoleCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeliveryRemindersPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(ShieldRoleCatalog::class)->sync();
    }

    public function test_delivery_reminders_page_requires_authentication(): void
    {
        $this->get(DeliveryReminders::getUrl())->assertRedirect();
    }

    public function test_depot_operator_cannot_open_delivery_reminders(): void
    {
        $user = User::factory()->withAppRoles(AppRole::DepotOperator)->create();

        $this->actingAs($user)->get(DeliveryReminders::getUrl())->assertForbidden();
    }

    public function test_planner_can_open_delivery_reminders_and_see_settings_without_secrets(): void
    {
        config([
            'delivery-reminders.enabled' => false,
            'delivery-reminders.overdue_max_days_past' => 14,
            'delivery-reminders.graph.client_secret' => 'graph-secret-must-not-render',
            'database.connections.rcpa_sql_write.password' => 'sql-write-secret-must-not-render',
        ]);

        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->get(DeliveryReminders::getUrl())
            ->assertOk()
            ->assertSee('Delivery reminders')
            ->assertSee('Sending is off')
            ->assertSee('DELIVERY_REMINDERS_ENABLED=true')
            ->assertSee('From addresses by entity')
            ->assertSee('vicsales@rcpa.com.au')
            ->assertSee('salesnsw@rcpa.com.au')
            ->assertSee('salesqld@rcpa.com.au')
            ->assertSee('salessa@rcpa.com.au')
            ->assertSee('4 weeks')
            ->assertSee('2 weeks')
            ->assertSee('overdue')
            ->assertSee('Daily at 07:30 Australia/Melbourne')
            ->assertSee('RCPVIC')
            ->assertSee('RCPNSW')
            ->assertDontSee('graph-secret-must-not-render')
            ->assertDontSee('sql-write-secret-must-not-render')
            ->assertDontSee('MICROSOFT_GRAPH_CLIENT_SECRET')
            ->assertDontSee('RCPA_SQL_WRITE_PASSWORD');
    }

    public function test_executive_viewer_can_open_delivery_reminders(): void
    {
        $user = User::factory()->withAppRoles(AppRole::ViewerExecutive)->create();

        $this->actingAs($user)->get(DeliveryReminders::getUrl())->assertOk();
        $this->assertTrue($user->can(
            app(ShieldRoleCatalog::class)->pagePermissionKey(DeliveryReminders::class),
        ));
    }

    public function test_dashboard_counts_are_grouped_by_entity(): void
    {
        $vicSend = DeliveryConfirmationSend::query()->create([
            'tracking_token' => 'DC-VIC00000000001',
            'entity' => 'RCPVIC',
            'reference_nbr' => '1001',
            'reference_duplicate' => 0,
            'recipient_email' => 'vic@example.com',
            'sender_mailbox' => 'vicsales@rcpa.com.au',
            'stage' => DeliveryConfirmationStage::Weeks12,
            'target_delivery_date' => '2026-10-10',
            'line_count' => 1,
            'total_outstanding_qty' => 5,
            'status' => 'sent',
            'sent_at' => now(),
            'reply_outcome' => 'processed',
        ]);
        DeliveryConfirmationLineResponse::query()->create([
            'delivery_confirmation_send_id' => $vicSend->id,
            'line_unique_id' => 11,
            'stock_code' => 'R0600-4',
            'outstanding_qty' => 5,
            'current_delivery_date' => '2026-10-10',
            'action' => 'confirm',
            'outcome' => 'confirmed',
            'needs_rep_approval' => false,
        ]);

        $nswSend = DeliveryConfirmationSend::query()->create([
            'tracking_token' => 'DC-NSW00000000001',
            'entity' => 'RCPNSW',
            'reference_nbr' => '2002',
            'reference_duplicate' => 0,
            'recipient_email' => 'nsw@example.com',
            'sender_mailbox' => 'salesnsw@rcpa.com.au',
            'stage' => DeliveryConfirmationStage::Weeks34,
            'target_delivery_date' => '2026-10-20',
            'line_count' => 2,
            'total_outstanding_qty' => 8,
            'status' => 'sent',
            'sent_at' => now(),
            'reply_outcome' => 'unresolved',
        ]);
        DeliveryConfirmationLineResponse::query()->create([
            'delivery_confirmation_send_id' => $nswSend->id,
            'line_unique_id' => 21,
            'stock_code' => 'R0750-4',
            'outstanding_qty' => 4,
            'current_delivery_date' => '2026-10-20',
            'requested_delivery_date' => '2026-10-25',
            'action' => 'amend',
            'outcome' => 'written',
            'needs_rep_approval' => false,
        ]);
        DeliveryConfirmationLineResponse::query()->create([
            'delivery_confirmation_send_id' => $nswSend->id,
            'line_unique_id' => 22,
            'stock_code' => 'R0900-4',
            'outstanding_qty' => 4,
            'current_delivery_date' => '2026-10-12',
            'requested_delivery_date' => '2026-10-01',
            'action' => 'amend',
            'outcome' => 'pending_rep_approval',
            'needs_rep_approval' => true,
        ]);

        $summaries = collect(app(DeliveryReminderDashboardService::class)->entitySummaries())
            ->keyBy('entity');

        $this->assertSame(1, $summaries['RCPVIC']['sent']);
        $this->assertSame(1, $summaries['RCPVIC']['confirmed']);
        $this->assertSame(0, $summaries['RCPVIC']['written']);
        $this->assertSame(0, $summaries['RCPVIC']['pending_rep_approval']);
        $this->assertSame(0, $summaries['RCPVIC']['unresolved']);

        $this->assertSame(1, $summaries['RCPNSW']['sent']);
        $this->assertSame(0, $summaries['RCPNSW']['confirmed']);
        $this->assertSame(1, $summaries['RCPNSW']['written']);
        $this->assertSame(1, $summaries['RCPNSW']['pending_rep_approval']);
        $this->assertSame(1, $summaries['RCPNSW']['unresolved']);

        $this->assertSame(0, $summaries['RCPQLD']['sent']);
        $this->assertSame(0, $summaries['RCPSA']['sent']);

        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->get(DeliveryReminders::getUrl())
            ->assertOk()
            ->assertSee('1001')
            ->assertSee('2002')
            ->assertSee('vic@example.com')
            ->assertSee('nsw@example.com')
            ->assertSee('vicsales@rcpa.com.au')
            ->assertSee('salesnsw@rcpa.com.au');
    }
}
