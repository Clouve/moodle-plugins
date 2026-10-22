<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_academictranscript\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload grading policy or catalog documents to be read by the school's AI provider.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
