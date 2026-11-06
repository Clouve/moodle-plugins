<?php

namespace local_studentrecords\form;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Billing settings: on or off, text on statements, how to pay other than online.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class billingsettings extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('selectyesno', 'billing', get_string('set_billing', $p));
        $m->addHelpButton('billing', 'set_billing', $p);
        $m->addElement('static', 'currency', get_string('currency'), get_string('currencyusd', $p));
        $rules = [];
        foreach (\local_studentrecords\local\payments::ACCESS as $rule) {
            $rules[$rule] = get_string('access_' . $rule, $p);
        }
        $m->addElement('select', 'billaccess', get_string('set_billaccess', $p), $rules);
        $m->addHelpButton('billaccess', 'set_billaccess', $p);
        $accounts = [0 => get_string('none')] + \core_payment\helper::get_payment_accounts_menu(\context_system::instance());
        $m->addElement('select', 'paymentaccount', get_string('set_paymentaccount', $p), $accounts);
        $m->addHelpButton('paymentaccount', 'set_paymentaccount', $p);
        $m->addElement('text', 'reminderdays', get_string('set_reminderdays', $p), ['size' => 4]);
        $m->setType('reminderdays', PARAM_INT);
        $m->addHelpButton('reminderdays', 'set_reminderdays', $p);
        $m->addElement('text', 'refunddays', get_string('set_refunddays', $p), ['size' => 4]);
        $m->setType('refunddays', PARAM_INT);
        $m->addHelpButton('refunddays', 'set_refunddays', $p);
        $m->addElement('text', 'halftime', get_string('set_halftime', $p), ['size' => 4]);
        $m->setType('halftime', PARAM_RAW_TRIMMED);
        $m->addHelpButton('halftime', 'set_halftime', $p);
        $levels = $m->addElement(
            'select',
            'gradlevels',
            get_string('set_gradlevels', $p),
            array_map('format_string', \local_studentrecords\local\catalog::levels())
        );
        $levels->setMultiple(true);
        $m->addHelpButton('gradlevels', 'set_gradlevels', $p);
        $m->addElement('textarea', 'payinfo', get_string('set_payinfo', $p), ['rows' => 3, 'cols' => 60]);
        $m->setType('payinfo', PARAM_TEXT);
        $m->addHelpButton('payinfo', 'set_payinfo', $p);
        $m->addElement('textarea', 'statementnote', get_string('set_statementnote', $p), ['rows' => 3, 'cols' => 60]);
        $m->setType('statementnote', PARAM_TEXT);
        $this->add_action_buttons(false);
    }
}
