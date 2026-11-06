<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Change one automatic message: on or off, subject and text (empty = the default text).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class msgtext extends \moodleform {
    /**
     * Form definition. Custom data: defaultsubject, defaultbody.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'key');
        $m->setType('key', PARAM_ALPHA);
        $m->addElement('selectyesno', 'on', get_string('msgon', $p));
        $m->addElement('text', 'subject', get_string('msgsubject', $p), ['size' => 70]);
        $m->setType('subject', PARAM_TEXT);
        $m->addElement('textarea', 'body', get_string('msgbody', $p), ['rows' => 6, 'cols' => 70]);
        $m->setType('body', PARAM_TEXT);
        $m->addElement('static', 'placeholders', get_string('msgplaceholders', $p), get_string('msgplaceholders_desc', $p));
        $m->addElement('static', 'defaults', get_string('msgdefault', $p), '<strong>' . s($this->_customdata['defaultsubject']) .
            '</strong><br>' . nl2br(s($this->_customdata['defaultbody'])));
        $this->add_action_buttons(true);
    }
}
