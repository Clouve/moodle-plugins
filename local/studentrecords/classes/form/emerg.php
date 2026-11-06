<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * One emergency contact of a student.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class emerg extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'part' => PARAM_ALPHA, 'item' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('text', 'name', get_string('name'), ['size' => 60]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
        $m->addElement('text', 'relationship', get_string('emerg_relationship', $p), ['size' => 30]);
        $m->setType('relationship', PARAM_TEXT);
        $m->addElement('text', 'phone', get_string('phone', $p), ['size' => 20]);
        $m->setType('phone', PARAM_TEXT);
        $m->addElement('text', 'email', get_string('email'), ['size' => 40]);
        $m->setType('email', PARAM_EMAIL);
        $m->addElement('text', 'notes', get_string('notes', $p), ['size' => 60]);
        $m->setType('notes', PARAM_TEXT);
        if (!empty($this->_customdata['item'])) {
            $m->addElement('advcheckbox', 'active', get_string('keepshown', $p));
            $m->addHelpButton('active', 'keepshown', $p);
        }
        $this->add_action_buttons();
    }

    /**
     * Validation: a phone number or an email is needed.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim((string)$data['phone']) === '' && trim((string)$data['email']) === '') {
            $errors['phone'] = get_string('err_phoneoremail', 'local_studentrecords');
        }
        if (!empty($data['email']) && !validate_email($data['email'])) {
            $errors['email'] = get_string('invalidemail');
        }
        return $errors;
    }
}
