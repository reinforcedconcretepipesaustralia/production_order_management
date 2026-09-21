<?php

namespace Tests\Feature;

use Tests\TestCase;

class RcpaSqlStatusCommandTest extends TestCase
{
    public function test_status_command_fails_without_exposing_secrets_when_the_connection_is_not_ready(): void
    {
        config([
            'database.connections.rcpa_sql.database' => '',
            'database.connections.rcpa_sql.username' => '',
            'database.connections.rcpa_sql.password' => '',
        ]);

        $expected = extension_loaded('pdo_sqlsrv')
            ? 'RCPA_SQL_DATABASE or RCPA_SQL_USERNAME is empty'
            : 'pdo_sqlsrv PHP extension is not loaded.';

        $this->artisan('rcpa:sql-status')
            ->expectsOutputToContain($expected)
            ->assertFailed();
    }

    public function test_status_command_does_not_print_password_config(): void
    {
        config([
            'database.connections.rcpa_sql.database' => '',
            'database.connections.rcpa_sql.username' => '',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        $this->artisan('rcpa:sql-status')
            ->expectsOutputToContain('Target:')
            ->doesntExpectOutputToContain('should-never-appear')
            ->assertFailed();
    }

    public function test_status_command_fails_immediately_when_the_sql_host_cannot_be_resolved(): void
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->markTestSkipped('pdo_sqlsrv is required for this diagnostic path.');
        }

        config([
            'database.connections.rcpa_sql.host' => 'rcpa-sql-does-not-exist.invalid',
            'database.connections.rcpa_sql.database' => 'TenciaRCP_TEST',
            'database.connections.rcpa_sql.username' => 'reader',
            'database.connections.rcpa_sql.password' => 'should-never-appear',
        ]);

        $this->artisan('rcpa:sql-status')
            ->expectsOutputToContain('The SQL Server host could not be resolved')
            ->doesntExpectOutputToContain('should-never-appear')
            ->assertFailed();
    }
}
