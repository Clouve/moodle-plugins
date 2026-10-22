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
 * Uninstall: let go of the course fields and delete the signing key.
 *
 * Moodle drops the tables, settings and stored files of the plugin on its own.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Uninstall hook. Keeps the course codes, hours and hour types saved on courses, but frees their fields. Deletes the Term
 * field and the folder in moodledata that holds the self-signed signing key. Safe to run again.
 *
 * The folder is deleted in place, with fulldelete(). remove_dir() renames the folder to a temporary name first, and when
 * it cannot delete every file, the private key stays in a folder with a name that nobody can guess. fulldelete() leaves
 * what it could not delete in the folder, under its own name, where an admin can delete it by hand.
 *
 * @return bool whether the folder in moodledata is gone
 */
function xmldb_local_academictranscript_uninstall(): bool {
    global $CFG;

    require_once($CFG->libdir . '/filelib.php');

    \local_academictranscript\local\coursefields::release();
    return fulldelete($CFG->dataroot . '/local_academictranscript');
}
