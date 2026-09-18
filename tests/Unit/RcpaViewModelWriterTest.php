<?php

namespace Tests\Unit;

use App\Support\Rcpa\RcpaViewModelWriter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RcpaViewModelWriterTest extends TestCase
{
    public function test_it_builds_a_read_only_view_model_from_column_metadata(): void
    {
        $writer = new RcpaViewModelWriter;

        $php = $writer->contents('dbo.ProductionOrders', [
            ['name' => 'OrderId', 'type_name' => 'int', 'auto_increment' => false],
            ['name' => 'DueDate', 'type_name' => 'date', 'auto_increment' => false],
            ['name' => 'IsComplete', 'type_name' => 'bit', 'auto_increment' => false],
        ], 'OrderId');

        $this->assertSame('ProductionOrdersView', $writer->className('dbo.ProductionOrders'));
        $this->assertStringContainsString("protected \$table = 'dbo.ProductionOrders';", $php);
        $this->assertStringContainsString("protected \$primaryKey = 'OrderId';", $php);
        $this->assertStringContainsString('extends ReadOnlyView', $php);
        $this->assertStringContainsString("'DueDate' => 'date'", $php);
        $this->assertStringContainsString("'IsComplete' => 'boolean'", $php);
        $this->assertStringNotContainsString('password', strtolower($php));
    }

    public function test_it_rejects_a_primary_key_that_is_not_in_the_view(): void
    {
        $writer = new RcpaViewModelWriter;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column [MissingId] does not exist on view [dbo.ProductionOrders].');

        $writer->contents('dbo.ProductionOrders', [
            ['name' => 'name'],
            ['name' => 'id'],
        ], 'MissingId');
    }
}
