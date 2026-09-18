<?php

namespace Tests\Feature;

use App\Support\Database\ReadOnlySqlServerConnection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RcpaSqlConnectionConfigTest extends TestCase
{
    public function test_rcpa_sql_is_a_read_only_sql_server_connection_separate_from_the_default(): void
    {
        $this->assertSame('sqlite', config('database.default'));

        $connection = config('database.connections.rcpa_sql');

        $this->assertIsArray($connection);
        $this->assertSame('sqlsrv', $connection['driver']);
        $this->assertTrue($connection['readonly']);
        $this->assertSame('RCP-AUS-SQL01', $connection['host']);
        $this->assertSame('1433', (string) $connection['port']);
        $this->assertArrayHasKey('database', $connection);
        $this->assertArrayHasKey('username', $connection);
        $this->assertArrayHasKey('password', $connection);
        $this->assertNotSame(config('database.connections.sqlite'), $connection);
    }

    public function test_rcpa_sql_resolves_to_a_connection_that_refuses_writes(): void
    {
        // Laravel builds the connection lazily, so no server is contacted here.
        $this->assertInstanceOf(
            ReadOnlySqlServerConnection::class,
            DB::connection('rcpa_sql'),
        );
    }

    public function test_a_sql_server_connection_without_the_readonly_flag_is_left_alone(): void
    {
        config(['database.connections.rcpa_sql_writable' => array_merge(
            config('database.connections.rcpa_sql'),
            ['readonly' => false],
        )]);

        $connection = DB::connection('rcpa_sql_writable');

        $this->assertInstanceOf(SqlServerConnection::class, $connection);
        $this->assertNotInstanceOf(ReadOnlySqlServerConnection::class, $connection);
    }
}
