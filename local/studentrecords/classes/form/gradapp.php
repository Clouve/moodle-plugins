<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A graduation application.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class gradapp extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'part' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('select', 'termid', get_string('gradterm', $p), [0 => get_string('none')] +
            array_map('format_string', \local_studentrecords\local\catalog::terms()));
        $m->addElement('text', 'note', get_string('notes', $p), ['size' => 60]);
        $m->setType('note', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('applygraduation', $p));
    }
}
