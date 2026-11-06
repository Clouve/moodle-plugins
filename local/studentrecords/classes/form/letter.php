<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Issue an enrollment verification letter.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class letter extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('text', 'recipient', get_string('letterrecipient', $p), ['size' => 60]);
        $m->setType('recipient', PARAM_TEXT);
        $m->addHelpButton('recipient', 'letterrecipient', $p);
        $this->add_action_buttons(true, get_string('issueletter', $p));
    }
}
