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

namespace theme_studentrecords\admin;

use theme_studentrecords\local\brand;

/**
 * Colour picker that only saves colours with enough contrast against white (WCAG 2.1 AA).
 *
 * @package    theme_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_accentcolour extends \admin_setting_configcolourpicker {
    /**
     * Save the colour only if it is #rrggbb (or #rgb) and has a contrast of at least 4.5 to 1 with white.
     *
     * @param string $data
     * @return string Empty string when saved, or the error message.
     */
    public function write_setting($data) {
        $data = strtolower(trim((string)$data));
        if ($data === '') {
            $data = brand::DEFAULT_ACCENT;
        }
        if (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/', $data, $m)) {
            $data = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        } else if (preg_match('/^[0-9a-f]{6}$/', $data)) {
            $data = '#' . $data;
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $data)) {
            return get_string('accentcolor_format', 'theme_studentrecords');
        }
        $ratio = brand::contrast($data, '#ffffff');
        if ($ratio < brand::MIN_CONTRAST) {
            // Round down, so a colour just under the limit is never reported as meeting it.
            return get_string('accentcolor_lowcontrast', 'theme_studentrecords', format_float(floor($ratio * 100) / 100, 2));
        }
        return ($this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin'));
    }
}
