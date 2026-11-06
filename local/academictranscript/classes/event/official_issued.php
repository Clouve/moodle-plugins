<?php

namespace local_academictranscript\event;

/**
 * An Official transcript was issued.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
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
