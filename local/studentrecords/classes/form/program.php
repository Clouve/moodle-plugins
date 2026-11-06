<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A program.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class program extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        foreach (['code' => 20, 'name' => 60, 'school' => 60, 'credential' => 30] as $f => $size) {
            $m->addElement('text', $f, get_string('prog_' . $f, $p), ['size' => $size]);
            $m->setType($f, PARAM_TEXT);
        }
        $m->addRule('code', null, 'required', null, 'client');
        $m->addRule('name', null, 'required', null, 'client');
        $types = [];
        foreach (\local_studentrecords\local\importer::CREDENTIALS as $t) {
            $types[$t] = get_string('cred_' . $t, $p);
        }
        $m->addElement('select', 'credentialtype', get_string('prog_credentialtype', $p), $types);
        $m->addElement('selectyesno', 'isdegree', get_string('prog_isdegree', $p));
        $m->addElement(
            'select',
            'levelid',
            get_string('level', $p),
            [0 => get_string('none')] + array_map('format_string', \local_studentrecords\local\catalog::levels())
        );
        $m->addElement('select', 'unittype', get_string('unittype', $p), \local_studentrecords\local\catalog::unittypes());
        $m->addHelpButton('unittype', 'unittype', $p);
        foreach (['totalhours', 'trackhours', 'mingpa', 'residenthours'] as $f) {
            $m->addElement('text', $f, get_string('prog_' . $f, $p), ['size' => 8]);
            $m->setType($f, PARAM_RAW_TRIMMED);
        }
        $m->addHelpButton('residenthours', 'prog_residenthours', $p);
        $m->addElement('select', 'deliverymode', get_string('prog_deliverymode', $p), ['' => get_string('choosedots')] +
            \local_studentrecords\local\catalog::delivery_modes());
        $m->addElement('text', 'location', get_string('prog_location', $p), ['size' => 60]);
        $m->setType('location', PARAM_TEXT);
        $m->addHelpButton('location', 'prog_location', $p);
        foreach (['delivery' => 60, 'cipcode' => 10, 'duration' => 40, 'maxtime' => 40] as $f => $size) {
            $m->addElement('text', $f, get_string('prog_' . $f, $p), ['size' => $size]);
            $m->setType($f, PARAM_TEXT);
        }
        $m->addElement('textarea', 'graderules', get_string('prog_graderules', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('graderules', PARAM_TEXT);
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
        foreach (['totalhours', 'trackhours', 'mingpa', 'residenthours'] as $f) {
            if ($data[$f] !== '' && !is_numeric($data[$f])) {
                $errors[$f] = get_string('err_number', 'local_studentrecords');
            }
        }
        $other = $DB->get_field('local_studentrecords_program', 'id', ['code' => trim($data['code'])]);
        if ($other && $other != $data['id']) {
            $errors['code'] = get_string('err_duplicate', 'local_studentrecords');
        }
        return $errors;
    }
}
