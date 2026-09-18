<?php

namespace Tests\Unit;

use App\Exceptions\ReadOnlyConnectionException;
use App\Models\Rcpa\ReadOnlyView;
use App\Support\Database\ReadOnlyEloquentBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RcpaReadOnlyViewTest extends TestCase
{
    public function test_read_only_view_uses_the_rcpa_sql_connection(): void
    {
        $model = new class extends ReadOnlyView
        {
            protected $table = 'example_view';
        };

        $this->assertSame('rcpa_sql', $model->getConnectionName());
        $this->assertFalse($model->incrementing);
        $this->assertFalse($model->timestamps);
    }

    public function test_saving_a_read_only_view_is_rejected(): void
    {
        $model = new class extends ReadOnlyView
        {
            protected $table = 'example_view';
        };

        $this->expectException(ReadOnlyConnectionException::class);
        $this->expectExceptionMessage('The RCPA SQL connection is read-only.');

        $model->save();
    }

    public function test_eloquent_write_operations_are_rejected(): void
    {
        $builder = (new ReflectionClass(ReadOnlyEloquentBuilder::class))
            ->newInstanceWithoutConstructor();

        $this->expectException(ReadOnlyConnectionException::class);

        $builder->update(['name' => 'changed']);
    }
}
