<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Drop or withdraw a registration (the Moodle enrolment is suspended, never removed).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class endreg extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA, 'reg' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('static', 'what', '', $this->_customdata['text']);
        $m->addElement('advcheckbox', 'override', get_string('override', $p));
        $m->addHelpButton('override', 'override', $p);
        $m->addElement('text', 'note', get_string('overridenote', $p), ['size' => 60]);
        $m->setType('note', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('action_' . $this->_customdata['action'], $p));
    }
}
