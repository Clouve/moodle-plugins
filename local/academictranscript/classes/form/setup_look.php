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

use local_academictranscript\local\settings_menus;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Setup wizard step 5: how the transcript looks and calculates.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup_look extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'local_academictranscript';
        $mform->addElement('hidden', 'step', 'look');
        $mform->setType('step', PARAM_ALPHA);
        $mform->addElement('select', 'pagesize', get_string('pagesize', $p), settings_menus::pagesizes());
        $mform->addElement('text', 'accentcolor', get_string('accentcolor', $p), ['size' => 8]);
        $mform->setType('accentcolor', PARAM_TEXT);
        $mform->addHelpButton('accentcolor', 'accentcolor', $p);
        $mform->addElement('select', 'signaturemode', get_string('signaturemode', $p), settings_menus::signaturemodes());
        $mform->addElement('advcheckbox', 'roundpercent', get_string('roundpercent', $p), get_string('roundpercent_desc', $p));
        $mform->addElement('select', 'repeatrule', get_string('repeatrule', $p), settings_menus::repeatrules());
        $mform->addElement('text', 'creditlabel', get_string('creditlabel', $p), ['size' => 30]);
        $mform->setType('creditlabel', PARAM_TEXT);
        $mform->addElement('text', 'clocklabel', get_string('clocklabel', $p), ['size' => 30]);
        $mform->setType('clocklabel', PARAM_TEXT);
        $this->add_action_buttons(false, get_string('saveandnext', $p));
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
        if (!preg_match('/^#?[0-9a-fA-F]{6}$/', trim($data['accentcolor']))) {
            $errors['accentcolor'] = get_string('err_color', 'local_academictranscript');
        }
        return $errors;
    }
}
