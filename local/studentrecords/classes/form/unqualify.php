<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Remove qualifications from a faculty member (kept as removed, with date and who).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class unqualify extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'action', 'unqualify');
        $m->setType('action', PARAM_ALPHA);
        $m->addElement(
            'autocomplete',
            'removeids',
            get_string('removequalified', $p),
            $this->_customdata['courses'],
            ['multiple' => true]
        );
        $this->add_action_buttons(false, get_string('removequalified', $p));
    }
}
