<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;

final class SteelPlanningService
{
    /**
     * @param  iterable<SteelDemandLine>  $demandLines
     * @param  list<int|float|string>  $rollingWeeklyConsumptionKg
     */
    public function calculate(
        iterable $demandLines,
        SteelStockPosition $stock,
        array $rollingWeeklyConsumptionKg = [],
    ): SteelPlanResult {
        $requiredGrams = 0;

        foreach ($demandLines as $line) {
            if ($line->depotId !== $stock->depotId || $line->wireCode() !== $stock->wireCode) {
                throw new InvalidArgumentException('Wire demand lines must match the stock depot and persisted SKU wire code group.');
            }

            $requiredGrams += $line->plannedQuantity * $this->kilogramsToGrams($line->sku->wire_kg_per_pipe);
        }

        $gramsPerCoil = $this->tonnesToGrams($stock->averageCoilWeightTonnes);
        $onHandGrams = $stock->onHandCoils * $gramsPerCoil;
        $onOrderGrams = $stock->onOrderCoils * $gramsPerCoil;
        $inboundGrams = $stock->inboundTransferCoils * $gramsPerCoil;
        $outboundGrams = $stock->outboundTransferCoils * $gramsPerCoil;
        $projectedEndingGrams = $onHandGrams + $onOrderGrams + $inboundGrams - $outboundGrams - $requiredGrams;

        if ($stock->safetyThresholdCoils > 0) {
            $safetyStockGrams = $stock->safetyThresholdCoils * $gramsPerCoil;
        } else {
            $consumptionGrams = array_map($this->kilogramsToGrams(...), $rollingWeeklyConsumptionKg);
            $averageWeeklyGrams = $consumptionGrams === []
                ? $requiredGrams
                : intdiv(array_sum($consumptionGrams), count($consumptionGrams));
            $safetyStockGrams = 2 * $averageWeeklyGrams;
        }

        $recommendedOrderGrams = max(0, $safetyStockGrams - $projectedEndingGrams);

        return new SteelPlanResult(
            depotId: $stock->depotId,
            wireCode: $stock->wireCode,
            requiredKg: $this->formatGramsAsKilograms($requiredGrams),
            requiredTonnes: $this->formatGramsAsTonnes($requiredGrams),
            projectedEndingKg: $this->formatGramsAsKilograms($projectedEndingGrams),
            safetyStockKg: $this->formatGramsAsKilograms($safetyStockGrams),
            recommendedOrderKg: $this->formatGramsAsKilograms($recommendedOrderGrams),
            recommendedOrderTonnes: $this->formatGramsAsTonnes($recommendedOrderGrams),
        );
    }

    private function kilogramsToGrams(int|float|string $kilograms): int
    {
        return (int) round((float) $kilograms * 1_000);
    }

    private function tonnesToGrams(int|float|string $tonnes): int
    {
        return (int) round((float) $tonnes * 1_000_000);
    }

    private function formatGramsAsKilograms(int $grams): string
    {
        return number_format($grams / 1_000, 3, '.', '');
    }

    private function formatGramsAsTonnes(int $grams): string
    {
        return number_format($grams / 1_000_000, 6, '.', '');
    }
}
