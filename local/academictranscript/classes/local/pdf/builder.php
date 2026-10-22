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

namespace local_academictranscript\local\pdf;

use local_academictranscript\local\programs;
use local_academictranscript\local\scale;
use local_academictranscript\local\transcript;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/pdflib.php');

/**
 * Renders a transcript as a PDF on US Letter or A4 paper (a setting): Academic Record pages, then the Transcript Key.
 *
 * Terms and transfer credit are laid out in two columns. Blocks are measured first and placed
 * column by column; when page 1 is full the record continues on further pages. The credit summary,
 * signature block and privacy footer close the record.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class builder {
    /** @var float Content left edge (mm). */
    const X0 = 12.5;
    /** @var float Content width (mm), set from the page size. */
    protected static $w = 190.9;
    /** @var float Top of content (mm). */
    const TOP = 12.5;
    /** @var float Bottom of content (mm), set from the page size. */
    protected static $bottom = 266.9;
    /** @var float Gap between the two columns (mm). */
    const GAP = 6.0;
    /** @var float Height of the signature block (mm). */
    const SIGH = 31.0;
    /** @var string Accent colour (setting). */
    protected static $accent = '#1F3A5F';

    /** @var int[] Accent colour RGB. */
    protected static $accentrgb = [31, 58, 95];
    /** @var string Muted text colour. */
    const MUTED = '#3d4452';

    /** @var string Short unit heading of the current transcript (Cr or Hrs). */
    protected static $unitshort = '';

    /** @var document|null Scratch document for measuring. */
    protected static $scratch = null;

    /**
     * Render the PDF.
     *
     * @param array $data transcript data, from transcript::build() or an issued snapshot
     * @param \stdClass|null $issue issue record for an Official transcript, null for Unofficial
     * @return string PDF content
     */
    public static function render(array $data, ?\stdClass $issue): string {
        $official = !empty($issue);
        self::setup_page();
        $doc = self::new_document(self::page_format());
        $doc->accent = self::$accentrgb;
        self::$unitshort = $data['unitshort'];
        $doc->SetTitle(get_string($official ? 'doctitle_official_flat' : 'doctitle_unofficial_flat', 'local_academictranscript'));
        $doc->SetAuthor(self::school());
        $doc->SetCreator(get_string('pluginname', 'local_academictranscript'));
        if (!$official) {
            $doc->watermark = get_string('watermark', 'local_academictranscript');
        }
        $signed = $official ? signer::apply($doc) : false;

        // Blocks for the two columns.
        $colw = (self::$w - self::GAP) / 2;
        $specs = [];
        foreach ($data['transfer'] as $group) {
            $specs[] = ['title' => get_string('transfercredit', 'local_academictranscript'), 'right' => $group['institution'],
                'rows' => $group['rows'],
                'totals' => [get_string('transferaccepted', 'local_academictranscript'),
                    get_string('earnedx', 'local_academictranscript', self::n1($group['earned']))]];
        }
        foreach ($data['terms'] as $term) {
            $range = $term['startdate'] ? transcript::shortdate($term['startdate']) . ' - ' .
                transcript::shortdate($term['enddate']) : '';
            $specs[] = ['title' => $term['name'], 'right' => $range, 'rows' => $term['rows'],
                'totals' => [get_string('termtotals', 'local_academictranscript', ['earned' => self::n1($term['earned']),
                    'hours' => self::n1($term['gpahours']), 'qp' => self::n2($term['qp'])]),
                    get_string('termgpa', 'local_academictranscript', $term['gpa'] === '' ? '-' : $term['gpa'])]];
        }
        if (!$specs) {
            $specs[] = ['html' => '<p style="font-size:9pt;color:' . self::MUTED . ';">'
                . s(get_string('nocourses', 'local_academictranscript')) . '</p>'];
        }
        $blocks = [];
        foreach ($specs as $spec) {
            foreach (self::split_block($spec, $colw, 150) as $html) {
                $blocks[] = ['html' => $html, 'h' => self::measure($html, $colw)];
            }
        }
        if (trim((string)$data['qualification']) !== '') {
            $html = self::degree_html($data);
            $blocks[] = ['html' => $html, 'h' => self::measure($html, $colw)];
        }
        $colheader = self::colheader_html();
        $colheaderh = self::measure($colheader, $colw);
        $footer = self::footer_html();
        $footerh = self::measure($footer, self::$w);
        $summary = self::summary_html($data);
        $summaryh = self::measure($summary, self::$w);
        $limit = self::$bottom - $footerh - 2;

        // Record pages.
        $first = true;
        $queue = $blocks;
        $colys = [];
        while (true) {
            $doc->AddPage();
            $y = self::record_header($doc, $official, $data);
            $y = $first ? self::student_box($doc, $data, $y) : self::continued_line($doc, $data, $y);
            $first = false;
            self::write($doc, $footer, self::X0, self::$bottom - $footerh, self::$w);
            $colys = [$y, $y];
            $headerdone = [false, false];
            $col = 0;
            // If everything left fits on this page, balance the two columns (as in the design).
            $remaining = array_sum(array_map(fn($b) => $b['h'] + 2.5, $queue));
            $target = ($remaining + 2 * ($colheaderh + 1) <= 2 * ($limit - $y)) ? $y + $colheaderh + 1 + $remaining / 2 : $limit;
            while ($queue) {
                $block = $queue[0];
                $need = $block['h'] + ($headerdone[$col] ? 0 : $colheaderh + 1);
                $collimit = ($col === 0 && $headerdone[0]) ? min($limit, $target + 2) : $limit;
                if ($colys[$col] + $need > $collimit) {
                    if ($col === 0) {
                        $col = 1;
                        continue;
                    }
                    break; // Next page.
                }
                $x = self::X0 + $col * ($colw + self::GAP);
                if (!$headerdone[$col]) {
                    self::write($doc, $colheader, $x, $colys[$col], $colw);
                    $colys[$col] += $colheaderh + 1;
                    $headerdone[$col] = true;
                }
                self::write($doc, $block['html'], $x, $colys[$col], $colw);
                $colys[$col] += $block['h'] + 2.5;
                array_shift($queue);
            }
            if (!$queue) {
                break;
            }
        }

        // Credit summary and signature block at the bottom of the last record page.
        $sigy = $limit - self::SIGH;
        $summaryy = $sigy - 3 - $summaryh - 1.5;
        if (max($colys) > $summaryy - 1) {
            $doc->AddPage();
            $y = self::record_header($doc, $official, $data);
            self::continued_line($doc, $data, $y);
            self::write($doc, $footer, self::X0, self::$bottom - $footerh, self::$w);
        }
        $doc->SetLineStyle(['width' => 0.5, 'dash' => 0, 'color' => self::$accentrgb]);
        $doc->Line(self::X0, $summaryy - 1.5, self::X0 + self::$w, $summaryy - 1.5);
        self::write($doc, $summary, self::X0, $summaryy, self::$w);
        self::signature_block($doc, $data, $issue, $sigy, $signed);

        // Transcript Key.
        self::key_page($doc, $data);

        return $doc->Output('', 'S');
    }

    /**
     * Page format setting: LETTER or A4.
     *
     * @return string
     */
    protected static function page_format(): string {
        return get_config('local_academictranscript', 'pagesize') === 'A4' ? 'A4' : 'LETTER';
    }

    /**
     * Page geometry and accent colour from the settings.
     */
    protected static function setup_page(): void {
        if (self::page_format() === 'A4') {
            self::$w = 210 - 2 * self::X0;
            self::$bottom = 297 - self::X0;
        } else {
            self::$w = 215.9 - 2 * self::X0;
            self::$bottom = 279.4 - self::X0;
        }
        $hex = transcript::setting('accentcolor', '#1F3A5F');
        if (!preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m)) {
            $m = [1 => '1F3A5F'];
        }
        self::$accent = '#' . strtoupper($m[1]);
        self::$accentrgb = array_map('hexdec', str_split($m[1], 2));
    }

    /**
     * File name for the download.
     *
     * @param array $data
     * @param bool $official
     * @return string
     */
    public static function filename(array $data, bool $official): string {
        $name = clean_filename(str_replace(' ', '-', $data['fullname']));
        return 'transcript-' . $name . '-' . ($official ? 'official' : 'unofficial') . '-' . date('Ymd') . '.pdf';
    }

    /**
     * A new document with the bundled fonts.
     *
     * @param array|string $format
     * @return document
     */
    protected static function new_document($format = 'LETTER'): document {
        $doc = new document('P', 'mm', $format, true, 'UTF-8');
        $doc->add_fonts();
        $doc->setPrintHeader(true);
        $doc->setPrintFooter(false);
        $doc->SetMargins(self::X0, self::TOP, self::X0);
        $doc->SetAutoPageBreak(false);
        $doc->setCellHeightRatio(1.15);
        $doc->SetFont('sourcesans3', '', 9);
        $doc->SetTextColor(27, 31, 39);
        return $doc;
    }

    /**
     * Height of an HTML block at a width (mm).
     *
     * @param string $html
     * @param float $width
     * @return float
     */
    protected static function measure(string $html, float $width): float {
        if (!self::$scratch || self::$scratch->GetY() > 1500) {
            self::$scratch = self::new_document([216, 2000]);
            self::$scratch->setPrintHeader(false);
            self::$scratch->AddPage();
        }
        $doc = self::$scratch;
        $y0 = $doc->GetY() + 5;
        $doc->writeHTMLCell($width, 0, self::X0, $y0, $html, 0, 1, false, true, 'L');
        return $doc->GetY() - $y0;
    }

    /**
     * Write an HTML block at a position.
     *
     * @param document $doc
     * @param string $html
     * @param float $x
     * @param float $y
     * @param float $w
     */
    protected static function write(document $doc, string $html, float $x, float $y, float $w): void {
        $doc->writeHTMLCell($w, 0, $x, $y, $html, 0, 1, false, true, 'L');
    }

    /**
     * Split a block spec into HTML pieces that each fit $maxh (rows are split, the title repeats).
     *
     * @param array $spec
     * @param float $width
     * @param float $maxh
     * @return string[]
     */
    protected static function split_block(array $spec, float $width, float $maxh): array {
        if (isset($spec['html'])) {
            return [$spec['html']];
        }
        $html = self::block_html($spec);
        if (count($spec['rows']) < 2 || self::measure($html, $width) <= $maxh) {
            return [$html];
        }
        $half = (int)ceil(count($spec['rows']) / 2);
        $a = $spec;
        $a['rows'] = array_slice($spec['rows'], 0, $half);
        $a['totals'] = null;
        $b = $spec;
        $b['rows'] = array_slice($spec['rows'], $half);
        $b['title'] = get_string('continued', 'local_academictranscript', $spec['title']);
        return array_merge(self::split_block($a, $width, $maxh), self::split_block($b, $width, $maxh));
    }

    /**
     * The name of the school: the School name setting, or the full name of the site when that is empty.
     *
     * @return string plain text
     */
    protected static function school(): string {
        return transcript::school_name();
    }

    /**
     * Number with one decimal.
     *
     * @param float|null $n
     * @return string
     */
    protected static function n1(?float $n): string {
        return $n === null ? '' : number_format($n, 1);
    }

    /**
     * Number with two decimals.
     *
     * @param float|null $n
     * @return string
     */
    protected static function n2(?float $n): string {
        return $n === null ? '' : number_format($n, 2);
    }

    /**
     * Content of a stored setting file (logo, signatureimage, sealimage), or null.
     *
     * @param string $area
     * @return string|null
     */
    protected static function setting_file(string $area): ?string {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_academictranscript',
            $area,
            0,
            'id',
            false
        );
        $file = reset($files);
        return $file ? $file->get_content() : null;
    }

    /**
     * Header of an Academic Record page. Returns the y below it.
     *
     * @param document $doc
     * @param bool $official
     * @param array $data
     * @return float
     */
    protected static function record_header(document $doc, bool $official, array $data): float {
        $top = self::TOP;
        $textx = self::X0;
        if ($logo = self::setting_file('logo')) {
            $doc->Image('@' . $logo, self::X0, $top, 19, 19, '', '', '', true, 300, '', false, false, 0, 'CM');
            $textx = self::X0 + 23;
        }
        $rightw = 46;
        $parts = [];
        $address = trim(preg_replace('/\s*\R\s*/', ', ', transcript::setting('address')), ', ');
        if ($address !== '') {
            $parts[] = $address;
        }
        $parts[] = get_string('registraroffice', 'local_academictranscript');
        foreach (['phone', 'email', 'website'] as $key) {
            if (($v = transcript::setting($key)) !== '') {
                $parts[] = $v;
            }
        }
        $left = '<div style="font-family:sourceserif4;font-weight:bold;font-size:19pt;color:' . self::$accent . ';">'
            . s(self::school()) . '</div><div style="font-size:8.5pt;color:' . self::MUTED . ';">'
            . s(implode(' · ', $parts)) . '</div>';
        $doc->writeHTMLCell(self::X0 + self::$w - $rightw - 3 - $textx, 0, $textx, $top + 1, $left, 0, 1);
        $lefty = $doc->GetY();

        $title = get_string($official ? 'doctitle_official' : 'doctitle_unofficial', 'local_academictranscript');
        $doc->SetFont('sourceserif4', 'B', 11);
        $doc->SetTextColor(27, 31, 39);
        $doc->MultiCell($rightw, 0, $title, 0, 'R', false, 1, self::X0 + self::$w - $rightw, $top + 2);
        $doc->SetFont('sourcesans3', '', 8.5);
        $doc->SetTextColor(61, 68, 82);
        $doc->SetX(self::X0 + self::$w - $rightw);
        $doc->Cell($rightw, 4, get_string(
            'pageof',
            'local_academictranscript',
            ['page' => $doc->getAliasNumPage(), 'pages' => $doc->getAliasNbPages()]
        ), 0, 1, 'R');
        $y = max($lefty, $doc->GetY(), $top + 19) + 1.5;
        $doc->SetLineStyle(['width' => 0.5, 'dash' => 0, 'color' => self::$accentrgb]);
        $doc->Line(self::X0, $y, self::X0 + self::$w, $y);
        $doc->SetTextColor(27, 31, 39);
        $doc->SetFont('sourcesans3', '', 9);
        return $y + 3;
    }

    /**
     * Label + value cell for the student box.
     *
     * @param string $labelkey
     * @param string $value
     * @param string $width
     * @param int $colspan
     * @return string
     */
    protected static function info_cell(string $labelkey, string $value, string $width, int $colspan = 1): string {
        return '<td width="' . $width . '"' . ($colspan > 1 ? ' colspan="' . $colspan . '"' : '') . '>'
            . '<span style="font-size:7pt;color:#555c69;">'
            . s(\core_text::strtoupper(get_string($labelkey, 'local_academictranscript')))
            . '</span><br/><span style="font-family:sourcesans3semib;font-size:9.5pt;">' . s($value === '' ? '-' : $value)
            . '</span></td>';
    }

    /**
     * Student details box (page 1). Returns the y below it.
     *
     * @param document $doc
     * @param array $data
     * @param float $y
     * @return float
     */
    protected static function student_box(document $doc, array $data, float $y): float {
        $html = '<table cellpadding="4" style="background-color:#f3f4f7;border:0.3px solid #d5d9e0;"><tr>'
            . self::info_cell('studentname', $data['fullname'], '25%')
            . self::info_cell('studentid', $data['studentid'], '25%')
            . self::info_cell('dob', transcript::date($data['dob']), '25%')
            . self::info_cell('admitterm', $data['admitterm'], '25%')
            . '</tr><tr>'
            . self::info_cell('program', $data['program'], '50%', 2)
            . self::info_cell('status', $data['status'], '25%')
            . self::info_cell('calendar', $data['unitlabel'], '25%')
            . '</tr></table>';
        self::write($doc, $html, self::X0, $y, self::$w);
        return $doc->GetY() + 3;
    }

    /**
     * "Name · ID (continued)" line on later record pages, or "Name (continued)" for a student without a Student ID.
     * Returns the y below it.
     *
     * @param document $doc
     * @param array $data
     * @param float $y
     * @return float
     */
    protected static function continued_line(document $doc, array $data, float $y): float {
        if ($data['studentid'] === '') {
            $text = get_string('continuedrecordnoid', 'local_academictranscript', $data['fullname']);
        } else {
            $text = get_string(
                'continuedrecord',
                'local_academictranscript',
                ['name' => $data['fullname'], 'id' => $data['studentid']]
            );
        }
        $html = '<div style="font-size:9pt;color:' . self::MUTED . ';">' . s($text) . '</div>';
        self::write($doc, $html, self::X0, $y, self::$w);
        return $doc->GetY() + 2;
    }

    /**
     * Column header row: Course, Title, Cr, Grd, QP.
     *
     * @return string
     */
    protected static function colheader_html(): string {
        $c = 'style="font-weight:bold;font-size:7.5pt;color:' . self::MUTED . ';border-bottom:0.3px solid #9aa1ad;"';
        $h = fn($key) => s(\core_text::strtoupper(get_string($key, 'local_academictranscript')));
        $unit = s(\core_text::strtoupper((string)self::$unitshort));
        return '<table cellpadding="1"><tr>'
            . '<td width="17%" ' . $c . '>' . $h('col_course') . '</td>'
            . '<td width="47%" ' . $c . '>' . $h('col_title') . '</td>'
            . '<td width="11%" align="right" ' . $c . '>' . $unit . '</td>'
            . '<td width="11%" align="right" ' . $c . '>' . $h('col_grd') . '</td>'
            . '<td width="14%" align="right" ' . $c . '>' . $h('col_qp') . '</td></tr></table>';
    }

    /**
     * A term or transfer block.
     *
     * @param array $spec title, right, rows, totals
     * @return string
     */
    protected static function block_html(array $spec): string {
        $html = '<table cellpadding="0.8" style="font-size:8.5pt;"><tr>'
            . '<td width="60%" style="font-weight:bold;">' . s($spec['title']) . '</td>'
            . '<td width="40%" align="right" style="font-family:sourcesans3semib;color:' . self::MUTED . ';">'
            . s($spec['right']) . '</td></tr>';
        foreach ($spec['rows'] as $row) {
            $qp = $row['qp'] === null ? '-' : self::n2($row['qp']);
            $html .= '<tr nobr="true"><td width="17%">' . s($row['code']) . '</td>'
                . '<td width="47%">' . s($row['title']) . '</td>'
                . '<td width="11%" align="right">' . self::n1($row['hours']) . '</td>'
                . '<td width="11%" align="right">' . s($row['grade']) . '</td>'
                . '<td width="14%" align="right">' . $qp . '</td></tr>';
            foreach ($row['notes'] as $note) {
                $html .= '<tr><td width="17%"></td><td width="83%" style="font-style:italic;'
                    . 'font-size:7.5pt;color:' . self::MUTED . ';">' . s($note) . '</td></tr>';
            }
        }
        if (!empty($spec['totals'])) {
            $border = 'border-top:0.3px solid #c9ced6;';
            $html .= '<tr><td width="68%" style="' . $border . 'color:' . self::MUTED . ';">' . s($spec['totals'][0]) . '</td>'
                . '<td width="32%" align="right" style="' . $border . 'font-weight:bold;">' . s($spec['totals'][1])
                . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * Degree Awarded box.
     *
     * @param array $data
     * @return string
     */
    protected static function degree_html(array $data): string {
        $line = $data['awarddate'] ? get_string('conferred', 'local_academictranscript', transcript::date($data['awarddate'])) : '';
        return '<table cellpadding="3" style="border:0.4px solid ' . self::$accent . ';"><tr><td>'
            . '<span style="font-size:7.5pt;font-weight:bold;color:' . self::$accent . ';">'
            . s(\core_text::strtoupper(get_string('degreeawarded', 'local_academictranscript'))) . '</span><br/>'
            . '<span style="font-family:sourcesans3semib;font-size:9.5pt;">' . s($data['qualification']) . '</span>'
            . ($line !== '' ? '<br/><span style="font-size:8.5pt;color:' . self::MUTED . ';">' . s($line) . '</span>' : '')
            . '</td></tr></table>';
    }

    /**
     * Credit summary table.
     *
     * @param array $data
     * @return string
     */
    protected static function summary_html(array $data): string {
        $h = 'style="font-weight:bold;font-size:7.5pt;color:' . self::MUTED . ';border-bottom:0.3px solid #9aa1ad;"';
        $up = fn($key) => s(\core_text::strtoupper(get_string($key, 'local_academictranscript')));
        $html = '<table cellpadding="0.8" style="font-size:8.5pt;"><tr>'
            . '<td width="36%" ' . $h . '>'
            . $up($data['unittype'] === programs::CLOCK ? 'clocksummary' : 'creditsummary') . '</td>'
            . '<td width="16%" align="right" ' . $h . '>' . $up('earned') . '</td>'
            . '<td width="16%" align="right" ' . $h . '>' . $up('gpahours') . '</td>'
            . '<td width="16%" align="right" ' . $h . '>' . $up('qualitypoints') . '</td>'
            . '<td width="16%" align="right" ' . $h . '>' . $up('gpa') . '</td></tr>';
        $rows = [
            ['summary_institutional', $data['summary']['institutional'], ''],
            ['summary_transfer', $data['summary']['transfer'], ''],
            ['summary_overall', $data['summary']['overall'], 'font-weight:bold;border-top:0.3px solid #c9ced6;'],
        ];
        foreach ($rows as [$key, $v, $style]) {
            $transfer = $key === 'summary_transfer';
            $gpa = $v['gpa'] === '' ? '-' : $v['gpa'];
            $gpastyle = $key === 'summary_overall' ? 'color:' . self::$accent . ';' : '';
            $html .= '<tr><td width="36%" style="' . $style . '">' . s(get_string($key, 'local_academictranscript')) . '</td>'
                . '<td width="16%" align="right" style="' . $style . '">' . self::n1($v['earned']) . '</td>'
                . '<td width="16%" align="right" style="' . $style . '">' . self::n1($v['gpahours']) . '</td>'
                . '<td width="16%" align="right" style="' . $style . '">' . self::n2($v['qp']) . '</td>'
                . '<td width="16%" align="right" style="' . $style . $gpastyle . '">' . ($transfer ? '-' : s($gpa))
                . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * Privacy footer.
     *
     * @return string
     */
    protected static function footer_html(): string {
        $text = transcript::setting('footer', get_string('footer_default', 'local_academictranscript'));
        return '<div style="font-size:8pt;color:' . self::MUTED . ';text-align:center;">' . nl2br(s($text)) . '</div>';
    }

    /**
     * Verify page address without the scheme.
     *
     * @return string
     */
    protected static function verify_display(): string {
        $url = (new \moodle_url('/local/academictranscript/verify.php'))->out(false);
        return preg_replace('#^https?://#', '', $url);
    }

    /**
     * Seal, signature and the issue details box.
     *
     * @param document $doc
     * @param array $data
     * @param \stdClass|null $issue
     * @param float $y
     * @param bool $signed
     */
    protected static function signature_block(document $doc, array $data, ?\stdClass $issue, float $y, bool $signed): void {
        $x = self::X0;
        $doc->SetLineStyle(['width' => 0.3, 'dash' => 0, 'color' => [213, 217, 224]]);
        $doc->SetFillColor(250, 251, 252);
        $doc->Rect($x, $y, self::$w, self::SIGH, 'DF');
        $images = get_config('local_academictranscript', 'signaturemode') === 'images';
        $accent = self::$accentrgb;

        // Seal.
        $cx = $x + 15;
        $cy = $y + self::SIGH / 2;
        $seal = $images ? self::setting_file('sealimage') : null;
        if ($seal) {
            $doc->Image('@' . $seal, $cx - 12, $cy - 12, 24, 24, '', '', '', true, 300, '', false, false, 0, 'CM');
        } else {
            $doc->SetLineStyle(['width' => 0.4, 'dash' => '1,1', 'color' => $accent]);
            $doc->Circle($cx, $cy, 12);
            $doc->SetLineStyle(['width' => 0.3, 'dash' => 0, 'color' => $accent]);
            $doc->SetFont('sourcesans3', 'B', 7);
            $doc->SetTextColor(...self::$accentrgb);
            $doc->MultiCell(20, 0, get_string('sig_seal', 'local_academictranscript'), 0, 'C', false, 1, $cx - 10, $cy - 4);
        }

        // Signature.
        $mx = $x + 32;
        $mw = self::$w - 32 - 70;
        $name = transcript::setting('registrarname');
        $title = transcript::setting('registrartitle');
        $doc->SetTextColor(27, 31, 39);
        $doc->SetLineStyle(['width' => 0.3, 'dash' => 0, 'color' => [27, 31, 39]]);
        $sigimage = $images ? self::setting_file('signatureimage') : null;
        if ($images) {
            if ($sigimage) {
                $doc->Image('@' . $sigimage, $mx, $y + 2.5, $mw, 10, '', '', '', true, 300, '', false, false, 0, 'LB');
            }
            $doc->Line($mx, $y + 13, $mx + $mw, $y + 13);
            $doc->SetFont('sourcesans3', 'B', 8.5);
            $who = trim($name . ($name !== '' && $title !== '' ? ', ' : '') . $title);
            $doc->MultiCell(
                $mw,
                0,
                $who !== '' ? $who : get_string('sig_official', 'local_academictranscript'),
                0,
                'L',
                false,
                1,
                $mx,
                $y + 13.5
            );
        } else {
            $doc->Line($mx, $y + 11, $mx + $mw, $y + 11);
            $doc->SetFont('sourcesans3', '', 8);
            $doc->MultiCell(
                $mw,
                0,
                get_string('sig_official', 'local_academictranscript') . ($name !== '' ? ' (' . $name . ')' : ''),
                0,
                'L',
                false,
                1,
                $mx,
                $y + 11.4
            );
            $half = ($mw - 6) / 2;
            if ($title !== '') {
                $doc->SetFont('sourcesans3', '', 8.5);
                $doc->MultiCell($half, 0, $title, 0, 'L', false, 1, $mx, $y + 17.2);
            }
            $doc->Line($mx, $y + 21.5, $mx + $half, $y + 21.5);
            $doc->Line($mx + $half + 6, $y + 21.5, $mx + $mw, $y + 21.5);
            $doc->SetFont('sourcesans3', '', 8);
            $doc->MultiCell($half, 0, get_string('sig_title', 'local_academictranscript'), 0, 'L', false, 1, $mx, $y + 21.9);
            $datelabel = get_string('sig_date', 'local_academictranscript');
            $doc->MultiCell($half, 0, $datelabel, 0, 'L', false, 1, $mx + $half + 6, $y + 21.9);
        }
        $doc->SetFont('sourcesans3', 'I', 7.5);
        $doc->SetTextColor(61, 68, 82);
        $note = $issue ? ($signed ? get_string('digitallysigned', 'local_academictranscript') :
            get_string('notsigned', 'local_academictranscript')) : get_string('unofficialnote', 'local_academictranscript');
        $doc->MultiCell($mw, 0, $note, 0, 'L', false, 1, $mx, $y + self::SIGH - 5);

        // Issue details.
        if ($issue) {
            $rows = [
                'dateissuedcol' => transcript::date((int)$issue->timecreated),
                'lastentry' => transcript::date($data['lastentry']),
                'transcriptno' => (string)($issue->transcriptno ?? ''),
                'issuedto' => (string)($issue->recipient ?? ''),
                'verifyat' => self::verify_display(),
                'verifycodeshort' => transcript::format_code($issue->verifycode),
            ];
        } else {
            $rows = [
                'dateprintedcol' => transcript::date((int)$data['generated']),
                'lastentry' => transcript::date($data['lastentry']),
                'copytype' => get_string('unofficialcopy', 'local_academictranscript'),
                'verifyat' => get_string('notverifiable', 'local_academictranscript'),
            ];
        }
        $html = '<table cellpadding="0.4" style="font-size:7.5pt;">';
        foreach ($rows as $key => $value) {
            $html .= '<tr><td width="30%" style="color:#555c69;">' . s(get_string($key, 'local_academictranscript')) . '</td>'
                . '<td width="70%" style="font-family:sourcesans3semib;">' . s($value === '' ? '-' : $value) . '</td></tr>';
        }
        $html .= '</table>';
        $h = self::measure($html, 66);
        self::write($doc, $html, $x + self::$w - 68, $y + max(1.5, (self::SIGH - $h) / 2), 66);
        $doc->SetTextColor(27, 31, 39);
    }

    /**
     * The Transcript Key page.
     *
     * @param document $doc
     * @param array $data
     */
    protected static function key_page(document $doc, array $data): void {
        $doc->SetAutoPageBreak(true, 14);
        $doc->AddPage();
        $top = self::TOP;
        $left = '<div style="font-family:sourceserif4;font-weight:bold;font-size:16pt;color:' . self::$accent . ';">'
            . s(get_string('transcriptkey', 'local_academictranscript')) . '</div><div style="font-size:8.5pt;color:' . self::MUTED
            . ';">' . s(self::school() . ' · ' . get_string('registraroffice', 'local_academictranscript')) . '</div>';
        $doc->writeHTMLCell(120, 0, self::X0, $top, $left, 0, 1);
        $lefty = $doc->GetY();
        $doc->SetFont('sourcesans3', '', 8.5);
        $doc->SetTextColor(61, 68, 82);
        $doc->SetXY(self::X0 + self::$w - 70, $top + 3);
        $student = $data['fullname'];
        if ($data['studentid'] !== '') {
            $a = ['name' => $data['fullname'], 'id' => $data['studentid']];
            $student = get_string('keystudentline', 'local_academictranscript', $a);
        }
        $doc->Cell(70, 4, $student, 0, 2, 'R');
        $doc->Cell(70, 4, get_string(
            'pageof',
            'local_academictranscript',
            ['page' => $doc->getAliasNumPage(), 'pages' => $doc->getAliasNbPages()]
        ), 0, 1, 'R');
        $y = max($lefty, $doc->GetY()) + 1.5;
        $doc->SetLineStyle(['width' => 0.5, 'dash' => 0, 'color' => self::$accentrgb]);
        $doc->Line(self::X0, $y, self::X0 + self::$w, $y);
        $doc->SetTextColor(27, 31, 39);
        $y += 3;

        $h2 = fn($key) => '<div style="font-family:sourceserif4;font-weight:bold;font-size:11pt;color:' . self::$accent . ';">'
            . s(get_string($key, 'local_academictranscript')) . '</div>';
        $th = 'style="font-weight:bold;font-size:7.5pt;color:' . self::MUTED . ';border-bottom:0.3px solid #9aa1ad;"';
        $up = fn($key) => s(\core_text::strtoupper(get_string($key, 'local_academictranscript')));
        $rowborder = 'border-bottom:0.2px solid #e1e4ea;';

        // Grades used in GPA: the approved scale, all 12 grades with percentages.
        $html = $h2('key_gradesused') . '<table cellpadding="1" style="font-size:8.5pt;"><tr>'
            . '<td width="10%" ' . $th . '>' . $up('col_grade') . '</td>'
            . '<td width="18%" align="center" ' . $th . '>' . $up('col_pointsperunit') . '</td>'
            . '<td width="20%" align="center" ' . $th . '>' . $up('col_percentage') . '</td>'
            . '<td width="52%" ' . $th . '>' . $up('col_description') . '</td></tr>';
        $keyrows = $data['key'] ?? scale::scale_rows();
        foreach ($keyrows as $row) {
            if (!empty($row['ismark'])) {
                continue;
            }
            $html .= '<tr><td width="10%" style="font-weight:bold;' . $rowborder . '">' . s($row['grade']) . '</td>'
                . '<td width="18%" align="center" style="' . $rowborder . '">' . s($row['points']) . '</td>'
                . '<td width="20%" align="center" style="' . $rowborder . '">' . s($row['percent']) . '</td>';
            if ($row['rowspan']) {
                $html .= '<td width="52%" rowspan="' . $row['rowspan'] . '" style="' . $rowborder . '">'
                    . s($row['description']) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        if (($note = transcript::setting('key_scalenote')) !== '') {
            $html .= '<div style="font-size:8pt;color:' . self::MUTED . ';">' . s($note) . '</div>';
        }
        self::write($doc, $html, self::X0, $y, self::$w);
        $y = $doc->GetY() + 3;

        // Marks not used in GPA.
        $html = $h2('key_marks') . '<table cellpadding="1" style="font-size:8.5pt;">';
        foreach ($keyrows as $row) {
            if (empty($row['ismark'])) {
                continue;
            }
            $html .= '<tr><td width="8%" style="font-weight:bold;' . $rowborder . '">' . s($row['grade']) . '</td><td width="92%"'
                . ' style="' . $rowborder . '">' . s($row['description']) . '</td></tr>';
        }
        $html .= '</table>';
        self::write($doc, $html, self::X0, $y, self::$w);
        $y = $doc->GetY() + 3;

        // Text sections in two columns.
        $replace = ['{school}' => self::school(),
            '{verifyurl}' => (new \moodle_url('/local/academictranscript/verify.php'))->out(false)];
        $sections = [];
        foreach (['key_gpa', 'key_units', 'key_coursenumbers', 'key_accreditation', 'key_real', 'key_privacy'] as $key) {
            $text = transcript::setting($key, get_string($key . '_default', 'local_academictranscript'));
            if (trim($text) === '') {
                continue;
            }
            $title = transcript::setting($key . '_title', get_string($key . '_title', 'local_academictranscript'));
            $sections[] = '<div style="font-family:sourceserif4;font-weight:bold;font-size:11pt;color:' . self::$accent . ';">'
                . s($title) . '</div><div style="font-size:8.5pt;">' . nl2br(s(strtr($text, $replace))) . '</div>';
        }
        $html = '<table cellpadding="0" cellspacing="0">';
        for ($i = 0; $i < count($sections); $i += 2) {
            $html .= '<tr nobr="true"><td width="48%">' . $sections[$i] . '<br/></td><td width="4%"></td><td width="48%">'
                . ($sections[$i + 1] ?? '') . '<br/></td></tr>';
        }
        $html .= '</table>';
        self::write($doc, $html, self::X0, $y, self::$w);
        $y = $doc->GetY() + 2;

        // Contact box.
        $contact = transcript::setting('key_contact', get_string('key_contact_default', 'local_academictranscript'));
        $html = '<table cellpadding="4" style="background-color:#f3f4f7;border:0.3px solid #d5d9e0;"><tr><td>'
            . '<span style="font-size:7.5pt;font-weight:bold;color:' . self::$accent . ';">'
            . s(\core_text::strtoupper(get_string('key_contact_title', 'local_academictranscript'))) . '</span><br/>'
            . '<span style="font-size:8.5pt;">' . nl2br(s(strtr($contact, $replace))) . '</span></td></tr></table>';
        $h = self::measure($html, self::$w);
        $doc->SetAutoPageBreak(false);
        $boxy = max($y, self::$bottom - $h);
        if ($boxy + $h > $doc->getPageHeight() - 6) {
            $doc->AddPage();
            $boxy = self::TOP;
        }
        self::write($doc, $html, self::X0, $boxy, self::$w);
    }
}
