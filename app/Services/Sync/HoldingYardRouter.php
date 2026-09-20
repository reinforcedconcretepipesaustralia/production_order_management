<?php

namespace App\Services\Sync;

use App\Models\SkuMaster;

/**
 * Which plant actually makes what a holding yard sold.
 *
 * Some entities hold stock but manufacture nothing — that South Australia has no bill of material and
 * no production rows in Tencia confirms it. Their orders are real work for the plants that supply
 * them, so the demand is routed rather than dropped.
 *
 * South Australia's own catalogue answers the question directly: its stock codes carry the supplying
 * state as a first letter, and stripping that letter gives the code that state uses. VR0450-4 is a
 * Victorian 450 and NR0450-4 a New South Wales one, so the 450 that both states make needs no rule
 * of thumb — the order line already says. A diameter rule remains as a fallback for a code whose
 * prefix is not recognised.
 *
 * Shared by the SQL sync and the workbook stopgap importer so both route identically.
 */
final class HoldingYardRouter
{
    /**
     * First letter of a holding yard's stock code to the entity that makes it.
     *
     * W is Western Australia, which is closing: those lines are routed to it so they can be counted
     * and reported rather than passing as ordinary demand.
     *
     * @var array<string, array<string, string>>
     */
    private const CODE_PREFIXES = [
        'RCPSA' => [
            'V' => 'RCPVIC',
            'N' => 'RCPNSW',
            'W' => 'RCPWA',
        ],
    ];

    /**
     * Fallback when a holding yard's code prefix is not one we map.
     *
     * @var array<string, list<array{up_to_mm: int|null, entity: string}>>
     */
    public const RULES = [
        'RCPSA' => [
            ['up_to_mm' => 450, 'entity' => 'RCPNSW'],
            ['up_to_mm' => null, 'entity' => 'RCPVIC'],
        ],
    ];

    public function isHoldingYard(string $entityCode): bool
    {
        return array_key_exists($entityCode, self::RULES);
    }

    /**
     * The plant entity and stock code this order line should be planned against.
     */
    public function route(string $entityCode, SkuMaster|string $sku): SupplyRoute
    {
        $code = $sku instanceof SkuMaster ? $sku->sku_code : $sku;

        if (! $this->isHoldingYard($entityCode)) {
            return new SupplyRoute($entityCode, $code, false);
        }

        $prefixes = self::CODE_PREFIXES[$entityCode] ?? [];
        $first = strtoupper(substr($code, 0, 1));

        if (isset($prefixes[$first])) {
            return new SupplyRoute($prefixes[$first], substr($code, 1), true);
        }

        return new SupplyRoute($this->supplyingEntity($entityCode, $sku), $code, true);
    }

    /**
     * The supplying entity by diameter alone, which is all the workbook extract can offer: it carries
     * no per-line source, unlike Tencia's own prefixed codes.
     */
    public function supplyingEntity(string $entityCode, SkuMaster|string $sku): string
    {
        $rules = self::RULES[$entityCode] ?? null;

        if ($rules === null) {
            return $entityCode;
        }

        $diameter = $sku instanceof SkuMaster
            ? $sku->diameter_mm ?? self::diameterFromCode($sku->sku_code)
            : self::diameterFromCode($sku);

        foreach ($rules as $rule) {
            if ($rule['up_to_mm'] === null || ($diameter !== null && $diameter <= $rule['up_to_mm'])) {
                return $rule['entity'];
            }
        }

        return $entityCode;
    }

    /**
     * Pipe codes carry the diameter in millimetres, e.g. R0375-4 is a 375.
     */
    public static function diameterFromCode(string $code): ?int
    {
        return preg_match('/^[A-Z]+(\d{3,4})/', strtoupper($code), $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
