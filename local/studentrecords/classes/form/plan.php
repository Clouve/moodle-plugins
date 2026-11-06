<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\plans;

/**
 * A payment plan: the school's plan (Billing setup), or a plan given to a student for a term (one of the school's
 * plans, or a custom plan with a note).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class plan extends \moodleform {
    /**
     * Form definition. Custom data: mode (template or student), edit (template), templates (student: id => name).
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $student = $this->_customdata['mode'] === 'student';
        $options = fn($list, $prefix) => array_combine($list, array_map(fn($k) => get_string($prefix . $k, $p), $list));
        if ($student) {
            foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA] as $f => $type) {
                $m->addElement('hidden', $f);
                $m->setType($f, $type);
            }
            $m->addElement('select', 'termid', get_string('term', $p), array_map(
                'format_string',
                \local_studentrecords\local\catalog::terms()
            ));
            $m->addElement('select', 'planid', get_string('plantemplate', $p), $this->_customdata['templates'] +
                [0 => get_string('customplan', $p)]);
            $m->addHelpButton('planid', 'plantemplate', $p);
        } else {
            $m->addElement('hidden', 'plan');
            $m->setType('plan', PARAM_INT);
        }
        $m->addElement('text', 'name', get_string('planname', $p), ['size' => 50]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        if (!$student) {
            $m->addRule('name', null, 'required', null, 'client');
            $m->addElement('select', 'scope', get_string('planwho', $p), $options(billing::SCOPES, 'scope_'));
            $m->addElement('select', 'levelid', get_string('level', $p), array_map(
                'format_string',
                \local_studentrecords\local\catalog::levels()
            ));
            $m->hideIf('levelid', 'scope', 'neq', 'level');
            $m->addElement('select', 'programid', get_string('program', $p), \local_studentrecords\local\catalog::programs(false));
            $m->hideIf('programid', 'scope', 'neq', 'program');
        }
        $m->addElement('select', 'downtype', get_string('downtype', $p), $options(plans::DOWNTYPES, 'downtype_'));
        $m->addElement('text', 'downvalue', get_string('downvalue', $p), ['size' => 10]);
        $m->setType('downvalue', PARAM_RAW_TRIMMED);
        $m->addHelpButton('downvalue', 'downvalue', $p);
        $m->addElement('text', 'numpay', get_string('numpay', $p), ['size' => 4]);
        $m->setType('numpay', PARAM_INT);
        $m->addElement('select', 'frequency', get_string('frequency', $p), $options(plans::FREQUENCIES, 'frequency_'));
        $m->addElement('select', 'duemode', get_string('duemode', $p), $options(plans::DUEMODES, 'duemode_'));
        $m->addElement('text', 'firstdue', get_string('firstdue', $p), ['size' => 4]);
        $m->setType('firstdue', PARAM_INT);
        $m->addHelpButton('firstdue', 'firstdue', $p);
        $m->hideIf('firstdue', 'duemode', 'neq', 'relative');
        $m->hideIf('frequency', 'duemode', 'neq', 'relative');
        $m->addElement('text', 'duedates', get_string('duedates', $p), ['size' => 60]);
        $m->setType('duedates', PARAM_TEXT);
        $m->addRule('duedates', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $m->addHelpButton('duedates', 'duedates', $p);
        $m->hideIf('duedates', 'duemode', 'neq', 'fixed');
        foreach (['planfee', 'latefee'] as $f) {
            $m->addElement('text', $f, get_string($f, $p), ['size' => 10]);
            $m->setType($f, PARAM_RAW_TRIMMED);
            $m->setDefault($f, '0');
        }
        $m->addHelpButton('latefee', 'latefee', $p);
        $m->addElement('text', 'gracedays', get_string('gracedays', $p), ['size' => 4]);
        $m->setType('gracedays', PARAM_INT);
        $m->addHelpButton('gracedays', 'gracedays', $p);
        $m->setDefault('downtype', 'percent');
        $m->setDefault('downvalue', '25');
        $m->setDefault('numpay', 3);
        $m->setDefault('frequency', 'monthly');
        $m->setDefault('firstdue', 30);
        $m->setDefault('gracedays', 5);
        if ($student) {
            foreach (
                ['name', 'downtype', 'downvalue', 'numpay', 'frequency', 'duemode', 'firstdue', 'duedates', 'planfee',
                    'latefee', 'gracedays'] as $f
            ) {
                $m->hideIf($f, 'planid', 'neq', 0);
            }
        } else if (!empty($this->_customdata['edit'])) {
            $m->addElement('selectyesno', 'active', get_string('active', $p));
        }
        $m->addElement('textarea', 'note', get_string($student ? 'plannote' : 'note', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('note', PARAM_TEXT);
        if ($student) {
            $m->addHelpButton('note', 'plannote', $p);
        }
        if ($student) {
            $m->addElement('advcheckbox', 'sendagreement', get_string('plan_sendagreement', $p));
            $m->setDefault('sendagreement', 1);
            $m->addHelpButton('sendagreement', 'plan_sendagreement', $p);
        }
        $this->add_action_buttons(true, get_string($student ? 'giveplan' : 'savechanges', $student ? $p : 'moodle'));
    }

    /**
     * Validation of the plan terms (for a student only when the plan is custom); a note for a custom plan.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $p = 'local_studentrecords';
        $student = $this->_customdata['mode'] === 'student';
        if ($student && !empty($data['planid'])) {
            return $errors;
        }
        if ($student && trim((string)$data['name']) === '') {
            $errors['name'] = get_string('required');
        }
        if ($student && trim((string)$data['note']) === '') {
            $errors['note'] = get_string('err_customnote', $p);
        }
        foreach (['downvalue', 'planfee', 'latefee'] as $f) {
            if (billing::parse_amount((string)$data[$f]) === null) {
                $errors[$f] = get_string('err_amount', $p);
            }
        }
        if ($data['downtype'] === 'percent' && billing::parse_amount((string)$data['downvalue']) > 100) {
            $errors['downvalue'] = get_string('err_percent', $p);
        }
        if ((int)$data['numpay'] < 1 || (int)$data['numpay'] > 24) {
            $errors['numpay'] = get_string('err_numpay', $p);
        }
        if ((int)$data['gracedays'] < 0) {
            $errors['gracedays'] = get_string('err_gracedays', $p);
        }
        if (
            $data['duemode'] === 'fixed' && !plans::due_days(
                'fixed',
                '2000-01-01',
                0,
                'monthly',
                (int)$data['numpay'],
                (string)$data['duedates']
            )
        ) {
            $errors['duedates'] = get_string('err_duedates', $p, (int)$data['numpay']);
        }
        if (!$student) {
            foreach (['level' => 'levelid', 'program' => 'programid'] as $scope => $field) {
                if ($data['scope'] === $scope && empty($data[$field])) {
                    $errors[$field] = get_string('required');
                }
            }
        }
        return $errors;
    }
}
