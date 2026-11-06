<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A degree, transcript, license or certification on file for a faculty member.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class credential extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id', 'facultyid'] as $f) {
            $m->addElement('hidden', $f);
            $m->setType($f, PARAM_INT);
        }
        $types = [];
        foreach (\local_studentrecords\local\faculty::CREDTYPES as $t) {
            $types[$t] = get_string('credtype_' . $t, $p);
        }
        $m->addElement('select', 'credtype', get_string('credtype', $p), $types);
        foreach (['title' => 60, 'subject' => 60, 'institution' => 60] as $f => $size) {
            $m->addElement('text', $f, get_string('cred_' . $f, $p), ['size' => $size]);
            $m->setType($f, PARAM_TEXT);
        }
        $m->addRule('title', null, 'required', null, 'client');
        $m->addElement('text', 'yearawarded', get_string('cred_yearawarded', $p), ['size' => 6]);
        $m->setType('yearawarded', PARAM_RAW_TRIMMED);
        $m->addElement('date_selector', 'expires', get_string('expires', $p), ['optional' => true]);
        $m->addElement('advcheckbox', 'verified', get_string('cred_verified', $p));
        $m->addElement('filemanager', 'files', get_string('cred_files', $p), null, ['maxfiles' => 5, 'subdirs' => 0]);
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
        if (
            $data['yearawarded'] !== '' && (!ctype_digit($data['yearawarded']) || $data['yearawarded'] < 1900 ||
                $data['yearawarded'] > 2100)
        ) {
            $errors['yearawarded'] = get_string('err_number', 'local_studentrecords');
        }
        return $errors;
    }
}
