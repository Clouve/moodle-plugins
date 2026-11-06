<?php

namespace local_academictranscript\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add or edit one transfer credit course.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class transfer extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        foreach (['id', 'userid'] as $hidden) {
            $mform->addElement('hidden', $hidden);
            $mform->setType($hidden, PARAM_INT);
        }
        $mform->addElement('text', 'institution', get_string('institution', 'local_academictranscript'), ['size' => 50]);
        $mform->setType('institution', PARAM_TEXT);
        $mform->addRule('institution', null, 'required', null, 'client');
        $mform->addElement('text', 'coursecode', get_string('col_course', 'local_academictranscript'), ['size' => 15]);
        $mform->setType('coursecode', PARAM_TEXT);
        $mform->addElement('text', 'title', get_string('col_title', 'local_academictranscript'), ['size' => 50]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addElement('float', 'hours', get_string('hours', 'local_academictranscript'));
        $mform->addRule('hours', null, 'required', null, 'client');
        $mform->addElement(
            'date_selector',
            'dateaccepted',
            get_string('dateaccepted', 'local_academictranscript'),
            ['optional' => true]
        );
        $mform->addElement('text', 'note', get_string('footnote', 'local_academictranscript'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('note', PARAM_TEXT);
        $mform->addElement('selectyesno', 'active', get_string('showontranscript', 'local_academictranscript'));
        $mform->setDefault('active', 1);
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
        $errors = parent::validation($data, $files);
        if ($data['hours'] < 0 || $data['hours'] > 5000) {
            $errors['hours'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }
}
