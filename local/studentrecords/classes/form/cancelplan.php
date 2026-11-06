<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Cancel a student's payment plan, with a reason (the plan is kept).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class cancelplan extends \moodleform {
    /**
     * Form definition. Custom data: text (the plan).
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA, 'item' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('static', 'plan', get_string('paymentplan', $p), $this->_customdata['text']);
        $m->addElement('textarea', 'reason', get_string('reasonnote', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('reason', PARAM_TEXT);
        $m->addRule('reason', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('cancelplan', $p));
    }
}
