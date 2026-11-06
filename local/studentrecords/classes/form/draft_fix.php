<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Fix the values of a pending imported item before approving it.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class draft_fix extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        foreach ($this->_customdata['fields'] as $name => $value) {
            if (is_array($value)) {
                continue;
            }
            $m->addElement('text', 'f_' . $name, s($name), ['size' => 60]);
            $m->setType('f_' . $name, PARAM_TEXT);
            $m->setDefault('f_' . $name, (string)$value);
        }
        $this->add_action_buttons(true, get_string('savefix', 'local_studentrecords'));
    }
}
