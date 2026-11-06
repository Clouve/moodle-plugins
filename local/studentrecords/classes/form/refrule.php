<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\refunds;

/**
 * Add or change one of the school's refund rules.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class refrule extends \moodleform {
    /**
     * Form definition. Custom data: edit.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'rule');
        $m->setType('rule', PARAM_INT);
        $m->addElement('text', 'name', get_string('rulename', $p), ['size' => 50]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
        $m->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $m->addElement('text', 'sortorder', get_string('ruleorder', $p), ['size' => 4]);
        $m->setType('sortorder', PARAM_INT);
        $m->addHelpButton('sortorder', 'ruleorder', $p);
        $types = [];
        foreach (refunds::RULETYPES as $t) {
            $types[$t] = get_string('ruletype_' . $t, $p);
        }
        $m->addElement('select', 'ruletype', get_string('ruletype', $p), $types);
        $m->addHelpButton('ruletype', 'ruletype', $p);
        $m->addElement('text', 'value', get_string('rulevalue', $p), ['size' => 6]);
        $m->setType('value', PARAM_RAW_TRIMMED);
        $m->addHelpButton('value', 'rulevalue', $p);
        foreach (['beforestart', 'adddrop', 'after'] as $t) {
            $m->hideIf('value', 'ruletype', 'eq', $t);
        }
        $m->addElement('text', 'tuitionpct', get_string('tuitionpct', $p), ['size' => 6]);
        $m->setType('tuitionpct', PARAM_RAW_TRIMMED);
        $m->addElement('text', 'feespct', get_string('feespct', $p), ['size' => 6]);
        $m->setType('feespct', PARAM_RAW_TRIMMED);
        $m->addHelpButton('feespct', 'feespct', $p);
        $m->addElement('selectyesno', 'allfees', get_string('allfees', $p));
        $m->addHelpButton('allfees', 'allfees', $p);
        if (!empty($this->_customdata['edit'])) {
            $m->addElement('selectyesno', 'active', get_string('active', $p));
        }
        $m->addElement('textarea', 'note', get_string('note', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('note', PARAM_TEXT);
        $m->setDefault('value', '0');
        $m->setDefault('tuitionpct', '100');
        $m->setDefault('feespct', '100');
        $this->add_action_buttons();
    }

    /**
     * Validation: percents from 0 to 100; a value of 0 or more.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (['tuitionpct', 'feespct'] as $f) {
            $v = trim((string)$data[$f]);
            if (!is_numeric($v) || $v < 0 || $v > 100) {
                $errors[$f] = get_string('err_percent0', 'local_studentrecords');
            }
        }
        $v = trim((string)$data['value']);
        if ($v !== '' && (!is_numeric($v) || $v < 0)) {
            $errors['value'] = get_string('err_gracedays', 'local_studentrecords');
        }
        return $errors;
    }
}
