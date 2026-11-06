<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Enter the final grade of a registration (staff).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class regrade extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'action' => PARAM_ALPHA, 'reg' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('static', 'what', '', $this->_customdata['text']);
        $m->addElement(
            'select',
            'grade',
            get_string('finalgrade', $p),
            array_map('s', \local_studentrecords\local\catalog::grade_menu())
        );
        $m->addElement('text', 'gradepct', get_string('gradepct', $p), ['size' => 6]);
        $m->setType('gradepct', PARAM_RAW_TRIMMED);
        $this->add_action_buttons(true, get_string('savegrade', $p));
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
        if ($data['gradepct'] !== '' && (!is_numeric($data['gradepct']) || $data['gradepct'] < 0 || $data['gradepct'] > 100)) {
            $errors['gradepct'] = get_string('err_pct', 'local_studentrecords');
        }
        return $errors;
    }
}
