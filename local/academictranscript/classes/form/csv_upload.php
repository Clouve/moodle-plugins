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
 * Upload a CSV file (from the templates).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_upload extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'local_academictranscript';
        $mform->addElement('hidden', 'step', $this->_customdata['step']);
        $mform->setType('step', PARAM_ALPHA);
        $mform->addElement('hidden', 'action', 'csv');
        $mform->setType('action', PARAM_ALPHA);
        $types = [];
        foreach ($this->_customdata['types'] as $type) {
            $types[$type] = get_string('itemtype_' . $type, $p);
        }
        $mform->addElement('select', 'itemtype', get_string('csvtype', $p), $types);
        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', $p), null, ['accepted_types' => ['.csv', '.txt']]);
        $mform->addRule('csvfile', null, 'required');
        $this->add_action_buttons(false, get_string('readcsv', $p));
    }
}
