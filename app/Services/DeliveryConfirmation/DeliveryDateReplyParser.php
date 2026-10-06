<?php

namespace App\Services\DeliveryConfirmation;

use Carbon\CarbonImmutable;

/**
 * Parse explicit per-line reply instructions from the unquoted part of a reply.
 *
 * Expected lines:
 *   LINE {id} CONFIRM
 *   LINE {id} NEW DATE: dd/mm/yyyy
 *
 * Ambiguous text (dates without LINE instructions, conflicting instructions for
 * the same line, or invalid dates) is reported so nothing is written.
 */
class DeliveryDateReplyParser
{
    /**
     * @param  list<int>  $knownLineIds
     * @return array{
     *     status: 'ok'|'ambiguous'|'none',
     *     instructions: list<array{line_unique_id: int, action: 'confirm'|'amend', date: ?CarbonImmutable}>,
     *     message: ?string
     * }
     */
    public function parse(string $body, array $knownLineIds): array
    {
        $unquoted = $this->unquotedReplyText($body);
        $known = array_fill_keys($knownLineIds, true);
        $byLine = [];

        $patterns = [
            'confirm' => '/^\s*LINE\s+(\d+)\s+CONFIRM\b/im',
            'amend' => '/^\s*LINE\s+(\d+)\s+NEW\s+DATE\s*:\s*([^\r\n]+)/im',
        ];

        if (preg_match_all($patterns['confirm'], $unquoted, $confirmMatches, PREG_SET_ORDER) !== false) {
            foreach ($confirmMatches as $match) {
                $lineId = (int) $match[1];
                if (! isset($known[$lineId])) {
                    return [
                        'status' => 'ambiguous',
                        'instructions' => [],
                        'message' => "Reply mentions unknown line {$lineId}.",
                    ];
                }

                if (isset($byLine[$lineId])) {
                    return [
                        'status' => 'ambiguous',
                        'instructions' => [],
                        'message' => "Reply has conflicting instructions for line {$lineId}.",
                    ];
                }

                $byLine[$lineId] = [
                    'line_unique_id' => $lineId,
                    'action' => 'confirm',
                    'date' => null,
                ];
            }
        }

        if (preg_match_all($patterns['amend'], $unquoted, $amendMatches, PREG_SET_ORDER) !== false) {
            foreach ($amendMatches as $match) {
                $lineId = (int) $match[1];
                if (! isset($known[$lineId])) {
                    return [
                        'status' => 'ambiguous',
                        'instructions' => [],
                        'message' => "Reply mentions unknown line {$lineId}.",
                    ];
                }

                if (isset($byLine[$lineId])) {
                    return [
                        'status' => 'ambiguous',
                        'instructions' => [],
                        'message' => "Reply has conflicting instructions for line {$lineId}.",
                    ];
                }

                $date = $this->parseSingleDate(trim($match[2]));
                if ($date === null) {
                    return [
                        'status' => 'ambiguous',
                        'instructions' => [],
                        'message' => "Reply has an invalid date for line {$lineId}.",
                    ];
                }

                $byLine[$lineId] = [
                    'line_unique_id' => $lineId,
                    'action' => 'amend',
                    'date' => $date,
                ];
            }
        }

        if ($byLine === []) {
            return [
                'status' => 'none',
                'instructions' => [],
                'message' => 'No LINE confirm or NEW DATE instructions were found in the reply.',
            ];
        }

        return [
            'status' => 'ok',
            'instructions' => array_values($byLine),
            'message' => null,
        ];
    }

    public function unquotedReplyText(string $body): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $normalized);
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '') {
                $kept[] = $line;

                continue;
            }

            if (str_starts_with($trimmed, '>')) {
                break;
            }

            if (preg_match('/^on .+ wrote:$/i', $trimmed) === 1) {
                break;
            }

            if (preg_match('/^from:\s+/i', $trimmed) === 1) {
                break;
            }

            if (preg_match('/^-{2,}\s*original message\s*-{2,}$/i', $trimmed) === 1) {
                break;
            }

            if (preg_match('/^_{5,}$/', $trimmed) === 1) {
                break;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    private function parseSingleDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $match) === 1) {
            $day = (int) $match[1];
            $month = (int) $match[2];
            $year = (int) $match[3];

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return CarbonImmutable::create($year, $month, $day)->startOfDay();
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $match) === 1) {
            $year = (int) $match[1];
            $month = (int) $match[2];
            $day = (int) $match[3];

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return CarbonImmutable::create($year, $month, $day)->startOfDay();
        }

        if (preg_match('/^(\d{1,2})\s+([A-Za-z]{3,9})\s+(\d{4})$/', $value, $match) === 1) {
            try {
                return CarbonImmutable::parse(sprintf('%d %s %d', (int) $match[1], $match[2], (int) $match[3]))
                    ->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
