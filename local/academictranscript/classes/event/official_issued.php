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

namespace local_academictranscript\event;

/**
 * An Official transcript was issued.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class official_issued extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_academictranscript_issue';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_official_issued', 'local_academictranscript');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' issued Official transcript id '$this->objectid' "
            . "for the user with id '$this->relateduserid'.";
    }

    /**
     * Object id mapping for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_academictranscript_issue', 'restore' => \core\event\base::NOT_MAPPED];
    }
}
