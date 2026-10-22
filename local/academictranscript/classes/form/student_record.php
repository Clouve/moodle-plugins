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
 * Student details printed on the transcript. There is deliberately no SSN field.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_record extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('text', 'studentid', get_string('studentid', 'local_academictranscript'), ['size' => 30]);
        $mform->setType('studentid', PARAM_TEXT);
        $mform->addHelpButton('studentid', 'studentid', 'local_academictranscript');

        $dateopts = ['optional' => true, 'startyear' => 1930, 'stopyear' => (int)date('Y') + 2];
        $mform->addElement('date_selector', 'dob', get_string('dob', 'local_academictranscript'), $dateopts);

        $mform->addElement(
            'select',
            'programid',
            get_string('program', 'local_academictranscript'),
            \local_academictranscript\local\programs::menu()
        );
        $mform->setType('programid', PARAM_INT);
        $mform->addHelpButton('programid', 'program', 'local_academictranscript');

        $mform->addElement(
            'select',
            'admitterm',
            get_string('admitterm', 'local_academictranscript'),
            \local_academictranscript\local\terms::menu()
        );
        $mform->setType('admitterm', PARAM_INT);

        $statuses = ['' => get_string('choosedots')];
        foreach (\local_academictranscript\local\transcript::STATUSES as $status) {
            $statuses[$status] = get_string('status_' . $status, 'local_academictranscript');
        }
        $mform->addElement('select', 'status', get_string('status', 'local_academictranscript'), $statuses);
        $mform->setType('status', PARAM_ALPHA);

        $mform->addElement('text', 'qualification', get_string('qualification', 'local_academictranscript'), ['size' => 60]);
        $mform->setType('qualification', PARAM_TEXT);

        $mform->addElement('date_selector', 'awarddate', get_string('awarddate', 'local_academictranscript'), $dateopts);

        $this->add_action_buttons();
    }
}
