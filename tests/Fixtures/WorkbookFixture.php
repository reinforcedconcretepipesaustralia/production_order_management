<?php

namespace Tests\Fixtures;

use ZipArchive;

/**
 * Writes a minimal but real .xlsx so the reader and reconciler can be tested without depending
 * on a 7 MB workbook sitting outside the repository.
 *
 * Text is written through sharedStrings rather than inline, because that is what Excel produces
 * and therefore the path that has to work.
 */
final class WorkbookFixture
{
    /** @var array<string, array<int, array<string, string|int|float>>> */
    private array $sheets = [];

    /** @var list<string> */
    private array $strings = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<int, array<string, string|int|float>>  $rows  Row number to column => value.
     */
    public function withSheet(string $name, array $rows): self
    {
        $this->sheets[$name] = $rows;

        return $this;
    }

    public function write(string $path): string
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetXml = [];
        $index = 1;

        foreach ($this->sheets as $rows) {
            $sheetXml[$index] = $this->sheetXml($rows);
            $index++;
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($this->sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($this->sheets)));
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());

        foreach ($sheetXml as $number => $xml) {
            $zip->addFromString("xl/worksheets/sheet{$number}.xml", $xml);
        }

        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, array<string, string|int|float>>  $rows
     */
    private function sheetXml(array $rows): string
    {
        $body = '';

        foreach ($rows as $rowNumber => $cells) {
            $body .= '<row r="'.$rowNumber.'">';

            foreach ($cells as $column => $value) {
                $reference = $column.$rowNumber;

                if (is_int($value) || is_float($value)) {
                    $body .= '<c r="'.$reference.'"><v>'.$value.'</v></c>';

                    continue;
                }

                $body .= '<c r="'.$reference.'" t="s"><v>'.$this->stringIndex((string) $value).'</v></c>';
            }

            $body .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            ."<sheetData>{$body}</sheetData></worksheet>";
    }

    private function stringIndex(string $value): int
    {
        $existing = array_search($value, $this->strings, true);

        if ($existing !== false) {
            return $existing;
        }

        $this->strings[] = $value;

        return count($this->strings) - 1;
    }

    private function sharedStringsXml(): string
    {
        $items = '';

        foreach ($this->strings as $string) {
            $items .= '<si><t>'.htmlspecialchars($string, ENT_XML1).'</t></si>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            .count($this->strings).'" uniqueCount="'.count($this->strings).'">'
            .$items.'</sst>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        $index = 1;

        foreach (array_keys($this->sheets) as $name) {
            $sheets .= '<sheet name="'.htmlspecialchars($name, ENT_QUOTES | ENT_XML1)
                .'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $index++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            ."<sheets>{$sheets}</sheets></workbook>";
    }

    private function workbookRels(int $sheetCount): string
    {
        $relationships = '';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationships .= '<Relationship Id="rId'.$index
                .'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                .' Target="worksheets/sheet'.$index.'.xml"/>';
        }

        $relationships .= '<Relationship Id="rId'.($sheetCount + 1)
            .'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"'
            .' Target="sharedStrings.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships.'</Relationships>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            .' Target="xl/workbook.xml"/></Relationships>';
    }

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '<Override PartName="/xl/workbook.xml"'
            .' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml"'
            .' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml"'
                .' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .$overrides.'</Types>';
    }
}
