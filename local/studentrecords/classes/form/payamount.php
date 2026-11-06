<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\payments;

/**
 * A student picks how much to pay online (from $1.00 up to the balance).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class payamount extends \moodleform {
    /**
     * Form definition. Custom data: balance.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('static', 'balancenow', get_string('balance', $p), billing::money($this->_customdata['balance']));
        $m->addElement('text', 'amount', get_string('payamount', $p), ['size' => 12]);
        $m->setType('amount', PARAM_RAW_TRIMMED);
        $m->addRule('amount', null, 'required', null, 'client');
        $m->addHelpButton('amount', 'payamount', $p);
        $this->add_action_buttons(true, get_string('continuetopay', $p));
    }

    /**
     * Validation: from $1.00 up to the balance.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $amount = billing::parse_amount((string)$data['amount']);
        if ($amount === null || !payments::amount_allowed($amount, (float)$this->_customdata['balance'])) {
            $errors['amount'] = get_string('err_payamount', 'local_studentrecords');
        }
        return $errors;
    }
}
