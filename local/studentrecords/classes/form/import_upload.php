<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload a spreadsheet (.xlsx) or a CSV file to import.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class import_upload extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement(
            'filepicker',
            'importfile',
            get_string('importfile', $p),
            null,
            ['accepted_types' => ['.xlsx', '.csv'], 'maxbytes' => 10485760]
        );
        $m->addRule('importfile', null, 'required', null, 'client');
        $m->addHelpButton('importfile', 'importfile', $p);
        $this->add_action_buttons(false, get_string('readfile', $p));
    }
}
