<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A row of the grading scale (a letter, or a mark not used in GPA).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class grade extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'what', 'grade');
        $m->setType('what', PARAM_ALPHA);
        $m->addElement('text', 'letter', get_string('letter', $p), ['size' => 6]);
        $m->setType('letter', PARAM_TEXT);
        $m->addRule('letter', get_string('maximumchars', '', 10), 'maxlength', 10, 'server');
        $m->addRule('letter', null, 'required', null, 'client');
        foreach (['minpct', 'maxpct', 'points'] as $f) {
            $m->addElement('text', $f, get_string($f, $p), ['size' => 6]);
            $m->setType($f, PARAM_RAW_TRIMMED);
        }
        $m->addElement('advcheckbox', 'ingpa', get_string('ingpa', $p));
        $m->setDefault('ingpa', 1);
        $m->addElement('advcheckbox', 'earns', get_string('earns', $p));
        $m->addHelpButton('earns', 'earns', $p);
        $m->addElement('text', 'description', get_string('description'), ['size' => 60]);
        $m->setType('description', PARAM_TEXT);
        $this->add_action_buttons();
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        foreach (['minpct', 'maxpct', 'points'] as $f) {
            if ($data[$f] !== '' && !is_numeric($data[$f])) {
                $errors[$f] = get_string('err_number', 'local_studentrecords');
            }
        }
        if (!empty($data['ingpa']) && $data['points'] === '') {
            $errors['points'] = get_string('required');
        }
        $other = $DB->get_field('local_studentrecords_grade', 'id', ['letter' => trim($data['letter'])]);
        if ($other && $other != $data['id']) {
            $errors['letter'] = get_string('err_duplicate', 'local_studentrecords');
        }
        return $errors;
    }
}
