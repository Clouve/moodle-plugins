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
 * Request a change to a posted grade.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
            \local_academictranscript\local\scale::grade_menu()
        );
        $mform->addElement(
            'text',
            'newnote',
            get_string('footnote', 'local_academictranscript'),
            ['size' => 50, 'maxlength' => 255]
        );
        $mform->setType('newnote', PARAM_TEXT);
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
