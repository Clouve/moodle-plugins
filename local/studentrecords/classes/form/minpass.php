<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Lowest passing grade for a level, a program, a group of a program's courses, or one course.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class minpass extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $catalog = \local_studentrecords\local\catalog::class;
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'what', 'minpass');
        $m->setType('what', PARAM_ALPHA);
        $m->addElement('static', 'scopehelp', '', get_string('minpass_intro', $p));
        $m->addElement('select', 'levelid', get_string('level', $p), [0 => '-'] + array_map('format_string', $catalog::levels()));
        $m->addElement('select', 'programid', get_string('program', $p), [0 => '-'] + $catalog::programs());
        $m->addElement('text', 'groupname', get_string('groupname', $p), ['size' => 50]);
        $m->setType('groupname', PARAM_TEXT);
        $m->addElement('text', 'coursecode', get_string('coursecode', $p), ['size' => 15]);
        $m->setType('coursecode', PARAM_TEXT);
        $m->addElement('select', 'minletter', get_string('minletter', $p), ['' => '-'] + array_map('s', $catalog::letters()));
        $m->addElement('text', 'minpct', get_string('minpctrule', $p), ['size' => 6]);
        $m->setType('minpct', PARAM_RAW_TRIMMED);
        $m->addElement('text', 'note', get_string('note', $p), ['size' => 60]);
        $m->setType('note', PARAM_TEXT);
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
        $p = 'local_studentrecords';
        if (empty($data['levelid']) && empty($data['programid'])) {
            $errors['levelid'] = get_string('err_scope', $p);
        }
        if (!empty($data['levelid']) && (!empty($data['programid']) || trim($data['coursecode']) !== '')) {
            $errors['levelid'] = get_string('err_scope', $p);
        }
        if ((trim($data['groupname']) !== '' || trim($data['coursecode']) !== '') && empty($data['programid'])) {
            $errors['programid'] = get_string('err_scope', $p);
        }
        if (trim($data['coursecode']) !== '' && !\local_studentrecords\local\catalog::course_id($data['coursecode'])) {
            $errors['coursecode'] = get_string('err_unknowncourse', $p);
        }
        if (($data['minletter'] === '') === (trim($data['minpct']) === '')) {
            $errors['minletter'] = get_string('err_letterorpct', $p);
        }
        if (trim($data['minpct']) !== '' && !is_numeric($data['minpct'])) {
            $errors['minpct'] = get_string('err_number', $p);
        }
        return $errors;
    }
}
