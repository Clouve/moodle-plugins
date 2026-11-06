<?php

namespace local_academictranscript\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Request a change to a posted grade.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class change_request extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement(
            'static',
            'current',
            get_string('currentgrade', 'local_academictranscript'),
            s($this->_customdata['current'])
        );
        $mform->addElement(
            'select',
            'newgrade',
            get_string('newgrade', 'local_academictranscript'),
            $this->_customdata['letters'] ?? \local_academictranscript\local\scale::grade_menu()
        );
        // A change that goes to Student Records has no footnote: that plugin doesn't keep one.
        if (empty($this->_customdata['letters'])) {
            $mform->addElement(
                'text',
                'newnote',
                get_string('footnote', 'local_academictranscript'),
                ['size' => 50, 'maxlength' => 255]
            );
            $mform->setType('newnote', PARAM_TEXT);
        }
        $mform->addElement('text', 'reason', get_string('reason', 'local_academictranscript'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('submitchange', 'local_academictranscript'));
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
        if (trim($data['reason']) === '') {
            $errors['reason'] = get_string('required');
        }
        return $errors;
    }
}
