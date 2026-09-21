<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Pages\ErpViews;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class ErpViewsPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_erp_views_page_requires_authentication(): void
    {
        $this->get(ErpViews::getUrl())->assertRedirect();
    }

    public function test_erp_views_page_forbids_users_without_master_data_access(): void
    {
        $user = User::factory()->withAppRoles(AppRole::ViewerExecutive)->create();

        $this->actingAs($user)->get(ErpViews::getUrl())->assertForbidden();
    }

    public function test_planner_can_open_erp_views_and_see_mapped_view_tables(): void
    {
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->get(ErpViews::getUrl())
            ->assertOk()
            ->assertSee('dbo.vwProduction')
            ->assertSee('dbo.vwSALES_ORDERS')
            ->assertDontSee('dbo.vwQUOTES')
            ->assertDontSee('RCPA_SQL_PASSWORD');
    }

    public function test_erp_views_page_shows_setup_steps_when_the_sql_host_cannot_be_resolved(): void
    {
        config([
            'database.connections.rcpa_sql.host' => 'rcpa-sql-does-not-exist.invalid',
            'database.connections.rcpa_sql.database' => 'TenciaRCP_TEST',
            'database.connections.rcpa_sql.username' => 'reader',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);
        Cache::forget('rcpa_sql.connection_failure_reason');

        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->get(ErpViews::getUrl())
            ->assertOk()
            ->assertSee('could not be resolved')
            ->assertSee('Connect the corporate VPN or office Ethernet')
            ->assertSee('php artisan rcpa:pull-views')
            ->assertDontSee('should-never-appear');
    }

    public function test_retrying_the_connection_clears_the_cached_handshake_failure(): void
    {
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        Cache::put('rcpa_sql.connection_failure_reason', 'SQL Server instance is unavailable.', 60);

        Livewire::actingAs($user)
            ->test(ErpViews::class)
            ->call('retryConnection')
            ->assertOk();

        $this->assertNull(Cache::get('rcpa_sql.connection_failure_reason'));
    }
}
