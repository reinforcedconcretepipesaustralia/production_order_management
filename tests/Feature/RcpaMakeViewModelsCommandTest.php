<?php

namespace Tests\Feature;

use Tests\TestCase;

class RcpaMakeViewModelsCommandTest extends TestCase
{
    public function test_make_view_models_fails_closed_without_printing_secrets(): void
    {
        config([
            'database.connections.rcpa_sql.database' => '',
            'database.connections.rcpa_sql.username' => '',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        $expected = extension_loaded('pdo_sqlsrv')
            ? 'RCPA_SQL_DATABASE or RCPA_SQL_USERNAME is empty'
            : 'pdo_sqlsrv PHP extension is not loaded.';

        $this->artisan('rcpa:make-view-models', ['views' => ['dbo.ProductionOrders']])
            ->expectsOutputToContain($expected)
            ->doesntExpectOutputToContain('should-never-appear')
            ->assertFailed();
    }

    public function test_make_view_models_requires_an_explicit_primary_key(): void
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->markTestSkipped('pdo_sqlsrv is required to reach primary-key validation.');
        }

        config([
            'database.connections.rcpa_sql.database' => 'database-name',
            'database.connections.rcpa_sql.username' => 'username',
        ]);

        $this->artisan('rcpa:make-view-models', ['views' => ['dbo.ProductionOrders']])
            ->expectsOutputToContain('No primary key supplied for [dbo.ProductionOrders]')
            ->assertFailed();
    }
}
