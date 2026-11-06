<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Register a student in a course offering (with a staff override of date, capacity and prerequisite rules).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class register extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('autocomplete', 'offerid', get_string('offering', $p), $this->_customdata['offers']);
        $m->addRule('offerid', null, 'required', null, 'client');
        $m->addElement('advcheckbox', 'override', get_string('override', $p));
        $m->addHelpButton('override', 'override', $p);
        $m->addElement('text', 'note', get_string('overridenote', $p), ['size' => 60]);
        $m->setType('note', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('register', $p));
    }
}
