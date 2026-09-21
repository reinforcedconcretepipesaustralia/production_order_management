<?php

namespace App\Services\Scheduling;

use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use InvalidArgumentException;

/**
 * One gauge's worth of wire demand from planned pipes.
 *
 * A pipe consumes several gauges, ordered separately, so demand is per (product, gauge) rather than
 * per product. The single wire_code and wire_kg_per_pipe columns on sku_master only ever held one of
 * them, which understated every pipe with more than one gauge in its cage.
 */
final readonly class SteelDemandLine
{
    public function __construct(
        public int $depotId,
        public SkuMaster $sku,
        public int $plannedQuantity,
        public string $wireCode,
        public int|float|string $kgPerPipe,
    ) {
        if (
            $depotId <= 0
            || trim($wireCode) === ''
            || (float) $kgPerPipe < 0
            || $plannedQuantity < 0
        ) {
            throw new InvalidArgumentException(
                'Wire demand requires a depot, a wire code, a non-negative weight per pipe and a '
                .'non-negative planned quantity.',
            );
        }
    }

    /**
     * Every gauge a planned quantity of this product consumes.
     *
     * @return list<self>
     */
    public static function forSku(int $depotId, SkuMaster $sku, int $plannedQuantity): array
    {
        return $sku->wireRequirements
            ->map(fn (SkuWireRequirement $requirement): self => new self(
                depotId: $depotId,
                sku: $sku,
                plannedQuantity: $plannedQuantity,
                wireCode: $requirement->wire_code,
                kgPerPipe: $requirement->kg_per_pipe,
            ))
            ->values()
            ->all();
    }

    public function wireCode(): string
    {
        return $this->wireCode;
    }

    public function requiredKilograms(): string
    {
        return number_format($this->plannedQuantity * (float) $this->kgPerPipe, 3, '.', '');
    }
}
