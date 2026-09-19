<?php

namespace App\Support\Excel;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * A minimal streaming reader for .xlsx and .xlsm workbooks, enough to pull a few columns out of
 * a named sheet for reconciliation.
 *
 * Deliberately not a spreadsheet library. The planning workbooks are large — the VIC 'Prod Plan'
 * sheet alone is 7.5 MB of XML across 2,200 formula rows — so rows are streamed rather than
 * loaded, and no dependency is added for what amounts to reading a dozen columns.
 *
 * Cell values are the cached results Excel stored on its last save, which is exactly what is
 * wanted: the figures the planners have been working to.
 */
final class XlsxReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private ZipArchive $archive;

    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    public function __construct(private readonly string $path)
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("Workbook not found: {$this->path}");
        }

        $this->archive = new ZipArchive;

        if ($this->archive->open($this->path) !== true) {
            throw new RuntimeException("Could not open workbook: {$this->path}");
        }
    }

    public function __destruct()
    {
        $this->archive->close();
    }

    /**
     * Sheet names in workbook order, including hidden sheets.
     *
     * @return array<string, string> Sheet name to the part path holding it.
     */
    public function sheets(): array
    {
        $workbook = $this->part('xl/workbook.xml');
        $rels = $this->part('xl/_rels/workbook.xml.rels');

        $targets = [];
        preg_match_all('/Id="(rId\d+)"[^>]*Target="([^"]+)"/', $rels, $relMatches, PREG_SET_ORDER);

        foreach ($relMatches as $match) {
            $targets[$match[1]] = 'xl/'.ltrim(str_replace('/xl/', '', $match[2]), '/');
        }

        preg_match_all(
            '/<sheet[^>]*name="([^"]+)"[^>]*r:id="(rId\d+)"/',
            $workbook,
            $sheetMatches,
            PREG_SET_ORDER,
        );

        foreach ($sheetMatches as $match) {
            $target = $targets[$match[2]] ?? null;

            if ($target !== null) {
                $sheets[html_entity_decode($match[1], ENT_QUOTES | ENT_XML1)] = $target;
            }
        }

        return $sheets ?? [];
    }

    /**
     * Streams a sheet's rows.
     *
     * @return Generator<int, array<string, string>> Row number to column letter => value.
     *                                               Empty cells are omitted.
     */
    public function rows(string $sheetName, int $maxRow = PHP_INT_MAX): Generator
    {
        $sheets = $this->sheets();

        if (! isset($sheets[$sheetName])) {
            throw new RuntimeException(sprintf(
                'Sheet [%s] not found. Available: %s',
                $sheetName,
                implode(', ', array_keys($sheets)),
            ));
        }

        $reader = new XMLReader;

        // Reading from the zip stream keeps the sheet out of memory entirely.
        if (! $reader->open('zip://'.$this->path.'#'.$sheets[$sheetName], 'UTF-8')) {
            throw new RuntimeException("Could not read sheet [{$sheetName}].");
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowNumber = (int) $reader->getAttribute('r');

                if ($rowNumber > $maxRow) {
                    break;
                }

                $cells = $this->cellsFrom($reader->readOuterXml());

                if ($cells !== []) {
                    yield $rowNumber => $cells;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array<string, string>
     */
    private function cellsFrom(string $rowXml): array
    {
        $row = new XMLReader;

        if (! $row->XML($rowXml, 'UTF-8')) {
            return [];
        }

        $cells = [];

        try {
            while ($row->read()) {
                if ($row->nodeType !== XMLReader::ELEMENT || $row->localName !== 'c') {
                    continue;
                }

                $reference = (string) $row->getAttribute('r');
                $type = $row->getAttribute('t');
                $column = preg_replace('/\d+/', '', $reference);
                $value = $this->valueFrom($row->readOuterXml(), $type);

                if ($column !== '' && $value !== null && $value !== '') {
                    $cells[$column] = $value;
                }
            }
        } finally {
            $row->close();
        }

        return $cells;
    }

    private function valueFrom(string $cellXml, ?string $type): ?string
    {
        $document = new \DOMDocument;

        if (! @$document->loadXML($cellXml)) {
            return null;
        }

        if ($type === 'inlineStr') {
            return $this->textOf($document->getElementsByTagNameNS(self::NS, 't'));
        }

        $values = $document->getElementsByTagNameNS(self::NS, 'v');

        if ($values->length === 0) {
            return null;
        }

        $raw = $values->item(0)->textContent;

        return $type === 's' ? ($this->sharedStrings()[(int) $raw] ?? null) : $raw;
    }

    private function textOf(\DOMNodeList $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $this->sharedStrings = [];
        $part = $this->archive->getFromName('xl/sharedStrings.xml');

        if ($part === false) {
            return $this->sharedStrings;
        }

        $reader = new XMLReader;

        if (! $reader->XML($part, 'UTF-8')) {
            return $this->sharedStrings;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $document = new \DOMDocument;

                    if (@$document->loadXML($reader->readOuterXml())) {
                        $this->sharedStrings[] = $this->textOf(
                            $document->getElementsByTagNameNS(self::NS, 't'),
                        );
                    } else {
                        $this->sharedStrings[] = '';
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return $this->sharedStrings;
    }

    private function part(string $name): string
    {
        $contents = $this->archive->getFromName($name);

        if ($contents === false) {
            throw new RuntimeException("Workbook is missing [{$name}].");
        }

        return $contents;
    }

    /**
     * Excel serial date to a Y-m-d string. Serial 1 is 1 January 1900, with the well-known
     * 1900 leap-year bug meaning the epoch is effectively 30 December 1899.
     */
    public static function serialToDate(string|int|float $serial): ?string
    {
        $days = (int) floor((float) $serial);

        if ($days < 1) {
            return null;
        }

        return date('Y-m-d', mktime(0, 0, 0, 12, 30 + $days, 1899));
    }
}
