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
 * Plain text for names.
 *
 * Names (courses, programs, marks) are stored and passed on as plain text, and whatever prints a name escapes it once.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text {
    /**
     * A name as plain text: filters applied, tags removed, and no HTML entities, so an ampersand is "&".
     *
     * The escape option of format_string() is not enough. While the site setting "formatstringstriptags" is off, Moodle
     * ignores that option, runs its HTML cleaner, and gives "&amp;" and the tags that are safe. Taking out the tags and
     * decoding the entities afterwards gives the same plain text with the setting on or off.
     *
     * The result can hold "<", ">" and "&" as plain characters, so it must be escaped where it is shown.
     *
     * @param string $text The name as it was typed
     * @param \context $context The context for the filters: the course for the name of a course, else the system
     * @return string
     */
    public static function plain(string $text, \context $context): string {
        $formatted = format_string($text, true, ['context' => $context, 'escape' => false]);
        return html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
