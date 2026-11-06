<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Record an admission decision for a student.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class decision extends \moodleform {
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
        $options = [];
        foreach (\local_studentrecords\local\admissions::DECISIONS as $d) {
            $options[$d] = get_string('decision_' . $d, $p);
        }
        $m->addElement('select', 'decision', get_string('decision', $p), $options);
        $m->addElement('date_selector', 'decisiondate', get_string('decisiondate', $p));
        $m->addElement('select', 'termid', get_string('admitterm', $p), [0 => get_string('none')] +
            array_map('format_string', \local_studentrecords\local\catalog::terms()));
        $m->addHelpButton('termid', 'decisionterm', $p);
        $m->addElement('textarea', 'reason', get_string('decisionreason', $p), ['rows' => 3, 'cols' => 60]);
        $m->setType('reason', PARAM_TEXT);
        $m->addElement('textarea', 'conditions', get_string('decisionconditions', $p), ['rows' => 3, 'cols' => 60]);
        $m->setType('conditions', PARAM_TEXT);
        $m->addHelpButton('conditions', 'decisionconditions', $p);
        $this->add_action_buttons(true, get_string('recorddecision', $p));
    }

    /**
     * Validation: a denial needs a reason.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['decision'] === 'denied' && trim((string)$data['reason']) === '') {
            $errors['reason'] = get_string('err_denyreason', 'local_studentrecords');
        }
        return $errors;
    }
}
