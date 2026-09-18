<?php

namespace Tests\Feature;

use Tests\TestCase;

class RcpaPullViewsCommandTest extends TestCase
{
    public function test_pull_views_fails_without_exposing_secrets_when_the_connection_is_not_ready(): void
    {
        config([
            'database.connections.rcpa_sql.database' => '',
            'database.connections.rcpa_sql.username' => '',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        $expected = extension_loaded('pdo_sqlsrv')
            ? '[unreadable] dbo.vwProduction'
            : 'pdo_sqlsrv PHP extension is not loaded.';

        $this->artisan('rcpa:pull-views')
            ->expectsOutputToContain($expected)
            ->doesntExpectOutputToContain('should-never-appear')
            ->assertFailed();
    }
}
