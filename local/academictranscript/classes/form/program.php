<?php

namespace local_academictranscript\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add or edit a program.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class program extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'local_academictranscript';
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'name', get_string('programname', $p), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('text', 'credential', get_string('credential', $p), ['size' => 60]);
        $mform->setType('credential', PARAM_TEXT);
        $mform->addHelpButton('credential', 'credential', $p);
        $unitmenu = \local_academictranscript\local\programs::unittype_menu();
        $mform->addElement('select', 'unittype', get_string('unittype', $p), $unitmenu);
        $mform->addHelpButton('unittype', 'unittype', $p);
        $mform->addElement('float', 'totalhours', get_string('totalhours', $p));
        $mform->setDefault('totalhours', '');
        $mform->addElement('selectyesno', 'active', get_string('active', $p));
        $mform->setDefault('active', 1);
        $this->add_action_buttons();
    }

    /**
     * Validation: unique name.
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
                'local_academictranscript_program',
                'name = ? AND id <> ?',
                [trim($data['name']), (int)$data['id']]
            )
        ) {
            $errors['name'] = get_string('err_exists', 'local_academictranscript');
        }
        return $errors;
    }
}
