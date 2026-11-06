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

namespace theme_studentrecords\output;

use theme_studentrecords\local\brand;

/**
 * Renderer: the login form gets the school's logo, name and login page text.
 *
 * @package    theme_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_renderer extends \theme_boost\output\core_renderer {
    /**
     * Moodle's own login form for this version, with the school's name above it and the help text below it.
     *
     * @param \core_auth\output\login $form
     * @return string
     */
    public function render_login(\core_auth\output\login $form) {
        return $this->render_from_template('theme_studentrecords/loginform', [
            'srbrand' => brand::export(),
            'srwelcome' => trim((string)get_config('theme_studentrecords', 'loginwelcome')),
            'srhelp' => trim((string)get_config('theme_studentrecords', 'loginhelp')),
            'form' => parent::render_login($form),
        ]);
    }
}
