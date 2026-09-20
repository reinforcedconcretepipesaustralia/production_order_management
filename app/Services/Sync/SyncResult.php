<?php

namespace App\Services\Sync;

/**
 * What a sync did, in terms a planner can act on: how many rows landed, and what was left out and
 * why. A sync that silently drops rows is worse than one that fails, so every skip is counted and
 * the first few rejects are kept verbatim for the command to print.
 */
final class SyncResult
{
    private const REJECTS_KEPT = 10;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $rejects = [];

    private int $rejectedTotal = 0;

    public function count(string $label, int $by = 1): void
    {
        $this->counts[$label] = ($this->counts[$label] ?? 0) + $by;
    }

    public function reject(string $label, string $detail): void
    {
        $this->count($label);
        $this->rejectedTotal++;

        if (count($this->rejects) < self::REJECTS_KEPT) {
            $this->rejects[] = $detail;
        }
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * @return list<string>
     */
    public function rejects(): array
    {
        return $this->rejects;
    }

    public function rejectedTotal(): int
    {
        return $this->rejectedTotal;
    }

    public function total(string $label): int
    {
        return $this->counts[$label] ?? 0;
    }

    public function merge(self $other): void
    {
        foreach ($other->counts as $label => $count) {
            $this->count($label, $count);
        }

        $this->rejectedTotal += $other->rejectedTotal;

        foreach ($other->rejects as $reject) {
            if (count($this->rejects) < self::REJECTS_KEPT) {
                $this->rejects[] = $reject;
            }
        }
    }
}
