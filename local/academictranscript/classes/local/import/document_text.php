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

/**
 * Plain text from an uploaded policy or catalog document (PDF, Word .docx, text; other formats through
 * Moodle's document converters when one is enabled).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class document_text {
    /** @var string[] Accepted file types for the upload form. */
    const ACCEPTED = ['.pdf', '.docx', '.doc', '.odt', '.rtf', '.txt'];

    /**
     * Text of a stored file.
     *
     * @param \stored_file $file
     * @return string
     * @throws \moodle_exception when the text cannot be read
     */
    public static function from_stored_file(\stored_file $file): string {
        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $text = '';
        if ($ext === 'pdf') {
            $text = self::from_pdf($file);
        } else if ($ext === 'docx') {
            $text = self::from_docx($file->get_content());
        } else if ($ext === 'txt' || $ext === 'csv' || $ext === 'md') {
            $text = $file->get_content();
        } else {
            $text = self::via_converter($file);
        }
        $text = self::clean($text);
        if (trim($text) === '') {
            throw new \moodle_exception('err_notext', 'local_academictranscript', '', $file->get_filename());
        }
        return $text;
    }

    /**
     * Tidy whitespace.
     *
     * @param string $text
     * @return string
     */
    public static function clean(string $text): string {
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim((string)$text);
    }

    /**
     * Text of a Word .docx file (paragraphs and table cells).
     *
     * @param string $content
     * @return string
     */
    public static function from_docx(string $content): string {
        if (!class_exists('\ZipArchive')) {
            return '';
        }
        $tmp = make_request_directory() . '/doc.docx';
        file_put_contents($tmp, $content);
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            return '';
        }
        $xml = (string)$zip->getFromName('word/document.xml');
        $zip->close();
        $xml = preg_replace(['#</w:p>#', '#</w:tc>#', '#<w:tab/>#', '#<w:br[^>]*/>#'], ["\n", "\t", "\t", "\n"], $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Text of a PDF: Ghostscript when configured (Site administration > Server > System paths), else the bundled parser.
     *
     * @param \stored_file $file
     * @return string
     */
    protected static function from_pdf(\stored_file $file): string {
        global $CFG;
        $dir = make_request_directory();
        $path = $dir . '/doc.pdf';
        $file->copy_content_to($path);
        if (!empty($CFG->pathtogs) && is_executable($CFG->pathtogs)) {
            $out = $dir . '/doc.txt';
            $cmd = escapeshellarg($CFG->pathtogs) . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite -sOutputFile='
                . escapeshellarg($out) . ' ' . escapeshellarg($path);
            exec($cmd, $output, $status);
            if ($status === 0 && is_readable($out) && trim((string)file_get_contents($out)) !== '') {
                return (string)file_get_contents($out);
            }
        }
        self::load_pdfparser();
        $parser = new \Smalot\PdfParser\Parser();
        return $parser->parseFile($path)->getText();
    }

    /**
     * Register an autoloader for the bundled smalot/pdfparser library (lib/pdfparser, LGPL-3.0).
     *
     * Call this before using the library's classes. It does nothing when they are already loaded.
     */
    public static function load_pdfparser(): void {
        if (class_exists('\Smalot\PdfParser\Parser', false)) {
            return;
        }
        $dir = \core_component::get_component_directory('local_academictranscript') . '/lib/pdfparser/src/';
        spl_autoload_register(function ($class) use ($dir) {
            if (strpos($class, 'Smalot\\PdfParser\\') === 0) {
                $file = $dir . str_replace('\\', '/', $class) . '.php';
                if (is_readable($file)) {
                    require_once($file);
                }
            }
        });
    }

    /**
     * Other formats (.doc, .odt, .rtf) through Moodle's document converters, if one is enabled.
     *
     * @param \stored_file $file
     * @return string
     */
    protected static function via_converter(\stored_file $file): string {
        $converter = new \core_files\converter();
        if (!$converter->can_convert_storedfile_to($file, 'txt')) {
            throw new \moodle_exception('err_convert', 'local_academictranscript', '', $file->get_filename());
        }
        $conversion = $converter->start_conversion($file, 'txt');
        for ($i = 0; $i < 30 && $conversion->get('status') !== \core_files\conversion::STATUS_COMPLETE; $i++) {
            if ($conversion->get('status') === \core_files\conversion::STATUS_FAILED) {
                break;
            }
            sleep(1);
            $conversion = $converter->poll_conversion($conversion);
        }
        $result = $conversion->get_destfile();
        if (!$result) {
            throw new \moodle_exception('err_convert', 'local_academictranscript', '', $file->get_filename());
        }
        return $result->get_content();
    }
}
