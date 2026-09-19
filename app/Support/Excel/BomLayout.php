<?php

namespace App\Support\Excel;

use RuntimeException;

/**
 * Column map for a workbook's 'BOM' tab, detected from its header rows.
 *
 * Product labels sit in row 5 and the wire gauges above them in row 3 — the BOM's steel columns
 * are SUMIFS keyed on the gauge written in row 3, so that row is the authority for which column
 * holds which gauge.
 */
final class BomLayout
{
    private const HEADERS = [
        'tencia code' => 'sku_code',
        'status' => 'status',
        'diameter' => 'diameter',
        'weight' => 'unit_weight',
        'concrete volume (m3)' => 'concrete_volume',
        'adjustment' => 'adjustment',
        'mould type' => 'mould_type',
        'no. moulds' => 'mould_count',
        'cage' => 'cage',
        'machine' => 'machine',
        'class' => 'class',
        'fj vs rj' => 'joint',
    ];

    private const GAUGES = ['3.97', '4.95', '5.55', '6.00', '6.23', '7.50', '8.50', '9.00'];

    /**
     * @param  array<string, string>  $columns
     * @param  array<string, string>  $steelColumns  Gauge to column letter.
     */
    private function __construct(
        public readonly array $columns,
        public readonly array $steelColumns,
        public readonly int $firstDataRow,
    ) {}

    /**
     * @param  array<int, array<string, string>>  $headerRows
     */
    public static function detect(array $headerRows): self
    {
        $columns = [];
        $steelColumns = [];
        $labelRow = null;

        foreach ($headerRows as $rowNumber => $cells) {
            foreach ($cells as $column => $value) {
                $normalised = strtolower(trim(preg_replace('/\s+/', ' ', $value)));

                // The rated-speed columns are named after whichever machine the state runs:
                // 'BiDi rated speed' in VIC and QLD, 'Epak rated speed' in NSW. Matching on the
                // brand would silently import no run rates for a state, so match on the shape.
                if (str_ends_with($normalised, 'rated speed')) {
                    $field = str_starts_with($normalised, 'adjusted ') ? 'adjusted_speed' : 'rated_speed';
                    $columns[$field] ??= $column;
                    $labelRow = max($labelRow ?? 0, $rowNumber);

                    continue;
                }

                if (isset(self::HEADERS[$normalised])) {
                    if (! isset($columns[self::HEADERS[$normalised]])) {
                        $columns[self::HEADERS[$normalised]] = $column;
                        $labelRow = max($labelRow ?? 0, $rowNumber);
                    }

                    continue;
                }

                if (is_numeric($normalised)) {
                    $gauge = number_format((float) $normalised, 2, '.', '');

                    if (in_array($gauge, self::GAUGES, true)) {
                        $steelColumns[$gauge] ??= $column;
                    }
                }
            }
        }

        if (! isset($columns['sku_code'])) {
            throw new RuntimeException(
                "Could not find the 'Tencia Code' column on the BOM sheet. Its header text may have changed.",
            );
        }

        ksort($steelColumns, SORT_NATURAL);

        return new self($columns, $steelColumns, ($labelRow ?? 5) + 1);
    }

    public function column(string $field): ?string
    {
        return $this->columns[$field] ?? null;
    }
}
