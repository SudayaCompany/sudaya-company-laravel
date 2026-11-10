<?php

namespace App\Support\Spreadsheet;

use RuntimeException;
use ZipArchive;

/**
 * Penulis XLSX minimal (satu sheet) tanpa dependency: cukup untuk ekspor data yang dibuka di
 * Excel atau Google Sheets. Teks disimpan sebagai inline string, sehingga isi yang diawali
 * "=", "+", "-", atau "@" tidak pernah dijalankan sebagai rumus.
 */
final class XlsxWriter
{
    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|null>>  $rows
     * @param  list<int>  $widths  lebar kolom (karakter)
     */
    public static function build(string $sheetName, array $header, array $rows, array $widths = []): string
    {
        return self::workbook([['name' => $sheetName, 'header' => $header, 'rows' => $rows, 'widths' => $widths]]);
    }

    /**
     * Beberapa sheet dalam satu file (mis. laporan: Ringkasan, Harian, Menu, …).
     *
     * @param  list<array{name: string, header: list<string>, rows: list<list<string|int|null>>, widths?: list<int>}>  $sheets
     */
    public static function workbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new RuntimeException('Tidak bisa membuat file sementara.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa membuat file XLSX.');
        }
        $sheets = array_values($sheets);
        $zip->addFromString('[Content_Types].xml', self::contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbookXml(array_column($sheets, 'name')));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels(count($sheets)));
        $zip->addFromString('xl/styles.xml', self::styles());
        foreach ($sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', self::sheet($sheet['header'], $sheet['rows'], $sheet['widths'] ?? []));
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @param list<string|int|null> $values */
    private static function row(int $index, array $values, bool $header): string
    {
        $cells = '';
        foreach (array_values($values) as $col => $value) {
            $ref = self::column($col).$index;
            $style = $header ? ' s="1"' : '';
            if (is_int($value)) {
                $cells .= "<c r=\"{$ref}\"{$style}><v>{$value}</v></c>";
            } elseif ($value !== null && $value !== '') {
                $cells .= "<c r=\"{$ref}\"{$style} t=\"inlineStr\"><is><t xml:space=\"preserve\">".self::escape($value).'</t></is></c>';
            }
        }

        return "<row r=\"{$index}\">{$cells}</row>";
    }

    private static function sheet(array $header, array $rows, array $widths): string
    {
        $cols = '';
        foreach ($widths as $i => $width) {
            $n = $i + 1;
            $cols .= "<col min=\"{$n}\" max=\"{$n}\" width=\"{$width}\" customWidth=\"1\"/>";
        }
        $data = self::row(1, $header, true);
        foreach (array_values($rows) as $i => $row) {
            $data .= self::row($i + 2, $row, false);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($cols === '' ? '' : "<cols>{$cols}</cols>")
            ."<sheetData>{$data}</sheetData></worksheet>";
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        // Karakter kontrol selain tab/baris baru tidak sah di XML.
        $clean = (string) preg_replace('/[^\P{C}\t\n\r]/u', '', $value);

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function contentTypes(int $sheets): string
    {
        $parts = '';
        for ($i = 1; $i <= $sheets; $i++) {
            $parts .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$parts
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /** @param list<string> $names */
    private static function workbookXml(array $names): string
    {
        $sheets = '';
        foreach (array_values($names) as $i => $sheetName) {
            // Nama sheet maks 31 karakter dan tanpa karakter terlarang Excel.
            $name = self::escape(mb_substr((string) preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName), 0, 31));
            $n = $i + 1;
            $sheets .= "<sheet name=\"{$name}\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            ."<sheets>{$sheets}</sheets></workbook>";
    }

    private static function workbookRels(int $sheets): string
    {
        $rels = '';
        for ($i = 1; $i <= $sheets; $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'<Relationship Id="rId'.($sheets + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function styles(): string
    {
        // Style 0 = biasa, style 1 = header tebal.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>';
    }
}
