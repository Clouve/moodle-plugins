<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\refunds;

/**
 * Record money paid back to a student (by check, bank transfer, on the payment provider's site ...).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class payout extends \moodleform {
    /**
     * Form definition. Custom data: owed.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('static', 'owed', get_string('schoolowes', $p), billing::money($this->_customdata['owed']));
        $methods = [];
        foreach (refunds::PAYOUTS as $k) {
            $methods[$k] = get_string('payout_' . $k, $p);
        }
        $m->addElement('select', 'method', get_string('payoutmethod', $p), $methods);
        $m->addHelpButton('method', 'payoutmethod', $p);
        $m->addElement('text', 'amount', get_string('amountusd', $p), ['size' => 12]);
        $m->setType('amount', PARAM_RAW_TRIMMED);
        $m->addRule('amount', null, 'required', null, 'client');
        $m->addElement('date_selector', 'txndate', get_string('linedate', $p), [
            'timezone' => \local_studentrecords\local\catalog::timezone(),
        ]);
        $m->addElement('text', 'reference', get_string('payreference', $p), ['size' => 40]);
        $m->setType('reference', PARAM_TEXT);
        $m->addRule('reference', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');
        $m->addElement('textarea', 'note', get_string('note', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('note', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('recordpayout', $p));
    }

    /**
     * Validation: above zero and not more than the school owes.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $amount = billing::parse_amount((string)$data['amount']);
        if ($amount === null || $amount <= 0 || $amount > round($this->_customdata['owed'], 2) + 0.004) {
            $errors['amount'] = get_string('err_payoutamount', 'local_studentrecords', billing::money($this->_customdata['owed']));
        }
        return $errors;
    }
}
