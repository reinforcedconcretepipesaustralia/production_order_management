<?php

namespace App\Services\Scheduling;

final readonly class ChangeoverAssessment
{
    /**
     * @param  list<string>  $requiredBlocks
     */
    public function __construct(
        public ?int $precedingScheduleId,
        public array $requiredBlocks,
    ) {}

    public function isRequired(): bool
    {
        return $this->requiredBlocks !== [];
    }
}
