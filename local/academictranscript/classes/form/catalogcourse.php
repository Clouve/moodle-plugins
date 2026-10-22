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
 * Add or edit a catalog course.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalogcourse extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'local_academictranscript';
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'code', get_string('col_course', $p), ['size' => 15]);
        $mform->setType('code', PARAM_TEXT);
        $mform->addRule('code', null, 'required', null, 'client');
        $mform->addElement('text', 'title', get_string('col_title', $p), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addElement('float', 'hours', get_string('hours', $p));
        $mform->addRule('hours', null, 'required', null, 'client');
        $unitmenu = \local_academictranscript\local\programs::unittype_menu();
        $mform->addElement('select', 'unittype', get_string('unittype', $p), $unitmenu);
        $mform->addElement('select', 'programid', get_string('program', $p), \local_academictranscript\local\programs::menu());
        $mform->addElement('selectyesno', 'active', get_string('active', $p));
        $mform->setDefault('active', 1);
        $this->add_action_buttons();
    }

    /**
     * Validation: unique code, sensible hours.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if (
            $DB->record_exists_select(
                'local_academictranscript_catalog',
                'code = ? AND id <> ?',
                [trim($data['code']), (int)$data['id']]
            )
        ) {
            $errors['code'] = get_string('err_exists', 'local_academictranscript');
        }
        if ($data['hours'] < 0 || $data['hours'] > 5000) {
            $errors['hours'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }
}
