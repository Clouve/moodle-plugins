<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A catalog course.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class course extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('text', 'code', get_string('coursecode', $p), ['size' => 20]);
        $m->setType('code', PARAM_TEXT);
        $m->addRule('code', null, 'required', null, 'client');
        $m->addElement('text', 'title', get_string('coursetitle', $p), ['size' => 60]);
        $m->setType('title', PARAM_TEXT);
        $m->addRule('title', null, 'required', null, 'client');
        $m->addElement('text', 'hours', get_string('hours', $p), ['size' => 8]);
        $m->setType('hours', PARAM_RAW_TRIMMED);
        $m->addElement('select', 'unittype', get_string('unittype', $p), \local_studentrecords\local\catalog::unittypes());
        $m->addElement(
            'select',
            'levelid',
            get_string('level', $p),
            [0 => get_string('none')] + array_map('format_string', \local_studentrecords\local\catalog::levels())
        );
        $m->addElement('textarea', 'prereqtext', get_string('prereqtext', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('prereqtext', PARAM_TEXT);
        $m->addHelpButton('prereqtext', 'prereqtext', $p);
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
        if ($data['hours'] !== '' && !is_numeric($data['hours'])) {
            $errors['hours'] = get_string('err_number', 'local_studentrecords');
        }
        $code = \local_studentrecords\local\grading::normalise_code($data['code']);
        $other = $DB->get_field('local_studentrecords_course', 'id', ['code' => $code]);
        if ($other && $other != $data['id']) {
            $errors['code'] = get_string('err_duplicate', 'local_studentrecords');
        }
        return $errors;
    }
}
