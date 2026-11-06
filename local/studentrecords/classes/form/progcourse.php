<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add a course to a program outline.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class progcourse extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        global $DB;
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'action', 'addcourse');
        $m->setType('action', PARAM_ALPHA);
        $courses = [];
        foreach ($DB->get_records('local_studentrecords_course', ['active' => 1], 'code', 'id, code, title') as $c) {
            $courses[$c->id] = s($c->code) . ' ' . format_string($c->title);
        }
        $m->addElement('autocomplete', 'courseid', get_string('course'), $courses);
        $m->addRule('courseid', null, 'required', null, 'client');
        $m->addElement('text', 'groupname', get_string('groupname', $p), ['size' => 50]);
        $m->setType('groupname', PARAM_TEXT);
        $m->addElement('select', 'requirement', get_string('requirement', $p), [
            'required' => get_string('req_required', $p), 'track' => get_string('req_track', $p),
            'elective' => get_string('req_elective', $p)]);
        $this->add_action_buttons(false, get_string('addtoprogram', $p));
    }
}
