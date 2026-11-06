<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Mark a faculty member as qualified to teach catalog courses.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class qualify extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        global $DB;
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'action', 'qualify');
        $m->setType('action', PARAM_ALPHA);
        $courses = [];
        foreach ($DB->get_records('local_studentrecords_course', ['active' => 1], 'code', 'id, code, title') as $c) {
            $courses[$c->id] = s($c->code) . ' ' . format_string($c->title);
        }
        $m->addElement('autocomplete', 'courseids', get_string('qualifiedcourses', $p), $courses, ['multiple' => true]);
        $this->add_action_buttons(false, get_string('addqualified', $p));
    }
}
