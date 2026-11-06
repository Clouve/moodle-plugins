<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * An academic level.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class level extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'what', 'level');
        $m->setType('what', PARAM_ALPHA);
        $m->addElement('text', 'name', get_string('levelname', $p), ['size' => 40]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
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
        $errors = parent::validation($data, $files);
        $other = \local_studentrecords\local\catalog::level_id($data['name']);
        if ($other && $other != $data['id']) {
            $errors['name'] = get_string('err_duplicate', 'local_studentrecords');
        }
        return $errors;
    }
}
