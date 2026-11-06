<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A faculty or staff record.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class faculty extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('select', 'stafftype', get_string('stafftype', $p), ['faculty' => get_string('type_faculty', $p),
            'staff' => get_string('type_staff', $p)]);
        $m->addElement('autocomplete', 'userid', get_string('moodleaccount', $p), $this->_customdata['usermenu'] ?? [], [
            'ajax' => 'core_user/form_user_selector', 'multiple' => false]);
        $m->addHelpButton('userid', 'moodleaccount', $p);
        foreach (['honorific' => 10, 'firstname' => 40, 'lastname' => 40, 'jobtitle' => 60, 'department' => 60] as $f => $size) {
            $m->addElement('text', $f, get_string('fac_' . $f, $p), ['size' => $size]);
            $m->setType($f, PARAM_TEXT);
        }
        $m->addRule('firstname', null, 'required', null, 'client');
        $m->addRule('lastname', null, 'required', null, 'client');
        $m->addElement('text', 'email', get_string('email'), ['size' => 40]);
        $m->setType('email', PARAM_EMAIL);
        $m->addElement('text', 'phone', get_string('phone', $p), ['size' => 20]);
        $m->setType('phone', PARAM_TEXT);
        $m->addElement('select', 'employment', get_string('employment', $p), ['' => get_string('none'),
            'fulltime' => get_string('load_fulltime', $p), 'parttime' => get_string('load_parttime', $p)]);
        $m->addElement('text', 'officehours', get_string('officehours', $p), ['size' => 60]);
        $m->setType('officehours', PARAM_TEXT);
        $m->addElement('advcheckbox', 'active', get_string('active', $p));
        $m->setDefault('active', 1);
        $m->addElement('advcheckbox', 'nolms', get_string('nolms', $p));
        $m->addHelpButton('nolms', 'nolms', $p);
        $m->addElement('textarea', 'notes', get_string('notes', $p), ['rows' => 3, 'cols' => 60]);
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
        if (!empty($data['userid'])) {
            $other = $DB->get_field('local_studentrecords_faculty', 'id', ['userid' => $data['userid']], IGNORE_MULTIPLE);
            if ($other && $other != $data['id']) {
                $errors['userid'] = get_string('err_hasrecord', 'local_studentrecords');
            }
        }
        return $errors;
    }
}
