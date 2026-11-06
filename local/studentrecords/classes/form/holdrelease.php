<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Release a hold (kept with who and when).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class holdrelease extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'part' => PARAM_ALPHA, 'item' => PARAM_INT] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('static', 'what', '', $this->_customdata['text']);
        $m->addElement('text', 'releasenote', get_string('releasenote', $p), ['size' => 60]);
        $m->setType('releasenote', PARAM_TEXT);
        $m->addRule('releasenote', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('releasehold', $p));
    }
}
