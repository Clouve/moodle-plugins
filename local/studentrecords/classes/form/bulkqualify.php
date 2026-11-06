<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add or remove qualified courses for several faculty members at once (faculty roster).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class bulkqualify extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        global $DB;
        $m = $this->_form;
        $p = 'local_studentrecords';
        $courses = [];
        foreach ($DB->get_records('local_studentrecords_course', ['active' => 1], 'code', 'id, code, title') as $c) {
            $courses[$c->id] = s($c->code) . ' ' . format_string($c->title);
        }
        $m->addElement('header', 'h_bulk', get_string('bulkqualify', $p));
        $m->setExpanded('h_bulk', false);
        $m->addElement(
            'autocomplete',
            'facultyids',
            get_string('facultyrecords', $p),
            array_map('s', \local_studentrecords\local\catalog::faculty()),
            ['multiple' => true]
        );
        $m->addRule('facultyids', null, 'required', null, 'client');
        $m->addElement('autocomplete', 'courseids', get_string('qualifiedcourses', $p), $courses, ['multiple' => true]);
        $m->addRule('courseids', null, 'required', null, 'client');
        $m->addElement('select', 'bulkaction', get_string('action', $p), [
            'add' => get_string('addqualified', $p), 'remove' => get_string('removequalified', $p)]);
        $this->add_action_buttons(false, get_string('apply', $p));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (['facultyids', 'courseids'] as $f) {
            if (empty($data[$f])) {
                $errors[$f] = get_string('required');
            }
        }
        return $errors;
    }
}
