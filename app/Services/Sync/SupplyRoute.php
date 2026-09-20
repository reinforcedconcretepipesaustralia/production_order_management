<?php

namespace App\Services\Sync;

/**
 * Where an order placed on a holding yard actually gets made, and what the product is called there.
 */
final class SupplyRoute
{
    public function __construct(
        public readonly string $entityCode,
        public readonly string $stockCode,
        public readonly bool $wasRouted,
    ) {}

    public function isExcludedEntity(): bool
    {
        return in_array($this->entityCode, TenciaSource::EXCLUDED_ENTITIES, true);
    }
}
