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

namespace local_academictranscript\local;

/**
 * Option lists shared by the settings page and the setup wizard.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_menus {
    /**
     * Repeat rules.
     *
     * @return array
     */
    public static function repeatrules(): array {
        $p = 'local_academictranscript';
        return [calculator::REPEAT_LATEST => get_string('repeat_latest', $p),
            calculator::REPEAT_HIGHEST => get_string('repeat_highest', $p),
            calculator::REPEAT_ALL => get_string('repeat_all', $p)];
    }

    /**
     * Page sizes.
     *
     * @return array
     */
    public static function pagesizes(): array {
        $p = 'local_academictranscript';
        return ['LETTER' => get_string('pagesize_letter', $p), 'A4' => get_string('pagesize_a4', $p)];
    }

    /**
     * Signature modes.
     *
     * @return array
     */
    public static function signaturemodes(): array {
        $p = 'local_academictranscript';
        return ['lines' => get_string('signaturemode_lines', $p), 'images' => get_string('signaturemode_images', $p)];
    }
}
