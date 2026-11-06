<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * One row of a student's prior education.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class educ extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'part' => PARAM_ALPHA, 'item' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('text', 'school', get_string('educ_school', $p), ['size' => 60]);
        $m->setType('school', PARAM_TEXT);
        $m->addRule('school', null, 'required', null, 'client');
        $m->addElement('text', 'credential', get_string('educ_credential', $p), ['size' => 60]);
        $m->setType('credential', PARAM_TEXT);
        $m->addElement('date_selector', 'completed', get_string('educ_completed', $p), ['optional' => true,
            'startyear' => 1950]);
        $m->addElement('select', 'country', get_string('country'), ['' => get_string('none')] +
            get_string_manager()->get_list_of_countries());
        $m->addElement('text', 'notes', get_string('notes', $p), ['size' => 60]);
        $m->setType('notes', PARAM_TEXT);
        if (!empty($this->_customdata['item'])) {
            $m->addElement('advcheckbox', 'active', get_string('keepshown', $p));
            $m->addHelpButton('active', 'keepshown', $p);
        }
        $this->add_action_buttons();
    }
}
