<?php

namespace Tests\Unit;

use App\Support\Rcpa\RcpaSqlFailureClassifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RcpaSqlFailureClassifierTest extends TestCase
{
    public function test_classifies_tcp_failures_as_unavailable_without_using_the_raw_message(): void
    {
        $exception = new RuntimeException('[Microsoft][ODBC Driver 18 for SQL Server]TCP Provider: connection refused');

        $this->assertSame(
            'SQL Server instance is unavailable.',
            RcpaSqlFailureClassifier::reason($exception),
        );
    }
}
