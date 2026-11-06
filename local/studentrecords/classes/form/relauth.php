<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A release authorization: who may receive which information of the student, from / to which dates.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class relauth extends \moodleform {
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
        $m->addElement('text', 'recipient', get_string('relrecipient', $p), ['size' => 60]);
        $m->setType('recipient', PARAM_TEXT);
        $m->addRule('recipient', null, 'required', null, 'client');
        $m->addElement('text', 'relationship', get_string('emerg_relationship', $p), ['size' => 30]);
        $m->setType('relationship', PARAM_TEXT);
        $scopes = [];
        foreach (\local_studentrecords\local\ferpa::SCOPES as $s) {
            $scopes[$s] = get_string('relscope_' . $s, $p);
        }
        $m->addElement('autocomplete', 'scope', get_string('relscope', $p), $scopes, ['multiple' => true]);
        $m->addRule('scope', null, 'required', null, 'client');
        $m->addElement('text', 'details', get_string('reldetails', $p), ['size' => 60]);
        $m->setType('details', PARAM_TEXT);
        $m->addElement('date_selector', 'fromdate', get_string('holdfrom', $p));
        $m->addElement('date_selector', 'todate', get_string('reltodate', $p), ['optional' => true]);
        $this->add_action_buttons(true, get_string('addrelease', $p));
    }
}
