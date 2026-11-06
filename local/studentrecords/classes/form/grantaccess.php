<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Give course access now, before the payment rule is met, with a reason.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class grantaccess extends \moodleform {
    /**
     * Form definition. Custom data: text (the course).
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA, 'item' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('static', 'course', get_string('hist_course', $p), $this->_customdata['text']);
        $m->addElement('textarea', 'reason', get_string('reasonnote', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('reason', PARAM_TEXT);
        $m->addRule('reason', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('giveaccess', $p));
    }
}
