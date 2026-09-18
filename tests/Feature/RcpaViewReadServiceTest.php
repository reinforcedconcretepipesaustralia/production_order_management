<?php

namespace Tests\Feature;

use App\Services\Rcpa\RcpaViewReadService;
use App\Support\Rcpa\RcpaViewCatalog;
use Tests\TestCase;

class RcpaViewReadServiceTest extends TestCase
{
    public function test_preview_ignores_tables_that_are_not_mapped_view_models(): void
    {
        $preview = (new RcpaViewReadService(new RcpaViewCatalog))->previewMappedView('dbo.not_a_mapped_view');

        $this->assertSame([], $preview['columns']);
        $this->assertSame([], $preview['rows']);
    }

    public function test_preview_does_not_query_when_rcpa_sql_credentials_are_missing(): void
    {
        config([
            'database.connections.rcpa_sql.database' => '',
            'database.connections.rcpa_sql.username' => '',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        $preview = (new RcpaViewReadService(new RcpaViewCatalog))->previewMappedView('dbo.vwProduction');

        $this->assertSame([], $preview['rows']);
    }

    public function test_production_demand_does_not_query_when_rcpa_sql_credentials_are_missing(): void
    {
        config([
            'database.connections.rcpa_sql.database' => '',
            'database.connections.rcpa_sql.username' => '',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        $demand = (new RcpaViewReadService(new RcpaViewCatalog))->productionDemand('2026-09-01', '2026-09-30');

        $this->assertSame('unreadable', $demand['status']);
        $this->assertSame([], $demand['rows']);
        $this->assertStringContainsString('RCPA_SQL_', (string) $demand['reason']);
    }
}
