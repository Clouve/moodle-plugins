<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A course offering: catalog course in a term, section, instructor, capacity and linked Moodle course.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class offer extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        global $DB;
        $m = $this->_form;
        $p = 'local_studentrecords';
        $catalog = \local_studentrecords\local\catalog::class;
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('select', 'termid', get_string('term', $p), array_map('format_string', $catalog::terms()));
        $courses = [];
        foreach ($DB->get_records('local_studentrecords_course', ['active' => 1], 'code', 'id, code, title') as $c) {
            $courses[$c->id] = s($c->code) . ' ' . format_string($c->title);
        }
        $m->addElement('autocomplete', 'courseid', get_string('hist_course', $p), $courses);
        $m->addRule('courseid', null, 'required', null, 'client');
        $m->addElement('text', 'section', get_string('section', $p), ['size' => 6]);
        $m->setType('section', PARAM_TEXT);
        $m->addRule('section', get_string('maximumchars', '', 20), 'maxlength', 20, 'server');
        $m->setDefault('section', '01');
        $m->addRule('section', null, 'required', null, 'client');
        $teachers = array_map('s', $catalog::faculty());
        $m->addElement('select', 'facultyid', get_string('instructor', $p), [0 => get_string('none')] + $teachers);
        $m->addHelpButton('facultyid', 'instructor', $p);
        $m->addElement('advcheckbox', 'notqualifiedok', get_string('notqualifiedok', $p));
        $m->hideIf('notqualifiedok', 'facultyid', 'eq', 0);
        $m->addElement('text', 'capacity', get_string('capacity', $p), ['size' => 5]);
        $m->setType('capacity', PARAM_RAW_TRIMMED);
        $m->addHelpButton('capacity', 'capacity', $p);
        $moodle = [0 => get_string('none')];
        foreach ($DB->get_records_select('course', 'id <> ?', [SITEID], 'shortname', 'id, shortname, fullname') as $c) {
            $moodle[$c->id] = format_string($c->shortname) . ' - ' . format_string($c->fullname);
        }
        $m->addElement('select', 'moodlecourseid', get_string('moodlecourse', $p), $moodle);
        $m->addHelpButton('moodlecourseid', 'moodlecourse', $p);
        $statuses = [];
        foreach (\local_studentrecords\local\registration::OFFERSTATUSES as $st) {
            $statuses[$st] = get_string('offerstatus_' . $st, $p);
        }
        $days = [];
        foreach (\local_studentrecords\local\selfreg::DAYS as $d) {
            $days[] = $m->createElement('advcheckbox', 'day_' . $d, '', get_string($d, 'calendar'));
        }
        $m->addGroup($days, 'meetdaysgroup', get_string('meetdays', $p), ' ', false);
        $m->addHelpButton('meetdaysgroup', 'meetdays', $p);
        $m->addElement('text', 'starttime', get_string('starttime', $p), ['size' => 6, 'placeholder' => '09:00']);
        $m->setType('starttime', PARAM_RAW_TRIMMED);
        $m->addElement('text', 'endtime', get_string('endtime', $p), ['size' => 6, 'placeholder' => '11:30']);
        $m->setType('endtime', PARAM_RAW_TRIMMED);
        $m->addElement('text', 'room', get_string('room', $p), ['size' => 20]);
        $m->setType('room', PARAM_TEXT);
        $m->addRule('room', get_string('maximumchars', '', 50), 'maxlength', 50, 'server');
        $m->addHelpButton('room', 'room', $p);
        $m->addElement('select', 'status', get_string('status', $p), $statuses);
        $m->addElement('text', 'notes', get_string('notes', $p), ['size' => 60]);
        $m->setType('notes', PARAM_TEXT);
        $this->add_action_buttons();
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if ($data['capacity'] !== '' && (!ctype_digit((string)$data['capacity']) || (int)$data['capacity'] < 1)) {
            $errors['capacity'] = get_string('err_capacity', 'local_studentrecords');
        }
        $other = $DB->get_field('local_studentrecords_offer', 'id', ['termid' => $data['termid'],
            'courseid' => (int)$data['courseid'], 'section' => trim($data['section'])]);
        if ($other && $other != $data['id']) {
            $errors['section'] = get_string('err_duplicate', 'local_studentrecords');
        }
        $selfreg = \local_studentrecords\local\selfreg::class;
        foreach (['starttime', 'endtime'] as $f) {
            if (($data[$f] ?? '') !== '' && !$selfreg::valid_time($data[$f])) {
                $errors[$f] = get_string('err_time', 'local_studentrecords');
            }
        }
        if (
            empty($errors['starttime']) && empty($errors['endtime']) && ($data['starttime'] ?? '') !== '' &&
                ($data['endtime'] ?? '') !== '' && $data['endtime'] <= $data['starttime']
        ) {
            $errors['endtime'] = get_string('err_endtime', 'local_studentrecords');
        }
        if (
            !empty($data['facultyid']) && !empty($data['courseid']) &&
                !\local_studentrecords\local\faculty::is_qualified((int)$data['facultyid'], (int)$data['courseid']) &&
                empty($data['notqualifiedok'])
        ) {
            $errors['facultyid'] = get_string('err_notqualified', 'local_studentrecords');
        }
        return $errors;
    }
}
