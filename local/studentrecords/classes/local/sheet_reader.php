<?php

namespace local_studentrecords\local;

/**
 * Reads spreadsheets (.xlsx) and CSV files into plain rows of text.
 *
 * For .xlsx the values Excel saved are used (formulas are not recalculated), so the result is exactly what
 * the school sees in the file. No macros or external links are followed.
 *
 * An .xlsx file is a zip of XML files, and it comes from outside the site. So a document type is refused in any
 * encoding, and the size of what is unpacked has a limit, whatever the size of the zip. A file over a limit is refused
 * with a message: rows are never left out without saying so.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class sheet_reader {
    /** @var string Spreadsheet XML namespace. */
    const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @var string Package relationships namespace. */
    const RELNS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @var string Document relationships namespace (sheet r:id attributes). */
    const DOCRELNS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var int Largest number of rows read from one sheet. */
    const MAXROWS = 20000;

    /** @var int Widest sheet read, in columns. */
    const MAXCOLUMNS = 512;

    /** @var int Largest file read, in bytes. */
    const MAXFILEBYTES = 10485760;

    /** @var int Largest part of an .xlsx file once unpacked, in bytes. */
    const MAXPARTBYTES = 33554432;

    /** @var int Most bytes unpacked from one .xlsx file, all parts together. */
    const MAXTOTALBYTES = 67108864;

    /**
     * Read every sheet of an .xlsx file.
     *
     * @param string $path file path
     * @return array sheet name => list of rows (each a list of strings, column A first)
     * @throws \moodle_exception when the file is not a readable spreadsheet
     */
    public static function read_xlsx(string $path): array {
        $size = (int)filesize($path);
        if ($size > self::MAXFILEBYTES) {
            throw new \moodle_exception('err_filetoolarge', 'local_studentrecords');
        }
        $zip = new \ZipArchive();
        if (!$size || $zip->open($path) !== true) {
            throw new \moodle_exception('err_notspreadsheet', 'local_studentrecords');
        }
        try {
            $left = self::MAXTOTALBYTES;
            $shared = [];
            $xml = self::read_part($zip, 'xl/sharedStrings.xml', $left);
            if ($xml !== null) {
                $doc = self::load_xml($xml);
                foreach ($doc->children(self::NS)->si as $si) {
                    $shared[] = self::text_of($si);
                }
            }
            $workbook = self::read_part($zip, 'xl/workbook.xml', $left);
            $rels = self::read_part($zip, 'xl/_rels/workbook.xml.rels', $left);
            if ($workbook === null || $rels === null) {
                throw new \moodle_exception('err_notspreadsheet', 'local_studentrecords');
            }
            $targets = [];
            foreach (self::load_xml($rels)->children(self::RELNS) as $rel) {
                $targets[self::attr($rel, 'Id')] = self::attr($rel, 'Target');
            }
            $sheets = [];
            $wb = self::load_xml($workbook);
            foreach ($wb->children(self::NS)->sheets->sheet as $sheet) {
                $rid = (string)$sheet->attributes(self::DOCRELNS)['id'];
                $target = $targets[$rid] ?? '';
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                $content = self::read_part($zip, $target, $left);
                if ($content === null) {
                    continue;
                }
                $sheets[self::attr($sheet, 'name')] = self::sheet_rows(self::load_xml($content), $shared);
            }
            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * One part of an .xlsx file, unpacked within the limits.
     *
     * @param \ZipArchive $zip
     * @param string $name
     * @param int $left bytes that may still be unpacked from this file, reduced by what this part takes
     * @return string|null null when the file has no such part
     * @throws \moodle_exception when the part is over a limit
     */
    protected static function read_part(\ZipArchive $zip, string $name, int &$left): ?string {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        $size = (int)$stat['size'];
        if ($size > self::MAXPARTBYTES || $size > $left) {
            throw new \moodle_exception('err_filetoolarge', 'local_studentrecords');
        }
        // The size in the zip can be false, so nothing past it is unpacked.
        $content = $zip->getFromName($name, $size + 1);
        if ($content === false || strlen($content) > $size) {
            throw new \moodle_exception('err_notspreadsheet', 'local_studentrecords');
        }
        $left -= $size;
        return $content;
    }

    /**
     * Text of a CSV file as UTF-8. Spreadsheet programs save CSV as UTF-8, as UTF-16 or in the Windows encoding, and
     * text that is not valid UTF-8 cannot be stored.
     *
     * @param string $content
     * @return string
     */
    protected static function to_utf8(string $content): string {
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = \core_text::convert(substr($content, 2), 'utf-16le', 'utf-8');
        } else if (str_starts_with($content, "\xFE\xFF")) {
            $content = \core_text::convert(substr($content, 2), 'utf-16be', 'utf-8');
        } else if (!mb_check_encoding($content, 'UTF-8')) {
            $content = \core_text::convert($content, 'windows-1252', 'utf-8');
        }
        return str_replace("\0", '', preg_replace('/^\xEF\xBB\xBF/', '', $content));
    }

    /**
     * Read CSV text (comma, semicolon or tab separated; first line = headers).
     *
     * @param string $content
     * @return array list of rows
     * @throws \moodle_exception when the text is over a limit
     */
    public static function read_csv(string $content): array {
        if (strlen($content) > self::MAXFILEBYTES) {
            throw new \moodle_exception('err_filetoolarge', 'local_studentrecords');
        }
        $content = self::to_utf8($content);
        $lines = preg_split('/\r\n|\r|\n/', $content, 2);
        $first = $lines[0] ?? '';
        $delimiter = ',';
        foreach ([';', "\t"] as $d) {
            if (substr_count($first, $d) > substr_count($first, $delimiter)) {
                $delimiter = $d;
            }
        }
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }
            if (count($rows) >= self::MAXROWS) {
                fclose($handle);
                throw new \moodle_exception('err_filetoolarge', 'local_studentrecords');
            }
            $rows[] = array_map(fn($v) => trim((string)$v), $row);
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Rows of one worksheet.
     *
     * @param \SimpleXMLElement $doc
     * @param string[] $shared shared strings
     * @return array
     * @throws \moodle_exception when the sheet is over a limit
     */
    protected static function sheet_rows(\SimpleXMLElement $doc, array $shared): array {
        $rows = [];
        $data = $doc->children(self::NS)->sheetData;
        if (!$data) {
            return [];
        }
        foreach ($data->row as $row) {
            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                // A cell may leave out its reference: it then follows the cell before it.
                $ref = self::attr($c, 'r');
                $col = $ref === '' ? $next : self::column_index($ref);
                $next = $col + 1;
                $type = self::attr($c, 't');
                $v = isset($c->v) ? (string)$c->v : '';
                if ($type === 's') {
                    $value = $shared[(int)$v] ?? '';
                } else if ($type === 'inlineStr') {
                    $value = self::text_of($c->is);
                } else if ($type === 'b') {
                    $value = $v === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $value = $v;
                }
                $value = trim($value);
                if ($value === '') {
                    // Empty cells with only a style say nothing, and they can reach the last column of the sheet.
                    continue;
                }
                if ($col >= self::MAXCOLUMNS) {
                    throw new \moodle_exception('err_filetoolarge', 'local_studentrecords');
                }
                $cells[$col] = $value;
            }
            if (!$cells) {
                continue;
            }
            if (count($rows) >= self::MAXROWS) {
                throw new \moodle_exception('err_filetoolarge', 'local_studentrecords');
            }
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $value) {
                $line[$i] = $value;
            }
            $rows[] = $line;
        }
        return $rows;
    }

    /**
     * An attribute without a namespace (element children are read in the spreadsheet namespace).
     *
     * @param \SimpleXMLElement $node
     * @param string $name
     * @return string
     */
    protected static function attr(\SimpleXMLElement $node, string $name): string {
        $attrs = $node->attributes();
        return isset($attrs[$name]) ? (string)$attrs[$name] : '';
    }

    /**
     * Zero-based column number from a cell reference such as "C12".
     *
     * @param string $ref
     * @return int
     */
    public static function column_index(string $ref): int {
        // Four letters are enough to be past the last column, and they cannot overflow an integer.
        $letters = substr(preg_replace('/[^A-Z]/', '', strtoupper($ref)), 0, 4);
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    /**
     * All text inside a string item (plain or rich text runs).
     *
     * @param \SimpleXMLElement|null $node
     * @return string
     */
    protected static function text_of($node): string {
        if (!$node) {
            return '';
        }
        $text = '';
        foreach ($node->xpath('.//*[local-name()="t"]') as $t) {
            $text .= (string)$t;
        }
        return $text;
    }

    /**
     * Parse XML without network access or entity expansion. A spreadsheet never has a document type, and entities can
     * only be declared in one. The text search is quick, but it misses a file in another encoding such as UTF-16, so
     * the parsed document is checked too, before any of its text is read.
     *
     * @param string $xml
     * @return \SimpleXMLElement
     * @throws \moodle_exception
     */
    protected static function load_xml(string $xml): \SimpleXMLElement {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new \moodle_exception('err_notspreadsheet', 'local_studentrecords');
        }
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false || dom_import_simplexml($doc)->ownerDocument->doctype !== null) {
            throw new \moodle_exception('err_notspreadsheet', 'local_studentrecords');
        }
        return $doc;
    }
}
