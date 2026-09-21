<?php

namespace Tests\Unit;

use App\Models\Rcpa\VwSALESORDERSView;
use Tests\TestCase;

class VwSALESORDERSViewTest extends TestCase
{
    public function test_queries_exclude_cancelled_suspended_and_closed_headers(): void
    {
        $query = VwSALESORDERSView::query();

        $this->assertStringContainsString('STATUS_FLAG', $query->toSql());
        $this->assertSame(['C', 'S', 'X'], $query->getBindings());
    }
}
