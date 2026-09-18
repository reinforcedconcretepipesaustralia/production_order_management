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

    public function test_classifies_denied_select_permission_as_a_dba_grant_without_leaking_object_names(): void
    {
        $exception = new RuntimeException(
            "[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]The SELECT permission was denied on the object 'TRANS_LINES', database 'TenciaRCP_WA', schema 'dbo'.",
        );

        $reason = RcpaSqlFailureClassifier::reason($exception);

        $this->assertSame(
            'The read-only login lacks SELECT permission on this view or its underlying tables. Ask the DBA to grant it.',
            $reason,
        );
        $this->assertStringNotContainsString('TenciaRCP_WA', $reason);
    }

    public function test_classifies_a_missing_view_distinctly_from_a_permission_failure(): void
    {
        $exception = new RuntimeException("[SQL Server]Invalid object name 'dbo.vwProduction'.");

        $this->assertSame(
            'This view does not exist on the target database.',
            RcpaSqlFailureClassifier::reason($exception),
        );
    }
}
