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
 * Setup wizard step 1: school details.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup_school extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'local_academictranscript';
        $mform->addElement('hidden', 'step', 'school');
        $mform->setType('step', PARAM_ALPHA);
        $mform->addElement('text', 'schoolname', get_string('schoolname', $p), ['size' => 60]);
        $mform->setType('schoolname', PARAM_TEXT);
        $mform->addRule('schoolname', null, 'required', null, 'client');
        $mform->addElement('textarea', 'address', get_string('address', $p), ['rows' => 3, 'cols' => 50]);
        $mform->setType('address', PARAM_TEXT);
        foreach (['phone' => 30, 'email' => 40, 'website' => 60, 'registrarname' => 40, 'registrartitle' => 40] as $name => $size) {
            $mform->addElement('text', $name, get_string($name === 'email' ? 'registraremail' : $name, $p), ['size' => $size]);
            $mform->setType($name, PARAM_TEXT);
        }
        $mform->addElement(
            'filemanager',
            'logo',
            get_string('logo', $p),
            null,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg'], 'subdirs' => 0]
        );
        $this->add_action_buttons(false, get_string('saveandnext', $p));
    }
}
