<?php

namespace App\Services\Scheduling;

final readonly class MouldConflict
{
    public function __construct(
        public int $conflictingScheduleId,
        public string $reason,
    ) {}
}
