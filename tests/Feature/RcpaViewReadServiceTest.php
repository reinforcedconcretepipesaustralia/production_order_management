<?php

namespace Tests\Feature;

use App\Services\Rcpa\RcpaViewReadService;
use App\Support\Rcpa\RcpaSqlFailureClassifier;
use App\Support\Rcpa\RcpaViewCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_stock_and_sales_order_reads_do_not_query_when_credentials_are_missing(): void
    {
        config([
            'database.connections.rcpa_sql.database' => '',
            'database.connections.rcpa_sql.username' => '',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        $reader = new RcpaViewReadService(new RcpaViewCatalog);
        $stock = $reader->stockOnHand();
        $orders = $reader->openSalesOrders('2026-09-01', '2026-10-15');

        $this->assertSame('unreadable', $stock['status']);
        $this->assertSame([], $stock['rows']);
        $this->assertSame('unreadable', $orders['status']);
        $this->assertSame([], $orders['rows']);
    }

    public function test_sku_master_and_bom_reads_do_not_query_when_credentials_are_missing(): void
    {
        config([
            'database.connections.rcpa_sql.database' => '',
            'database.connections.rcpa_sql.username' => '',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        $reader = new RcpaViewReadService(new RcpaViewCatalog);
        $skus = $reader->skuMaster();
        $bom = $reader->bomLines();

        $this->assertSame('unreadable', $skus['status']);
        $this->assertSame([], $skus['rows']);
        $this->assertSame('unreadable', $bom['status']);
        $this->assertSame([], $bom['rows']);
    }

    public function test_a_recent_connection_failure_short_circuits_further_reads(): void
    {
        config([
            'database.connections.rcpa_sql.host' => '127.0.0.1',
            'database.connections.rcpa_sql.database' => 'TenciaRCP_TEST',
            'database.connections.rcpa_sql.username' => 'reader',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        Cache::put('rcpa_sql.connection_failure_reason', 'SQL Server instance is unavailable.', 60);

        $reader = new RcpaViewReadService(new RcpaViewCatalog);
        $demand = $reader->productionDemand('2026-09-01', '2026-09-30');
        $stock = $reader->stockOnHand();

        $this->assertSame('SQL Server instance is unavailable.', $demand['reason']);
        $this->assertSame('SQL Server instance is unavailable.', $stock['reason']);
    }

    public function test_a_failed_handshake_is_cached_so_pages_do_not_retry_every_view(): void
    {
        config([
            'database.connections.rcpa_sql.host' => '127.0.0.1',
            'database.connections.rcpa_sql.port' => '1',
            'database.connections.rcpa_sql.database' => 'TenciaRCP_TEST',
            'database.connections.rcpa_sql.username' => 'reader',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        DB::purge('rcpa_sql');
        Cache::forget('rcpa_sql.connection_failure_reason');

        $stock = (new RcpaViewReadService(new RcpaViewCatalog))->stockOnHand();

        $this->assertSame('unreadable', $stock['status']);
        $this->assertIsString(Cache::get('rcpa_sql.connection_failure_reason'));
        $this->assertStringNotContainsString('should-never-appear', (string) $stock['reason']);
    }

    public function test_an_unresolvable_host_fails_without_opening_a_sql_handshake(): void
    {
        config([
            'database.connections.rcpa_sql.host' => 'rcpa-sql-does-not-exist.invalid',
            'database.connections.rcpa_sql.database' => 'TenciaRCP_TEST',
            'database.connections.rcpa_sql.username' => 'reader',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        Cache::forget('rcpa_sql.connection_failure_reason');

        $stock = (new RcpaViewReadService(new RcpaViewCatalog))->stockOnHand();

        $this->assertSame('unreadable', $stock['status']);
        $this->assertSame(RcpaSqlFailureClassifier::UNRESOLVED_HOST, $stock['reason']);
        $this->assertStringNotContainsString('should-never-appear', (string) $stock['reason']);
    }
}
