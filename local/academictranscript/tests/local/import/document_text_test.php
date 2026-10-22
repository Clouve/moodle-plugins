<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_academictranscript\local\import;

#[\PHPUnit\Framework\Attributes\CoversClass(document_text::class)]
/**
 * Tests for reading text out of uploaded policy documents.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\import\document_text
 */
final class document_text_test extends \advanced_testcase {
    /**
     * Start each test with a clean site, so files and settings made by a test do not outlast it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A PDF is read with the parser bundled in the plugin when Ghostscript is not configured.
     */
    public function test_from_stored_file_reads_pdf_with_bundled_parser(): void {
        global $CFG;

        $CFG->pathtogs = '';
        $file = $this->store_file('policy.pdf', $this->make_pdf('Grading policy: A is 93 to 100'));

        $this->assertStringContainsString('Grading policy', document_text::from_stored_file($file));
    }

    /**
     * A Word file gives its paragraphs and its table cells, each apart from the next.
     */
    public function test_from_docx_reads_paragraphs_and_table_cells(): void {
        $docx = $this->make_docx(
            '<w:p><w:r><w:t>Hello</w:t></w:r></w:p>'
            . '<w:tbl><w:tr>'
            . '<w:tc><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:p><w:r><w:t>4.0</w:t></w:r></w:p></w:tc>'
            . '</w:tr></w:tbl>'
        );

        $text = document_text::from_docx($docx);

        $this->assertSame(['Hello', 'A', '4.0'], preg_split('/\s+/', trim($text)));
    }

    /**
     * Line endings, tabs and non-breaking spaces are tidied, and runs of blank lines shrink to one blank line.
     */
    public function test_clean_tidies_whitespace(): void {
        $this->assertSame("a\n\n b c", document_text::clean("a\r\n\r\n\r\n\tb\u{00A0}c"));
    }

    /**
     * A format that needs a document converter fails with the "convert" error when no converter is enabled.
     */
    public function test_unsupported_format_without_converter_throws(): void {
        $file = $this->store_file('policy.odt', 'Not a real document, but the converter check never opens it.');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('err_convert', 'local_academictranscript', 'policy.odt'));
        document_text::from_stored_file($file);
    }

    /**
     * Store a file where the setup wizard keeps the documents it reads.
     *
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private function store_file(string $filename, string $content): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_academictranscript',
            'filearea' => 'sourcedocs',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * A one-page PDF made with TCPDF that holds a single line of text.
     *
     * @param string $line
     * @return string PDF content
     */
    private function make_pdf(string $line): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, $line);
        return $pdf->Output('', 'S');
    }

    /**
     * A Word file reduced to the part the plugin reads, word/document.xml.
     *
     * @param string $bodyxml the content of the document body
     * @return string the .docx file content
     */
    private function make_docx(string $bodyxml): string {
        $path = make_request_directory() . '/test.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $bodyxml . '</w:body></w:document>'
        );
        $zip->close();
        return file_get_contents($path);
    }
}
