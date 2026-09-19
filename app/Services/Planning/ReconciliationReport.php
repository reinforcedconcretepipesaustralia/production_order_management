<?php

namespace App\Services\Planning;

final readonly class ReconciliationReport
{
    /**
     * @param  list<array{row: int, date: string, scope: string, sku: string, workbook: int, app: int, difference: int, explanation: string}>  $variances
     * @param  array<string, int>  $skipped  Reason to row count.
     */
    public function __construct(
        public string $layoutSummary,
        public int $rowsRead,
        public int $rowsCompared,
        public int $matched,
        public array $variances,
        public array $skipped,
    ) {}

    public function isReconciled(): bool
    {
        return $this->variances === [] && $this->rowsCompared > 0;
    }
}
