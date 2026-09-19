<?php

namespace App\Support\Excel;

use RuntimeException;

/**
 * Works out which columns of a 'Prod Plan' sheet hold what, by reading its header rows.
 *
 * Columns are detected rather than hardcoded because the three state workbooks have drifted:
 * the steel block sits at AO:AW in VIC and NSW but AP:AX in QLD, so any fixed mapping silently
 * reconciles the wrong column for one state. Header text has stayed consistent even where
 * positions have not.
 */
final class ProdPlanLayout
{
    /** Header labels in row 2, mapped to the field they identify. */
    private const HEADERS = [
        'manufacture date' => 'date',
        'pipe' => 'sku',
        'pipe machine' => 'machine',
        'pipes based on oee settings' => 'capacity',
        '% of shift' => 'shift_fraction',
        'planned pipes (ea)' => 'planned_pipes',
        'good pipes' => 'good_pipes',
        'run rate at 100%' => 'run_rate',
    ];

    /**
     * @param  array<string, string>  $columns  Field name to column letter.
     * @param  array<string, string>  $steelColumns  Wire gauge (as written) to column letter.
     */
    private function __construct(
        public readonly array $columns,
        public readonly array $steelColumns,
        public readonly int $firstDataRow,
    ) {}

    /**
     * @param  array<int, array<string, string>>  $headerRows  Row number to cells.
     */
    /**
     * Returns null rather than throwing when the required headers are not present yet, so a
     * caller can feed rows in until the header block has been seen.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    public static function tryDetect(array $rows): ?self
    {
        try {
            return self::detect($rows);
        } catch (RuntimeException) {
            return null;
        }
    }

    public static function detect(array $headerRows): self
    {
        $columns = [];
        $steelColumns = [];
        $labelRow = 0;

        foreach ($headerRows as $rowNumber => $cells) {
            foreach ($cells as $column => $value) {
                $normalised = strtolower(trim(preg_replace('/\s+/', ' ', $value)));

                if (isset(self::HEADERS[$normalised])) {
                    $columns[self::HEADERS[$normalised]] ??= $column;
                    $labelRow = max($labelRow, $rowNumber);

                    continue;
                }

                // 'Planned T's' varies in trailing whitespace and apostrophe between files.
                if (str_starts_with($normalised, 'planned t')) {
                    $columns['planned_tonnes'] ??= $column;
                    $labelRow = max($labelRow, $rowNumber);

                    continue;
                }

                // The steel block is a run of bare wire gauges: 3.97, 4.95, 5.55, 6, 6.23, ...
                if (self::looksLikeWireGauge($normalised)) {
                    $steelColumns[self::normaliseGauge($normalised)] ??= $column;
                }
            }
        }

        foreach (['date', 'sku', 'planned_pipes'] as $required) {
            if (! isset($columns[$required])) {
                throw new RuntimeException(
                    "Could not find the '{$required}' column on the Prod Plan sheet. Its header text may have changed.",
                );
            }
        }

        ksort($steelColumns, SORT_NATURAL);

        // Data begins after the last row that carried a recognised header label, so the caller
        // can feed in more rows than the header block without swallowing a planned line.
        return new self($columns, $steelColumns, max(1, $labelRow) + 1);
    }

    public function column(string $field): ?string
    {
        return $this->columns[$field] ?? null;
    }

    /**
     * Wire gauges are written as plain numbers in the header, so '6' means 6.00 mm and must not
     * be confused with a class or a count. Only the gauges the plants actually stock qualify.
     */
    private static function looksLikeWireGauge(string $value): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        return in_array(self::normaliseGauge($value), [
            '3.97', '4.95', '5.55', '6.00', '6.23', '7.50', '8.50', '9.00',
        ], true);
    }

    private static function normaliseGauge(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
