<?php

namespace local_academictranscript\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload grading policy or catalog documents to be read by the school's AI provider.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class document_upload extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'local_academictranscript';
        $mform->addElement('hidden', 'step', $this->_customdata['step']);
        $mform->setType('step', PARAM_ALPHA);
        $mform->addElement('hidden', 'action', 'documents');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement(
            'filemanager',
            'documents',
            get_string('documents', $p),
            null,
            ['maxfiles' => 5, 'subdirs' => 0, 'maxbytes' => 20 * 1024 * 1024,
            'accepted_types' => \local_academictranscript\local\import\document_text::ACCEPTED]
        );
        $mform->addRule('documents', null, 'required');
        if (!empty($this->_customdata['needpolicy'])) {
            $mform->addElement('advcheckbox', 'acceptpolicy', get_string('ai_policy', $p), get_string('ai_policy_accept', $p));
            $mform->addRule('acceptpolicy', get_string('required'), 'required', null, 'client');
        }
        $this->add_action_buttons(false, get_string('readdocuments', $p));
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
        if (!empty($this->_customdata['needpolicy']) && empty($data['acceptpolicy'])) {
            $errors['acceptpolicy'] = get_string('required');
        }
        return $errors;
    }
}
