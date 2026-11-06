<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\payments;

/**
 * Record a payment made other than online (check, money order, cash, card at the office, bank transfer, sponsor,
 * Flywire ...). A receipt number is given by itself.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class payment extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $methods = [];
        foreach (payments::METHODS as $k) {
            $methods[$k] = get_string('paymethod_' . $k, $p);
        }
        $m->addElement('select', 'method', get_string('paymethod', $p), $methods);
        $m->addElement('text', 'amount', get_string('amountusd', $p), ['size' => 12]);
        $m->setType('amount', PARAM_RAW_TRIMMED);
        $m->addRule('amount', null, 'required', null, 'client');
        $m->addElement('date_selector', 'txndate', get_string('paydate', $p), [
            'timezone' => \local_studentrecords\local\catalog::timezone(),
        ]);
        $m->addElement('text', 'reference', get_string('payreference', $p), ['size' => 40]);
        $m->setType('reference', PARAM_TEXT);
        $m->addRule('reference', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');
        $m->addHelpButton('reference', 'payreference', $p);
        $m->addElement('select', 'termid', get_string('term', $p), [0 => get_string('none')] +
            array_map('format_string', \local_studentrecords\local\catalog::terms()));
        $m->addElement('textarea', 'note', get_string('note', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('note', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('recordpayment', $p));
    }

    /**
     * Validation: an amount above zero.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $amount = billing::parse_amount((string)$data['amount']);
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = get_string('err_amountpositive', 'local_studentrecords');
        }
        return $errors;
    }
}
