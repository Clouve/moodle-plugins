<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Place a hold on a student.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class hold extends \moodleform {
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
        $types = [];
        foreach (\local_studentrecords\local\academic::HOLDTYPES as $t) {
            $types[$t] = get_string('holdtype_' . $t, $p);
        }
        $m->addElement('select', 'holdtype', get_string('holdtype', $p), $types);
        $m->addElement('textarea', 'reason', get_string('holdreason', $p), ['rows' => 3, 'cols' => 60]);
        $m->setType('reason', PARAM_TEXT);
        $m->addRule('reason', null, 'required', null, 'client');
        $m->addElement('selectyesno', 'blocksreg', get_string('holdblocks', $p));
        $m->setDefault('blocksreg', 1);
        $timezone = \local_studentrecords\local\catalog::timezone();
        $m->addElement('date_selector', 'startdate', get_string('holdfrom', $p), ['timezone' => $timezone]);
        $m->addElement('date_selector', 'enddate', get_string('holdend', $p), ['optional' => true, 'timezone' => $timezone]);
        $m->addHelpButton('enddate', 'holdend', $p);
        $this->add_action_buttons(true, get_string('placehold', $p));
    }
}
