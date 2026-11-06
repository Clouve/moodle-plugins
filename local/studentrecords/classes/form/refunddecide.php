<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Approve or reject a proposed refund, with a note.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class refunddecide extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('select', 'decision', get_string('decision', $p), ['approve' => get_string('approverefund', $p),
            'reject' => get_string('rejectrefund', $p)]);
        $m->addElement('textarea', 'note', get_string('note', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('note', PARAM_TEXT);
        $this->add_action_buttons(false, get_string('savedecision', $p));
    }

    /**
     * Validation: a note when rejecting.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['decision'] === 'reject' && trim((string)$data['note']) === '') {
            $errors['note'] = get_string('err_reasonneeded', 'local_studentrecords');
        }
        return $errors;
    }
}
