<?php

namespace local_studentrecords\form;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\billing;

/**
 * Add or change a price list item (tuition or fee).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class fee extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'fee');
        $m->setType('fee', PARAM_INT);
        $m->addElement('text', 'name', get_string('feename', $p), ['size' => 50]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
        $m->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $options = fn($list, $prefix) => array_combine($list, array_map(fn($k) => get_string($prefix . $k, $p), $list));
        $m->addElement('select', 'kind', get_string('feekind', $p), $options(billing::KINDS, 'feekind_'));
        $m->addElement('select', 'basis', get_string('feebasis', $p), $options(billing::BASES, 'feebasis_'));
        $m->addHelpButton('basis', 'feebasis', $p);
        $m->addElement('text', 'amount', get_string('amountusd', $p), ['size' => 12]);
        $m->setType('amount', PARAM_RAW_TRIMMED);
        $m->addRule('amount', null, 'required', null, 'client');
        $m->addElement('select', 'scope', get_string('feescope', $p), $options(billing::SCOPES, 'scope_'));
        $m->addHelpButton('scope', 'feescope', $p);
        $m->addElement('select', 'levelid', get_string('level', $p), array_map(
            'format_string',
            \local_studentrecords\local\catalog::levels()
        ));
        $m->hideIf('levelid', 'scope', 'neq', 'level');
        $m->addElement('select', 'programid', get_string('program', $p), \local_studentrecords\local\catalog::programs(false));
        $m->hideIf('programid', 'scope', 'neq', 'program');
        $m->addElement('selectyesno', 'refundable', get_string('feerefundable', $p));
        $m->setDefault('refundable', 1);
        $m->addElement('selectyesno', 'qualified', get_string('feequalified', $p));
        $m->addHelpButton('qualified', 'feequalified', $p);
        $m->setDefault('qualified', 1);
        if (!empty($this->_customdata['edit'])) {
            $m->addElement('selectyesno', 'active', get_string('active', $p));
        }
        $m->addElement('textarea', 'note', get_string('note', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('note', PARAM_TEXT);
        $this->add_action_buttons();
    }

    /**
     * Validation: a valid amount; a level or program when the item is for one.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (billing::parse_amount((string)$data['amount']) === null) {
            $errors['amount'] = get_string('err_amount', 'local_studentrecords');
        }
        foreach (['level' => 'levelid', 'program' => 'programid'] as $scope => $field) {
            if ($data['scope'] === $scope && empty($data[$field])) {
                $errors[$field] = get_string('required');
            }
        }
        if ($data['kind'] === 'tuition' && in_array($data['basis'], ['manual', 'late'], true)) {
            $errors['basis'] = get_string('err_tuitionbasis', 'local_studentrecords');
        }
        return $errors;
    }
}
