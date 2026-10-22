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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/pdflib.php');

/**
 * PDF document with the double accent border on each page and, for student copies, the UNOFFICIAL watermark.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class document extends \pdf {
    /** @var string Watermark text, empty for none. */
    public $watermark = '';

    /** @var array Accent colour RGB. */
    public $accent = [31, 58, 95];

    /**
     * Register the bundled fonts (Source Sans 3, Source Serif 4; SIL Open Font License).
     */
    public function add_fonts(): void {
        $dir = \core_component::get_component_directory('local_academictranscript') . '/fonts/';
        // Every style is registered (nearest available file) so inherited bold/italic never fails.
        $families = [
            'sourcesans3' => ['' => 'sourcesans3', 'B' => 'sourcesans3b', 'I' => 'sourcesans3i', 'BI' => 'sourcesans3b'],
            'sourcesans3semib' => ['' => 'sourcesans3semib', 'B' => 'sourcesans3b', 'I' => 'sourcesans3i',
                'BI' => 'sourcesans3b'],
            'sourceserif4' => ['' => 'sourceserif4', 'B' => 'sourceserif4b', 'I' => 'sourceserif4i', 'BI' => 'sourceserif4b'],
        ];
        foreach ($families as $family => $styles) {
            foreach ($styles as $style => $file) {
                $this->AddFont($family, $style, $dir . $file . '.php');
            }
        }
    }

    /**
     * Page header: border and watermark (drawn under the content).
     */
    public function Header() { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $w = $this->getPageWidth();
        $h = $this->getPageHeight();
        $this->SetDrawColor($this->accent[0], $this->accent[1], $this->accent[2]);
        $this->SetLineStyle(['width' => 0.3, 'dash' => 0, 'color' => $this->accent]);
        $this->Rect(5.3, 5.3, $w - 10.6, $h - 10.6);
        $this->Rect(6.1, 6.1, $w - 12.2, $h - 12.2);
        if ($this->watermark !== '') {
            $this->SetAlpha(0.14);
            $this->StartTransform();
            $this->Rotate(32, $w / 2, $h / 2);
            $this->SetFont('sourcesans3', 'B', 46);
            $this->SetTextColor(176, 32, 32);
            $this->SetXY(0, $h / 2 - 10);
            $this->Cell($w, 20, $this->watermark, 0, 0, 'C');
            $this->StopTransform();
            $this->SetAlpha(1);
        }
        $this->SetTextColor(27, 31, 39);
    }

    /**
     * No page footer (footers are part of the page content).
     */
    public function Footer() { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
    }
}
