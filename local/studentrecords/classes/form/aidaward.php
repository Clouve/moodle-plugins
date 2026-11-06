<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\aid;

/**
 * Federal aid module: add or change an aid award, or record a disbursement of an accepted award.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class aidaward extends \moodleform {
    /**
     * Form definition. Custom data: mode (award or disburse), left (disburse: amount still to disburse).
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA, 'item' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        if ($this->_customdata['mode'] === 'disburse') {
            $m->addElement(
                'static',
                'left',
                get_string('aidleft', $p),
                \local_studentrecords\local\billing::money($this->_customdata['left'])
            );
            $m->addElement('date_selector', 'txndate', get_string('linedate', $p), [
                'timezone' => \local_studentrecords\local\catalog::timezone(),
            ]);
        } else {
            $m->addElement('text', 'awardyear', get_string('awardyear', $p), ['size' => 9]);
            $m->setType('awardyear', PARAM_TEXT);
            $m->addRule('awardyear', null, 'required', null, 'client');
            $m->addHelpButton('awardyear', 'awardyear', $p);
            $m->addElement('select', 'termid', get_string('term', $p), [0 => get_string('none')] +
                array_map('format_string', \local_studentrecords\local\catalog::terms()));
            $funds = [];
            foreach (array_keys(aid::FUNDS) as $f) {
                $funds[$f] = get_string('fund_' . $f, $p);
            }
            $m->addElement('select', 'fund', get_string('fund', $p), $funds);
            $statuses = [];
            foreach (aid::STATUSES as $s) {
                $statuses[$s] = get_string('aidstatus_' . $s, $p);
            }
            $m->addElement('select', 'status', get_string('status', $p), $statuses);
            $m->addElement('textarea', 'note', get_string('note', $p), ['rows' => 2, 'cols' => 60]);
            $m->setType('note', PARAM_TEXT);
        }
        $m->addElement('text', 'amount', get_string('amountusd', $p), ['size' => 12]);
        $m->setType('amount', PARAM_RAW_TRIMMED);
        $m->addRule('amount', null, 'required', null, 'client');
        $this->add_action_buttons(true);
    }

    /**
     * Validation: an amount (above zero for a disbursement, not more than what is left); award year written like 2026-27.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $p = 'local_studentrecords';
        $amount = \local_studentrecords\local\billing::parse_amount((string)$data['amount']);
        if ($this->_customdata['mode'] === 'disburse') {
            if ($amount === null || $amount <= 0 || $amount > round($this->_customdata['left'], 2) + 0.004) {
                $errors['amount'] = get_string(
                    'err_aidamount',
                    $p,
                    \local_studentrecords\local\billing::money($this->_customdata['left'])
                );
            }
        } else {
            if ($amount === null) {
                $errors['amount'] = get_string('err_amount', $p);
            }
            if (!preg_match('/^\d{4}-\d{2}$/', trim((string)$data['awardyear']))) {
                $errors['awardyear'] = get_string('err_awardyear', $p);
            }
            if (!empty(aid::FUNDS[$data['fund']]) && empty($data['termid'])) {
                $errors['termid'] = get_string('err_aidneedsterm', $p);
            }
        }
        return $errors;
    }
}
