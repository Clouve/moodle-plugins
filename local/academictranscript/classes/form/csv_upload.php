<?php

namespace local_academictranscript\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload a CSV file (from the templates).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class csv_upload extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'local_academictranscript';
        $mform->addElement('hidden', 'step', $this->_customdata['step']);
        $mform->setType('step', PARAM_ALPHA);
        $mform->addElement('hidden', 'action', 'csv');
        $mform->setType('action', PARAM_ALPHA);
        $types = [];
        foreach ($this->_customdata['types'] as $type) {
            $types[$type] = get_string('itemtype_' . $type, $p);
        }
        $mform->addElement('select', 'itemtype', get_string('csvtype', $p), $types);
        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', $p), null, ['accepted_types' => ['.csv', '.txt']]);
        $mform->addRule('csvfile', null, 'required');
        $this->add_action_buttons(false, get_string('readcsv', $p));
    }
}
