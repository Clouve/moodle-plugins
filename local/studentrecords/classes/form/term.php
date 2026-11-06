<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A term: dates and deadlines.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class term extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('text', 'name', get_string('termname', $p), ['size' => 40]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
        // Term dates are days in the school's time zone, whatever the time zone of the person editing them.
        $timezone = \local_studentrecords\local\catalog::timezone();
        foreach (['startdate', 'enddate'] as $f) {
            $m->addElement('date_selector', $f, get_string($f, $p), ['timezone' => $timezone]);
        }
        foreach (['adddropstart', 'adddropend', 'withdrawby', 'examstart', 'examend', 'gradefreeze'] as $f) {
            $m->addElement('date_selector', $f, get_string($f, $p), ['optional' => true, 'timezone' => $timezone]);
        }
        $m->addHelpButton('gradefreeze', 'gradefreeze', $p);
        $m->addHelpButton('withdrawby', 'withdrawby', $p);
        $m->addElement('textarea', 'notes', get_string('termnotes', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('notes', PARAM_TEXT);
        $m->addElement('advcheckbox', 'active', get_string('active', $p));
        $m->setDefault('active', 1);
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
        if ($data['enddate'] < $data['startdate']) {
            $errors['enddate'] = get_string('err_endbeforestart', 'local_studentrecords');
        }
        if (!empty($data['adddropend']) && ($data['adddropend'] < $data['startdate'] || $data['adddropend'] > $data['enddate'])) {
            $errors['adddropend'] = get_string('err_outsideterm', 'local_studentrecords');
        }
        if (!empty($data['withdrawby']) && ($data['withdrawby'] < $data['startdate'] || $data['withdrawby'] > $data['enddate'])) {
            $errors['withdrawby'] = get_string('err_outsideterm', 'local_studentrecords');
        }
        if (!empty($data['gradefreeze']) && $data['gradefreeze'] < $data['enddate']) {
            $errors['gradefreeze'] = get_string('err_freezebeforeend', 'local_studentrecords');
        }
        $other = $DB->get_field('local_studentrecords_term', 'id', ['name' => trim($data['name'])]);
        if ($other && $other != $data['id']) {
            $errors['name'] = get_string('err_duplicate', 'local_studentrecords');
        }
        return $errors;
    }
}
