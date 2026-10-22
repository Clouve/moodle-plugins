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

/**
 * Install: create the course fields, load the standard grading scale and marks, and mark the setup as not done.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Install hook. Safe to run again: it only adds what is missing and never changes data that is there. The one exception
 * is a course field that an uninstall kept: it gets its required, unique and locked settings back.
 *
 * @return bool
 */
function xmldb_local_academictranscript_install(): bool {
    \local_academictranscript\local\coursefields::ensure();
    \local_academictranscript\local\scale::load_defaults();
    if (get_config('local_academictranscript', 'setupcomplete') === false) {
        set_config('setupcomplete', 0, 'local_academictranscript');
    }
    return true;
}
