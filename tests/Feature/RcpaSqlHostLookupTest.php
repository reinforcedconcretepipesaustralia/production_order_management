<?php

namespace Tests\Feature;

use App\Support\Rcpa\RcpaSqlFailureClassifier;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The pre-flight lookup decides whether a connection is attempted at all, so a wrong answer here
 * takes the whole ERP layer offline. The unit tests inject a resolved address and so never run the
 * subprocess; these cover the subprocess itself.
 */
class RcpaSqlHostLookupTest extends TestCase
{
    public function test_a_host_that_resolves_is_not_reported_as_a_vpn_problem(): void
    {
        Process::fake(['*' => Process::result('192.168.60.3')]);

        $this->assertNull(RcpaSqlFailureClassifier::unresolvedHostReason('RCP-AUS-SQL01'));
    }

    /**
     * Regression: the lookup used to run quietly(), which discards output, so every lookup read an
     * empty string and the whole ERP layer reported itself as off the VPN while on the network.
     */
    public function test_a_lookup_that_cannot_answer_lets_the_handshake_decide(): void
    {
        Process::fake(['*' => Process::result('')]);

        $this->assertNull(RcpaSqlFailureClassifier::unresolvedHostReason('RCP-AUS-SQL01'));
    }

    public function test_a_failed_lookup_lets_the_handshake_decide(): void
    {
        Process::fake(['*' => Process::result('', '', 1)]);

        $this->assertNull(RcpaSqlFailureClassifier::unresolvedHostReason('RCP-AUS-SQL01'));
    }

    /**
     * gethostbyname() hands the hostname back when it cannot resolve it, which is the real
     * off-network signal and the one case worth skipping the handshake for.
     */
    public function test_a_hostname_echoed_back_is_an_unresolved_host(): void
    {
        Process::fake(['*' => Process::result('RCP-AUS-SQL01')]);

        $this->assertSame(
            RcpaSqlFailureClassifier::UNRESOLVED_HOST,
            RcpaSqlFailureClassifier::unresolvedHostReason('RCP-AUS-SQL01'),
        );
    }
}
