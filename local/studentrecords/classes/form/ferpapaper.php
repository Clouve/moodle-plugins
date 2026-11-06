<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Record a FERPA notice acknowledgment given on paper.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class ferpapaper extends \moodleform {
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
        $m->addElement('static', 'year', get_string('acadyear', $p), \local_studentrecords\local\ferpa::current_year());
        $m->addElement('date_selector', 'acknowledged', get_string('ferpaackdate', $p));
        $this->add_action_buttons(true, get_string('recordpaperack', $p));
    }
}
