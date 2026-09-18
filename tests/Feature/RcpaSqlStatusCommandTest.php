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
}
