<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Transfer credit accepted from another institution.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class xfer extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        global $DB;
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'part' => PARAM_ALPHA, 'item' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('text', 'institution', get_string('xfer_institution', $p), ['size' => 60]);
        $m->setType('institution', PARAM_TEXT);
        $m->addRule('institution', null, 'required', null, 'client');
        $m->addElement('text', 'extcourse', get_string('xfer_extcourse', $p), ['size' => 60]);
        $m->setType('extcourse', PARAM_TEXT);
        $m->addElement('text', 'credits', get_string('hours', $p), ['size' => 6]);
        $m->setType('credits', PARAM_RAW_TRIMMED);
        $m->addRule('credits', null, 'required', null, 'client');
        $m->addElement('text', 'grade', get_string('xfer_grade', $p), ['size' => 6]);
        $m->setType('grade', PARAM_TEXT);
        $m->addRule('grade', get_string('maximumchars', '', 10), 'maxlength', 10, 'server');
        $courses = [0 => get_string('none')];
        foreach ($DB->get_records('local_studentrecords_course', ['active' => 1], 'code', 'id, code, title') as $c) {
            $courses[$c->id] = s($c->code) . ' ' . format_string($c->title);
        }
        $m->addElement('autocomplete', 'equivcourseid', get_string('xfer_equiv', $p), $courses);
        $m->addHelpButton('equivcourseid', 'xfer_equiv', $p);
        $m->addElement('advcheckbox', 'countsprogram', get_string('xfer_countsprogram', $p));
        $m->setDefault('countsprogram', 1);
        $m->addElement('advcheckbox', 'ingpa', get_string('xfer_ingpa', $p));
        $m->setDefault('ingpa', \local_studentrecords\local\catalog::setting('transferingpa') === '1' ? 1 : 0);
        $m->addHelpButton('ingpa', 'xfer_ingpa', $p);
        $m->addElement(
            'date_selector',
            'awarded',
            get_string('xfer_awarded', $p),
            ['optional' => true, 'timezone' => \local_studentrecords\local\catalog::timezone()]
        );
        $m->addElement('text', 'note', get_string('notes', $p), ['size' => 60]);
        $m->setType('note', PARAM_TEXT);
        if (!empty($this->_customdata['item'])) {
            $m->addElement('advcheckbox', 'active', get_string('keepshown', $p));
            $m->addHelpButton('active', 'keepshown', $p);
        }
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
        $errors = parent::validation($data, $files);
        if (!is_numeric($data['credits']) || $data['credits'] <= 0) {
            $errors['credits'] = get_string('err_number', 'local_studentrecords');
        }
        return $errors;
    }
}
