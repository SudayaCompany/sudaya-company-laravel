<?php

namespace Tests\Unit;

use App\Support\Spreadsheet\XlsxWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class XlsxWriterTest extends TestCase
{
    /** @return array<string, string> */
    private function parts(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'file harus berupa zip yang sah');
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $parts[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $parts;
    }

    public function test_struktur_xlsx_lengkap(): void
    {
        $parts = $this->parts(XlsxWriter::build('Menu', ['Nama', 'Harga'], [['Es Kopi', 22000]]));

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $name) {
            $this->assertArrayHasKey($name, $parts);
            $this->assertNotFalse(simplexml_load_string($parts[$name]), "{$name} harus XML yang sah");
        }
        $this->assertStringContainsString('name="Menu"', $parts['xl/workbook.xml']);
    }

    public function test_angka_disimpan_sebagai_angka_dan_teks_di_escape(): void
    {
        $sheet = $this->parts(XlsxWriter::build('Menu', ['Nama', 'Harga'], [['Kopi <Susu> & "Gula"', 22000], [null, 0]]))['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('<c r="B2"><v>22000</v></c>', $sheet);
        $this->assertStringContainsString('<c r="B3"><v>0</v></c>', $sheet);
        $this->assertStringContainsString('Kopi &lt;Susu&gt; &amp; &quot;Gula&quot;', $sheet);
        $this->assertStringNotContainsString('r="A3"', $sheet, 'sel kosong tidak ditulis');
        $this->assertStringContainsString('<c r="A1" s="1" t="inlineStr">', $sheet, 'header bergaya tebal');
    }

    public function test_teks_mirip_rumus_tidak_menjadi_rumus(): void
    {
        $sheet = $this->parts(XlsxWriter::build('Menu', ['Nama'], [['=HYPERLINK("http://x")'], ['+62812'], ['@cmd']]))['xl/worksheets/sheet1.xml'];

        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertSame(3, substr_count($sheet, 't="inlineStr"><is><t xml:space="preserve">=')
            + substr_count($sheet, 't="inlineStr"><is><t xml:space="preserve">+')
            + substr_count($sheet, 't="inlineStr"><is><t xml:space="preserve">@'));
    }

    public function test_karakter_kontrol_dibuang_dan_nama_sheet_aman(): void
    {
        $parts = $this->parts(XlsxWriter::build('Menu: [Sudaya]/Coffee?', ['Nama'], [["Kopi\x01\x07 Susu\tdingin"]]));

        $this->assertStringContainsString("Kopi Susu\tdingin", $parts['xl/worksheets/sheet1.xml']);
        $this->assertNotFalse(simplexml_load_string($parts['xl/worksheets/sheet1.xml']));
        $this->assertStringNotContainsString('[', $parts['xl/workbook.xml']);
        $this->assertStringNotContainsString('?', explode('<sheets>', $parts['xl/workbook.xml'])[1]);
    }

    public function test_beberapa_sheet_dalam_satu_file(): void
    {
        $parts = $this->parts(XlsxWriter::workbook([
            ['name' => 'Ringkasan', 'header' => ['Metrik', 'Nilai'], 'rows' => [['Omzet', 50000]]],
            ['name' => 'Harian', 'header' => ['Tanggal', 'Omzet'], 'rows' => [['2026-11-09', 50000]], 'widths' => [14, 16]],
        ]));

        $this->assertArrayHasKey('xl/worksheets/sheet1.xml', $parts);
        $this->assertArrayHasKey('xl/worksheets/sheet2.xml', $parts);
        $this->assertStringContainsString('<sheet name="Ringkasan" sheetId="1" r:id="rId1"/><sheet name="Harian" sheetId="2" r:id="rId2"/>', $parts['xl/workbook.xml']);
        $this->assertStringContainsString('Id="rId3"', $parts['xl/_rels/workbook.xml.rels'], 'styles setelah semua sheet');
        $this->assertStringContainsString('/xl/worksheets/sheet2.xml', $parts['[Content_Types].xml']);
        $this->assertStringContainsString('2026-11-09', $parts['xl/worksheets/sheet2.xml']);
        foreach ($parts as $name => $xml) {
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $this->assertNotFalse(simplexml_load_string($xml), "{$name} XML sah");
            }
        }
    }

    public function test_kolom_lebih_dari_26_memakai_huruf_ganda(): void
    {
        $header = array_map(fn ($i) => "K{$i}", range(1, 28));
        $sheet = $this->parts(XlsxWriter::build('Menu', $header, []))['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('r="Z1"', $sheet);
        $this->assertStringContainsString('r="AA1"', $sheet);
        $this->assertStringContainsString('r="AB1"', $sheet);
    }
}
