<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Figure a proposed refund again on another date (for example the last date of attendance).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class refigure extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'refigure' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        // The school's time zone: the day decides which refund rule fits.
        $m->addElement('date_selector', 'eventdate', get_string('refunddate', $p), [
            'timezone' => \local_studentrecords\local\catalog::timezone(),
        ]);
        $m->addHelpButton('eventdate', 'refunddate', $p);
        $this->add_action_buttons(false, get_string('refigure', $p));
    }
}
