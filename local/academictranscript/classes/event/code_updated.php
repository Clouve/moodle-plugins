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
 * A hand-entered transcript code or note was changed. Keeps the history (old and new values).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class code_updated extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_code_updated', 'local_academictranscript');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        $o = $this->other;
        return "The user with id '$this->userid' changed the transcript entry for the user with id "
            . "'$this->relateduserid' in course '$this->courseid' from code '{$o['oldcode']}' / note '{$o['oldnote']}' "
            . "to code '{$o['newcode']}' / note '{$o['newnote']}'. Reason: '" . ($o['reason'] ?? '') . "'.";
    }

    /**
     * Validation.
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['oldcode', 'newcode', 'oldnote', 'newnote'] as $key) {
            if (!array_key_exists($key, $this->other)) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
        }
    }

    /**
     * Other mapping for restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
