<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(sheet_reader::class)]
/**
 * Tests for reading .xlsx and CSV files.
 *
 * The test files are written to a folder named local_studentrecords_tests in the system temp folder and are
 * left there (each run uses new file names; nothing is overwritten or removed).
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\sheet_reader
 */
final class sheet_reader_test extends \basic_testcase {
    /**
     * A new, unused file path in the tests' temp folder.
     *
     * @param string $suffix
     * @return string
     */
    protected static function new_path(string $suffix): string {
        $dir = sys_get_temp_dir() . '/local_studentrecords_tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        do {
            $path = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . $suffix;
        } while (file_exists($path));
        return $path;
    }

    /**
     * Build a minimal two-sheet workbook with shared strings, rich text, a formula, an inline string and a gap column.
     *
     * @return string path
     */
    protected static function make_xlsx(): string {
        $path = self::new_path('.xlsx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL);
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $rns = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $zip->addFromString('xl/workbook.xml', "<?xml version=\"1.0\"?><workbook $ns $rns><sheets>" .
            '<sheet name="Courses" sheetId="1" r:id="rId1"/><sheet name="Empty" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships ' .
            'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="x"/>' .
            '<Relationship Id="rId2" Target="/xl/worksheets/sheet2.xml" Type="x"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', "<?xml version=\"1.0\"?><sst $ns><si><t>Course code</t></si>" .
            '<si><r><t>Ti</t></r><r><t>tle</t></r></si><si><t>ART101</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', "<?xml version=\"1.0\"?><worksheet $ns><sheetData>" .
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c>' .
            '<c r="D1" t="inlineStr"><is><t>Hours</t></is></c></row>' .
            '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="str"><f>CONCAT("Dra","wing")</f><v>Drawing</v></c>' .
            '<c r="D2"><v>3</v></c></row><row r="3"/></sheetData></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', "<?xml version=\"1.0\"?><worksheet $ns><sheetData/></worksheet>");
        $zip->close();
        return $path;
    }

    /**
     * Sheets, rich text, formulas (saved value), gap columns and empty rows.
     */
    public function test_read_xlsx(): void {
        $sheets = sheet_reader::read_xlsx(self::make_xlsx());
        $this->assertSame(['Courses', 'Empty'], array_keys($sheets));
        $this->assertSame([['Course code', 'Title', '', 'Hours'], ['ART101', 'Drawing', '', '3']], $sheets['Courses']);
        $this->assertSame([], $sheets['Empty']);
    }

    /**
     * A file that is not a spreadsheet is refused.
     */
    public function test_refuses_bad_files(): void {
        $path = self::new_path('.txt');
        file_put_contents($path, 'not a zip');
        try {
            sheet_reader::read_xlsx($path);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_notspreadsheet', $e->errorcode);
        }
    }

    /**
     * CSV: delimiter detection, quotes, byte order mark; column letters.
     */
    public function test_read_csv(): void {
        $this->assertSame(
            [['Code', 'Title'], ['ART101', 'Drawing, Basic']],
            sheet_reader::read_csv("\xEF\xBB\xBFCode,Title\nART101,\"Drawing, Basic\"\n")
        );
        $this->assertSame([['Code', 'Title'], ['ART101', 'Drawing']], sheet_reader::read_csv("Code;Title\r\nART101;Drawing"));
        $this->assertSame(2, sheet_reader::column_index('C9'));
        $this->assertSame(27, sheet_reader::column_index('AB1'));
    }

    /**
     * Build a workbook with one sheet from the XML given.
     *
     * @param string $sheetdata rows inside sheetData
     * @param string|null $shared the whole shared strings part
     * @return string path
     */
    protected static function make_sheet(string $sheetdata, ?string $shared = null): string {
        $path = self::new_path('.xlsx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL);
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $rns = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $zip->addFromString('xl/workbook.xml', "<?xml version=\"1.0\"?><workbook $ns $rns><sheets>" .
            '<sheet name="One" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships ' .
            'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="x"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', "<?xml version=\"1.0\"?><worksheet $ns><sheetData>$sheetdata" .
            '</sheetData></worksheet>');
        if ($shared !== null) {
            $zip->addFromString('xl/sharedStrings.xml', $shared);
        }
        $zip->close();
        return $path;
    }

    /**
     * Assert that reading a workbook is refused with an error code.
     *
     * @param string $path
     * @param string $errorcode
     */
    protected function assert_refused(string $path, string $errorcode): void {
        try {
            sheet_reader::read_xlsx($path);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * A document type is refused, also in an encoding that the plain text search cannot read, and no entity is read.
     *
     * @dataProvider doctype_provider
     * @param string $encoding the encoding of the shared strings part
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('doctype_provider')]
    public function test_refuses_a_document_type_in_any_encoding(string $encoding): void {
        $target = tempnam(sys_get_temp_dir(), 'srtest');
        file_put_contents($target, 'secret file content');
        $xml = "<?xml version=\"1.0\" encoding=\"$encoding\"?><!DOCTYPE sst [<!ENTITY x SYSTEM \"file://$target\">]>" .
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&x;</t></si></sst>';
        $bom = $encoding === 'UTF-16' ? "\xFF\xFE" : '';
        $coded = $bom . ($encoding === 'UTF-16' ? mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8') : $xml);
        $this->assert_refused(self::make_sheet('<row r="1"><c r="A1" t="s"><v>0</v></c></row>', $coded), 'err_notspreadsheet');
        unlink($target);
    }

    /**
     * Data for test_refuses_a_document_type_in_any_encoding.
     *
     * @return array
     */
    public static function doctype_provider(): array {
        return ['plain' => ['UTF-8'], 'two bytes per letter' => ['UTF-16']];
    }

    /**
     * Entities that expand a billion times are refused without being expanded, also when the text search cannot see them.
     */
    public function test_refuses_entity_expansion(): void {
        $entities = '<!ENTITY a "aaaaaaaaaa">';
        foreach (range('b', 'k') as $i => $letter) {
            $previous = chr(ord($letter) - 1);
            $entities .= "<!ENTITY $letter \"" . str_repeat("&$previous;", 10) . '">';
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-16\"?><!DOCTYPE sst [$entities]>" .
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&k;</t></si></sst>';
        $coded = "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
        $this->assert_refused(self::make_sheet('<row r="1"><c r="A1" t="s"><v>0</v></c></row>', $coded), 'err_notspreadsheet');
    }

    /**
     * A small zip that unpacks to more than the limit is refused before it is unpacked.
     */
    public function test_refuses_a_zip_bomb(): void {
        $big = str_repeat(' ', sheet_reader::MAXPARTBYTES + 1);
        $path = self::make_sheet('<row r="1"><c r="A1"><v>1</v></c></row>', $big);
        $this->assertLessThan(sheet_reader::MAXFILEBYTES, filesize($path), 'the zip itself is under the upload limit');
        $this->assert_refused($path, 'err_filetoolarge');
    }

    /**
     * A sheet listed over and over counts each time, so a workbook cannot unpack the same part without end.
     */
    public function test_counts_a_part_each_time_it_is_read(): void {
        $path = self::new_path('.xlsx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL);
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $rns = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $sheets = '';
        foreach (range(1, 8) as $n) {
            $sheets .= "<sheet name=\"S$n\" sheetId=\"$n\" r:id=\"rId1\"/>";
        }
        $zip->addFromString('xl/workbook.xml', "<?xml version=\"1.0\"?><workbook $ns $rns><sheets>$sheets</sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships ' .
            'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="x"/></Relationships>');
        $padding = str_repeat(' ', (int)(sheet_reader::MAXTOTALBYTES / 7));
        $zip->addFromString('xl/worksheets/sheet1.xml', "<?xml version=\"1.0\"?><worksheet $ns><sheetData/>$padding</worksheet>");
        $zip->close();
        $this->assert_refused($path, 'err_filetoolarge');
    }

    /**
     * A file over the upload limit is refused, whatever the upload form was told.
     */
    public function test_refuses_a_file_over_the_limit(): void {
        $path = self::new_path('.xlsx');
        file_put_contents($path, str_repeat('x', sheet_reader::MAXFILEBYTES + 1));
        $this->assert_refused($path, 'err_filetoolarge');

        $this->expectException(\moodle_exception::class);
        sheet_reader::read_csv(str_repeat('x', sheet_reader::MAXFILEBYTES + 1));
    }

    /**
     * An empty file is not a spreadsheet, and says so without a PHP warning.
     */
    public function test_refuses_an_empty_file(): void {
        $path = self::new_path('.xlsx');
        file_put_contents($path, '');
        $this->assert_refused($path, 'err_notspreadsheet');
    }

    /**
     * Broken XML is refused and leaves no PHP warning behind.
     */
    public function test_refuses_broken_xml(): void {
        $this->assert_refused(self::make_sheet('<row r="1"><c r="A1">'), 'err_notspreadsheet');
        $this->assertSame([], libxml_get_errors());
    }

    /**
     * A value far to the right is refused: a row is as wide as its last cell, so it would take all the memory.
     */
    public function test_refuses_a_value_past_the_last_column(): void {
        $this->assert_refused(self::make_sheet('<row r="1"><c r="ZZZZZZ1"><v>1</v></c></row>'), 'err_filetoolarge');
    }

    /**
     * Empty cells with only a style are left out, so a styled row is not as wide as the sheet.
     */
    public function test_ignores_empty_styled_cells(): void {
        $sheets = sheet_reader::read_xlsx(self::make_sheet('<row r="1"><c r="A1"><v>1</v></c><c r="XFD1" s="3"/></row>' .
            '<row r="2"><c r="XFD2" s="3"/></row>'));
        $this->assertSame([['1']], $sheets['One']);
    }

    /**
     * Cells without a reference follow each other, as the file format allows.
     */
    public function test_reads_cells_without_a_reference(): void {
        $sheets = sheet_reader::read_xlsx(self::make_sheet('<row><c><v>1</v></c><c><v>2</v></c><c r="E1"><v>5</v></c>' .
            '<c><v>6</v></c></row>'));
        $this->assertSame([['1', '2', '', '', '5', '6']], $sheets['One']);
    }

    /**
     * More rows than the limit are refused with a message, not cut off without a word.
     */
    public function test_refuses_too_many_rows(): void {
        $rows = str_repeat('<row><c><v>1</v></c></row>', sheet_reader::MAXROWS + 1);
        $this->assert_refused(self::make_sheet($rows), 'err_filetoolarge');

        $this->expectException(\moodle_exception::class);
        sheet_reader::read_csv(str_repeat("a,b\n", sheet_reader::MAXROWS + 1));
    }

    /**
     * CSV in the Windows encoding or UTF-16 comes out as UTF-8, because anything else cannot be stored.
     */
    public function test_read_csv_encodings(): void {
        $this->assertSame([['Name'], ["Caf\u{e9}"]], sheet_reader::read_csv("Name\nCaf\xE9\n"));
        $utf16 = "\xFF\xFE" . mb_convert_encoding("Name\nCaf\u{e9}\n", 'UTF-16LE', 'UTF-8');
        $this->assertSame([['Name'], ["Caf\u{e9}"]], sheet_reader::read_csv($utf16));
        $this->assertSame([['Name'], ['AB']], sheet_reader::read_csv("Name\nA\0B\n"));
    }
}
