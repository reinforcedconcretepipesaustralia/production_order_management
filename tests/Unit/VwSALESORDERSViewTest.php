<?php

namespace Tests\Unit;

use App\Models\Rcpa\VwSALESORDERSView;
use Tests\TestCase;

class VwSALESORDERSViewTest extends TestCase
{
    public function test_queries_exclude_cancelled_headers_and_lines(): void
    {
        $query = VwSALESORDERSView::query();

        $this->assertStringContainsString('STATUS_FLAG', $query->toSql());
        $this->assertStringContainsString('LINE_STATUS', $query->toSql());
        $this->assertStringNotContainsString('TRANS_TYPE', $query->toSql());
        $this->assertSame(['C', 'C'], $query->getBindings());
    }

    public function test_outstanding_lines_are_those_still_to_make(): void
    {
        $query = VwSALESORDERSView::query()->outstanding();

        $this->assertStringContainsString('BACKORDER_QTY', $query->toSql());
        $this->assertStringNotContainsString('TRANS_TYPE', $query->toSql());
        $this->assertContains(0, $query->getBindings());
    }
}
