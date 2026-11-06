<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add class meetings to a course offering: one meeting, or a weekly schedule over the term.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class meeting extends \moodleform {
    /**
     * Form definition. Custom data: mode (one or weekly).
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $weekly = $this->_customdata['mode'] === 'weekly';
        foreach (['offer' => PARAM_INT, 'mode' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        if ($weekly) {
            $days = [];
            foreach ([1, 2, 3, 4, 5, 6, 7] as $d) {
                $days[] = $m->createElement('advcheckbox', 'day' . $d, '', get_string(['', 'monday', 'tuesday', 'wednesday',
                    'thursday', 'friday', 'saturday', 'sunday'][$d], 'calendar'));
            }
            $m->addGroup($days, 'days', get_string('meetingdays', $p), ' ', false);
            $m->addHelpButton('days', 'meetingdays', $p);
        } else {
            $m->addElement(
                'date_selector',
                'meetingdate',
                get_string('date'),
                ['timezone' => \local_studentrecords\local\catalog::timezone()]
            );
            $m->addElement('text', 'topic', get_string('meetingtopic', $p), ['size' => 50]);
            $m->setType('topic', PARAM_TEXT);
        }
        $m->addElement('text', 'hours', get_string('meetinghours', $p), ['size' => 5]);
        $m->setType('hours', PARAM_RAW_TRIMMED);
        $m->addRule('hours', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string($weekly ? 'addweekly' : 'addmeeting', $p));
    }

    /**
     * Validation: hours from more than 0 to 24; at least one day for a weekly schedule.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $h = trim((string)$data['hours']);
        if (!is_numeric($h) || $h <= 0 || $h > 24) {
            $errors['hours'] = get_string('err_meetinghours', 'local_studentrecords');
        }
        if ($this->_customdata['mode'] === 'weekly' && !array_filter(array_map(fn($d) => !empty($data['day' . $d]), range(1, 7)))) {
            $errors['days'] = get_string('err_meetingdays', 'local_studentrecords');
        }
        return $errors;
    }
}
