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
 * Add or edit a term.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class term extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'name', get_string('termname', 'local_academictranscript'), ['size' => 30, 'maxlength' => 100]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('date_selector', 'startdate', get_string('termstart', 'local_academictranscript'));
        $mform->addElement('date_selector', 'enddate', get_string('termend', 'local_academictranscript'));
        $this->add_action_buttons();
    }

    /**
     * Validation: unique name, end after start.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $name = trim($data['name']);
        if ($name === '') {
            $errors['name'] = get_string('required');
        } else if ($DB->record_exists_select('local_academictranscript_term', 'name = ? AND id <> ?', [$name, (int)$data['id']])) {
            $errors['name'] = get_string('termexists', 'local_academictranscript');
        } else if (strpos($name, "\n") !== false) {
            $errors['name'] = get_string('invaliddata', 'error');
        }
        if ($data['enddate'] <= $data['startdate']) {
            $errors['enddate'] = get_string('termendbeforestart', 'local_academictranscript');
        }
        return $errors;
    }
}
