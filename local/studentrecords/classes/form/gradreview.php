<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Review, confer or cancel a graduation application.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class gradreview extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $action = $this->_customdata['action'];
        foreach (['app' => PARAM_INT, 'action' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('static', 'what', '', $this->_customdata['text']);
        if ($action === 'review') {
            $m->addElement('select', 'decision', get_string('decision', $p), ['approve' => get_string('approve', $p),
                'deny' => get_string('reject', $p)]);
            $m->addElement('text', 'note', get_string('notes', $p), ['size' => 60]);
            $m->setType('note', PARAM_TEXT);
            $m->addHelpButton('note', 'gradreviewnote', $p);
        } else if ($action === 'confer') {
            $m->addElement(
                'date_selector',
                'conferred',
                get_string('conferraldate', $p),
                ['timezone' => \local_studentrecords\local\catalog::timezone()]
            );
            $m->addElement('text', 'credential', get_string('prog_credential', $p), ['size' => 60]);
            $m->setType('credential', PARAM_TEXT);
            $m->addRule('credential', null, 'required', null, 'client');
            $m->addElement('text', 'honors', get_string('honors', $p), ['size' => 40]);
            $m->setType('honors', PARAM_TEXT);
            $m->addHelpButton('honors', 'honors', $p);
        } else {
            $m->addElement('text', 'note', get_string('notes', $p), ['size' => 60]);
            $m->setType('note', PARAM_TEXT);
            $m->addRule('note', null, 'required', null, 'client');
        }
        $this->add_action_buttons(true, get_string('gradaction_' . $action, $p));
    }
}
