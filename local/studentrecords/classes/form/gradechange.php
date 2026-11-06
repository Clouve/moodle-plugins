<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Request a grade change (a second person approves it).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class gradechange extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'reg');
        $m->setType('reg', PARAM_INT);
        $m->addElement('static', 'what', '', $this->_customdata['text']);
        $m->addElement(
            'select',
            'newgrade',
            get_string('newgrade', $p),
            array_map('s', \local_studentrecords\local\catalog::grade_menu())
        );
        $m->addElement('text', 'newpct', get_string('gradepct', $p), ['size' => 6]);
        $m->setType('newpct', PARAM_RAW_TRIMMED);
        $m->addElement('textarea', 'reason', get_string('changereason', $p), ['rows' => 3, 'cols' => 60]);
        $m->setType('reason', PARAM_TEXT);
        $m->addRule('reason', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('requestchange', $p));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['newpct'] !== '' && (!is_numeric($data['newpct']) || $data['newpct'] < 0 || $data['newpct'] > 100)) {
            $errors['newpct'] = get_string('err_pct', 'local_studentrecords');
        }
        if ($data['newgrade'] === ($this->_customdata['current'] ?? null)) {
            $errors['newgrade'] = get_string('err_samegrade', 'local_studentrecords');
        }
        return $errors;
    }
}
