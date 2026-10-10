<?php

namespace Tests\Unit;

use App\Lib\Pii\RedactionPlanner;
use App\Lib\Pii\SpreadsheetRedactor;
use Tests\TestCase;

class SpreadsheetRedactorTest extends TestCase
{
    private const MAIN = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';

    private array $replacements = [
        ['surface' => 'Mari Maasikas', 'replacement' => 'M. M.', 'kind' => 'name'],
        ['surface' => '49403136515', 'replacement' => '[isikukood eemaldatud]', 'kind' => 'personal_code'],
    ];

    private function zip(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        return $path;
    }

    private function part(string $path, string $name): string
    {
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName($name);
        $zip->close();
        return $xml;
    }

    public function test_xlsx_cells_comments_headers_and_properties_are_rewritten(): void
    {
        $path = $this->zip([
            'xl/sharedStrings.xml' => '<sst ' . self::MAIN . '><si><t>Nimi</t></si>'
                . '<si><r><rPr><b/></rPr><t>Mari</t></r><r><t xml:space="preserve"> Maasikase taotlus</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet ' . self::MAIN . '><sheetData><row r="1">'
                . '<c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c>'
                . '<c r="C1"><v>49403136515</v></c>'
                . '<c r="D1"><v>49403136515.0</v></c>'
                . '<c r="E1" t="str"><f>A1&amp;" Mari Maasikas"</f><v>Nimi Mari Maasikas</v></c>'
                . '<c r="F1"><v>12.5</v></c>'
                . '<c r="G1" t="inlineStr"><is><t>Mari Maasikas</t></is></c>'
                . '</row></sheetData><headerFooter><oddHeader>&amp;CKoostaja Mari Maasikas</oddHeader></headerFooter></worksheet>',
            'xl/comments1.xml' => '<comments ' . self::MAIN . '><commentList><comment ref="A1"><text><r><t>Küsi Mari</t></r><r><t xml:space="preserve"> Maasikas käest</t></r></text></comment></commentList></comments>',
            'docProps/core.xml' => '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>Mari Maasikas</dc:creator></cp:coreProperties>',
        ]);

        $changed = (new SpreadsheetRedactor())->redact($path, 'xlsx', $this->replacements);

        $this->assertSame(8, $changed); // 1 shared string, 4 cells, 1 print header, 1 comment, 1 property
        $strings = $this->part($path, 'xl/sharedStrings.xml');
        $this->assertStringContainsString('M. M. taotlus', $strings, 'rich runs joined, inflected name replaced');
        $this->assertStringContainsString('<t>Nimi</t>', $strings, 'untouched strings keep their markup');
        $sheet = $this->part($path, 'xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('49403136515', $sheet);
        $this->assertSame(2, substr_count($sheet, '[isikukood eemaldatud]'), 'numeric codes became text cells');
        $this->assertStringNotContainsString('<f>', $sheet, 'formula replaced by its redacted value');
        $this->assertStringContainsString('<v>12.5</v>', $sheet, 'other numbers untouched');
        $this->assertStringNotContainsString('Maasikas', $sheet);
        $this->assertStringContainsString('Koostaja M. M.', $sheet);
        $this->assertStringContainsString('Küsi M. M. käest', $this->part($path, 'xl/comments1.xml'));
        $this->assertStringNotContainsString('Maasikas', $this->part($path, 'docProps/core.xml'));
        @unlink($path);
    }

    public function test_ods_paragraphs_and_stored_values_are_rewritten(): void
    {
        $path = $this->zip([
            'content.xml' => '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet><table:table><table:table-row>'
                . '<table:table-cell office:value-type="string"><text:p><text:span>Mari</text:span> Maasikas</text:p></table:table-cell>'
                . '<table:table-cell office:value-type="float" office:value="49403136515"><text:p>49403136515</text:p></table:table-cell>'
                . '</table:table-row></table:table></office:spreadsheet></office:body></office:document-content>',
        ]);

        (new SpreadsheetRedactor())->redact($path, 'ods', $this->replacements);

        $content = $this->part($path, 'content.xml');
        $this->assertStringNotContainsString('Maasikas', $content);
        $this->assertStringNotContainsString('49403136515', $content);
        $this->assertStringContainsString('<text:p>M. M.</text:p>', $content);
        @unlink($path);
    }

    public function test_untouched_spreadsheet_is_left_alone(): void
    {
        $xml = '<sst ' . self::MAIN . '><si><t>Tammsaare</t></si></sst>';
        $path = $this->zip(['xl/sharedStrings.xml' => $xml]);

        $this->assertSame(0, (new SpreadsheetRedactor())->redact($path, 'xlsx', $this->replacements));
        $this->assertSame($xml, $this->part($path, 'xl/sharedStrings.xml'));
        @unlink($path);
    }
}
