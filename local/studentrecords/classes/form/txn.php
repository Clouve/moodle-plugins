<?php

namespace local_studentrecords\form;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\billing;

/**
 * Add a charge or a credit (scholarship, waiver ...) to a student account.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class txn extends \moodleform {
    /**
     * Form definition. Custom data: mode (charge or credit).
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $mode = $this->_customdata['mode'];
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        if ($mode === 'charge') {
            $fees = [0 => get_string('otherchargeitem', $p)];
            foreach (billing::fees(true) as $fee) {
                $fees[$fee->id] = format_string($fee->name) . ' (' . billing::money((float)$fee->amount) . ')';
            }
            $m->addElement('select', 'feeid', get_string('feeitem', $p), $fees);
            $m->addHelpButton('feeid', 'feeitem', $p);
        } else {
            $kinds = [];
            foreach (billing::CREDITKINDS as $k) {
                $kinds[$k] = get_string('creditkind_' . $k, $p);
            }
            $m->addElement('select', 'method', get_string('creditkind', $p), $kinds);
        }
        $m->addElement('text', 'description', get_string('description'), ['size' => 50]);
        $m->setType('description', PARAM_TEXT);
        $m->addElement('text', 'amount', get_string('amountusd', $p), ['size' => 12]);
        $m->setType('amount', PARAM_RAW_TRIMMED);
        if ($mode === 'charge') {
            $m->addHelpButton('amount', 'chargeamount', $p);
        } else {
            $m->addRule('amount', null, 'required', null, 'client');
        }
        $m->addElement('select', 'termid', get_string('term', $p), [0 => get_string('none')] +
            array_map('format_string', \local_studentrecords\local\catalog::terms()));
        $m->addElement('date_selector', 'txndate', get_string('linedate', $p), [
            'timezone' => \local_studentrecords\local\catalog::timezone(),
        ]);
        if ($mode === 'charge') {
            $m->addElement('selectyesno', 'qualified', get_string('feequalified', $p));
            $m->addHelpButton('qualified', 'feequalified', $p);
            $m->hideIf('qualified', 'feeid', 'neq', 0);
            $m->addElement('selectyesno', 'refundable', get_string('feerefundable', $p));
            $m->setDefault('refundable', 1);
            $m->hideIf('refundable', 'feeid', 'neq', 0);
        }
        $m->addElement('textarea', 'note', get_string('reasonnote', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('note', PARAM_TEXT);
        $m->addRule('note', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string($mode === 'charge' ? 'addcharge' : 'addcredit', $p));
    }

    /**
     * Validation: an amount above zero (a charge from the price list may leave it empty to use the list price),
     * a description for a charge not on the price list.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $p = 'local_studentrecords';
        $fromlist = !empty($data['feeid']);
        if (!($fromlist && trim((string)$data['amount']) === '')) {
            $amount = billing::parse_amount((string)$data['amount']);
            if ($amount === null || $amount <= 0) {
                $errors['amount'] = get_string('err_amountpositive', $p);
            }
        }
        if (!$fromlist && trim((string)$data['description']) === '') {
            $errors['description'] = get_string('required');
        }
        if (trim((string)$data['note']) === '') {
            $errors['note'] = get_string('required');
        }
        return $errors;
    }
}
