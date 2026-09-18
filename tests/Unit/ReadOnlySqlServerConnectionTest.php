<?php

namespace Tests\Unit;

use App\Exceptions\ReadOnlyConnectionException;
use App\Support\Database\ReadOnlySqlServerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReadOnlySqlServerConnectionTest extends TestCase
{
    private function connection(): ReadOnlySqlServerConnection
    {
        // The PDO resolver blows up if touched, proving each write is refused
        // before the connection is ever opened.
        return new ReadOnlySqlServerConnection(
            fn () => throw new RuntimeException('The read-only guard must reject writes before connecting.'),
            'rcpa',
            '',
            ['name' => 'rcpa_sql', 'readonly' => true],
        );
    }

    /**
     * @return array<string, array{0: callable(ReadOnlySqlServerConnection): mixed}>
     */
    public static function writeOperations(): array
    {
        return [
            'insert' => [fn (ReadOnlySqlServerConnection $c) => $c->insert('insert into [v] ([a]) values (?)', [1])],
            'update' => [fn (ReadOnlySqlServerConnection $c) => $c->update('update [v] set [a] = ?', [1])],
            'delete' => [fn (ReadOnlySqlServerConnection $c) => $c->delete('delete from [v]')],
            'statement' => [fn (ReadOnlySqlServerConnection $c) => $c->statement('drop view [v]')],
            'affectingStatement' => [fn (ReadOnlySqlServerConnection $c) => $c->affectingStatement('update [v] set [a] = 1')],
            'unprepared' => [fn (ReadOnlySqlServerConnection $c) => $c->unprepared('truncate table [v]')],
        ];
    }

    /**
     * @param  callable(ReadOnlySqlServerConnection): mixed  $operation
     */
    #[DataProvider('writeOperations')]
    public function test_write_operations_are_refused(callable $operation): void
    {
        $this->expectException(ReadOnlyConnectionException::class);
        $this->expectExceptionMessage('The [rcpa_sql] database connection is read-only.');

        $operation($this->connection());
    }
}
