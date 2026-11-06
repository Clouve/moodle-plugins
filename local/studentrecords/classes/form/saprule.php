<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * An academic standing (SAP) rule, school-wide or for a level.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class saprule extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('select', 'levelid', get_string('level', $p), [0 => get_string('allprograms', $p)] +
            array_map('format_string', \local_studentrecords\local\catalog::levels()));
        foreach (['mingpa', 'mincompletion', 'maxyears', 'maxtimeframe', 'maxtransfer'] as $f) {
            $m->addElement('text', $f, get_string('sap_' . $f, $p), ['size' => 6]);
            $m->setType($f, PARAM_RAW_TRIMMED);
            $m->addHelpButton($f, 'sap_' . $f, $p);
        }
        $m->addElement('advcheckbox', 'active', get_string('active', $p));
        $m->setDefault('active', 1);
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
        foreach (['mingpa', 'mincompletion', 'maxyears', 'maxtimeframe', 'maxtransfer'] as $f) {
            if ($data[$f] !== '' && (!is_numeric($data[$f]) || $data[$f] < 0)) {
                $errors[$f] = get_string('err_number', 'local_studentrecords');
            }
        }
        $levelid = (int)$data['levelid'];
        $other = $levelid ? $DB->get_field('local_studentrecords_saprule', 'id', ['levelid' => $levelid]) :
            $DB->get_field_select('local_studentrecords_saprule', 'id', 'levelid IS NULL');
        if ($other && $other != $data['id']) {
            $errors['levelid'] = get_string('err_duplicate', 'local_studentrecords');
        }
        return $errors;
    }
}
