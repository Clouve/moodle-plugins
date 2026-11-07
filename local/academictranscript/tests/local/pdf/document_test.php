<?php

namespace local_academictranscript\local\pdf;

#[\PHPUnit\Framework\Attributes\CoversClass(document::class)]
/**
 * Tests for the PDF document and the fonts it registers.
 *
 * The fonts are modified versions of fonts under the SIL Open Font License, and the license reserves the word Source for
 * the original fonts. So these tests check the new names at every layer: the files, the TCPDF definitions, the name table
 * inside each font, and the PDF.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_academictranscript\local\pdf\document
 */
final class document_test extends \basic_testcase {
    /**
     * The font folder holds the seven fonts, the license texts and the readme, and nothing else.
     */
    public function test_font_folder_holds_only_the_renamed_fonts(): void {
        $expected = ['OFL-SourceSans3.txt', 'OFL-SourceSerif4.txt', 'readme_moodle.txt'];
        foreach (self::font_provider() as $font) {
            array_push($expected, $font[0] . '.php', $font[0] . '.z', $font[0] . '.ctg.z');
        }

        $this->assertEqualsCanonicalizing($expected, array_map('basename', glob(self::folder() . '*')));
    }

    /**
     * A definition carries the PostScript name of its font and points at its own files, and the size it gives is the size
     * of the font once it is unpacked.
     *
     * @dataProvider font_provider
     * @param string $font The TCPDF name
     * @param string $postscript The PostScript name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('font_provider')]
    public function test_definition_matches_its_files(string $font, string $postscript): void {
        $this->assertFileExists(self::folder() . $font . '.php');

        $definition = self::definition($font);

        $this->assertSame($postscript, $definition['name']);
        $this->assertSame($font . '.z', $definition['file']);
        $this->assertSame($font . '.ctg.z', $definition['ctg']);
        $this->assertFileExists(self::folder() . $definition['ctg']);
        $this->assertSame(strlen(self::truetype($font)), $definition['originalsize']);
    }

    /**
     * The name table inside each font has the new names.
     *
     * @dataProvider font_provider
     * @param string $font The TCPDF name
     * @param string $postscript The PostScript name
     * @param string $family The family record
     * @param string $full The full name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('font_provider')]
    public function test_name_table_has_the_new_names(string $font, string $postscript, string $family, string $full): void {
        $this->assertFileExists(self::folder() . $font . '.z');

        $names = self::name_records($font);

        $this->assertSame($family, $names[1]);
        $this->assertStringEndsWith(';' . $postscript, $names[3]);
        $this->assertSame($full, $names[4]);
        $this->assertSame($postscript, $names[6]);
    }

    /**
     * The copyright record, the trademark notice and the link to the license are there, and no other record of a font has
     * the reserved word.
     *
     * @dataProvider font_provider
     * @param string $font The TCPDF name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('font_provider')]
    public function test_only_the_notices_have_the_reserved_word(string $font): void {
        $this->assertFileExists(self::folder() . $font . '.z');

        $names = self::name_records($font);

        $this->assertMatchesRegularExpression('/Adobe .*with Reserved Font Name .Source./u', $names[0]);
        $this->assertSame('Source is a trademark of Adobe in the United States and/or other countries.', $names[7]);
        $this->assertSame('http://scripts.sil.org/OFL', $names[14]);
        unset($names[0], $names[7]);
        foreach ($names as $id => $text) {
            $this->assertStringNotContainsStringIgnoringCase('source', $text, "Name record $id of $font");
        }
    }

    /**
     * The name of each family reaches a registered font. For a name it doesn't know, TCPDF goes on with the current font
     * and says nothing.
     *
     * @dataProvider family_provider
     * @param string $family The family
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('family_provider')]
    public function test_family_is_registered(string $family): void {
        $doc = new document('P', 'mm', 'LETTER', true, 'UTF-8');
        $doc->add_fonts();
        $doc->SetFont('helvetica', '', 9);

        $this->assertSame($family, $doc->getFontFamilyName($family));
    }

    /**
     * A PDF embeds a subset of each of the seven fonts under its new name.
     */
    public function test_pdf_embeds_each_font_under_its_new_name(): void {
        $doc = new document('P', 'mm', 'LETTER', true, 'UTF-8');
        $doc->add_fonts();
        $doc->AddPage();
        foreach (self::family_provider() as $family) {
            foreach (['', 'B', 'I', 'BI'] as $style) {
                $doc->SetFont($family[0], $style, 9);
                $doc->Write(5, 'Ana Ruiz');
            }
        }

        $pdf = $doc->Output('', 'S');

        preg_match_all('~/FontName /([A-Z]{6}\+)?(\S+)~', $pdf, $matches);
        $this->assertNotContains('', $matches[1], 'Every font is a subset');
        $this->assertSame([], array_diff(array_column(self::font_provider(), 1), $matches[2]));
        $this->assertSame([], preg_grep('/source/i', $matches[2]));
        $this->assertSame(count($matches[2]), substr_count($pdf, '/FontFile2 '));
    }

    /**
     * The seven fonts.
     *
     * @return array
     */
    public static function font_provider(): array {
        return [
            'sans regular' => ['clouvesans', 'ClouveSans-Regular', 'Clouve Sans', 'Clouve Sans Regular'],
            'sans bold' => ['clouvesansb', 'ClouveSans-Bold', 'Clouve Sans', 'Clouve Sans Bold'],
            'sans italic' => ['clouvesansi', 'ClouveSans-Italic', 'Clouve Sans', 'Clouve Sans Italic'],
            'sans semibold' => ['clouvesanssemib', 'ClouveSans-SemiBold', 'Clouve Sans SemiBold', 'Clouve Sans SemiBold'],
            'serif regular' => ['clouveserif', 'ClouveSerif-Regular', 'Clouve Serif', 'Clouve Serif Regular'],
            'serif bold' => ['clouveserifb', 'ClouveSerif-Bold', 'Clouve Serif', 'Clouve Serif Bold'],
            'serif italic' => ['clouveserifi', 'ClouveSerif-Italic', 'Clouve Serif', 'Clouve Serif Italic'],
        ];
    }

    /**
     * The three families the builder asks for.
     *
     * @return array
     */
    public static function family_provider(): array {
        return [
            'sans' => ['clouvesans'],
            'sans semibold' => ['clouvesanssemib'],
            'serif' => ['clouveserif'],
        ];
    }

    /**
     * The folder of the fonts.
     *
     * @return string
     */
    private static function folder(): string {
        return \core_component::get_component_directory('local_academictranscript') . '/fonts/';
    }

    /**
     * The values of a font definition.
     *
     * @param string $font The TCPDF name
     * @return array
     */
    private static function definition(string $font): array {
        $name = $file = $ctg = $originalsize = null;
        include(self::folder() . $font . '.php');
        return ['name' => $name, 'file' => $file, 'ctg' => $ctg, 'originalsize' => $originalsize];
    }

    /**
     * The TrueType file of a font.
     *
     * @param string $font The TCPDF name
     * @return string
     */
    private static function truetype(string $font): string {
        return gzuncompress(file_get_contents(self::folder() . $font . '.z'));
    }

    /**
     * The text of the name records of a font, by name ID. The fonts only have Windows records, which are UTF-16.
     *
     * @param string $font The TCPDF name
     * @return string[]
     */
    private static function name_records(string $font): array {
        $ttf = self::truetype($font);
        $start = 0;
        for ($i = 0; $i < unpack('n', $ttf, 4)[1]; $i++) {
            if (substr($ttf, 12 + 16 * $i, 4) === 'name') {
                $start = unpack('N', $ttf, 20 + 16 * $i)[1];
            }
        }
        $table = unpack('nformat/ncount/nstorage', $ttf, $start);
        $names = [];
        for ($i = 0; $i < $table['count']; $i++) {
            $record = unpack('nplatform/nencoding/nlanguage/nid/nlength/noffset', $ttf, $start + 6 + 12 * $i);
            $text = substr($ttf, $start + $table['storage'] + $record['offset'], $record['length']);
            $names[$record['id']] = \core_text::convert($text, 'UTF-16BE', 'UTF-8');
        }
        return $names;
    }
}
