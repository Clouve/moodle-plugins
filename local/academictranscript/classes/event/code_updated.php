<?php

namespace local_academictranscript\event;

/**
 * A hand-entered transcript code or note was changed. Keeps the history (old and new values).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
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
