<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Setup wizard steps School, Approvals and Calendar (all answers can be changed later).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class school extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $step = $this->_customdata['step'];
        $m->addElement('hidden', 'step', $step);
        $m->setType('step', PARAM_ALPHA);
        foreach (\local_studentrecords\local\catalog::SCHOOL as $name => [$type, $fieldstep]) {
            if ($fieldstep !== $step || ($name === 'opeid' && !\local_studentrecords\local\catalog::module_on('fedaid'))) {
                continue;
            }
            $label = get_string('set_' . $name, $p);
            if ($type === 'textarea') {
                $m->addElement('textarea', $name, $label, ['rows' => 3, 'cols' => 60]);
            } else if ($type === 'yesno') {
                $m->addElement('selectyesno', $name, $label);
            } else if ($type === 'timezone') {
                $zones = \core_date::get_list_of_timezones(null, true);
                $m->addElement('select', $name, $label, $zones);
                $m->setDefault($name, 99);
            } else if ($type === 'calendar') {
                $options = ['' => get_string('choosedots')];
                foreach (\local_studentrecords\local\importer::CALENDARS as $c) {
                    $options[$c] = get_string('calendar_' . $c, $p);
                }
                $m->addElement('select', $name, $label, $options);
            } else if (in_array($type, ['percent', 'percent0', 'number', 'hours'], true)) {
                $m->addElement('text', $name, $label, ['size' => 5]);
            } else {
                $m->addElement('text', $name, $label, ['size' => 60]);
            }
            $m->setType($name, $type === 'text' || $type === 'textarea' ? PARAM_TEXT : PARAM_RAW_TRIMMED);
            if (get_string_manager()->string_exists('set_' . $name . '_help', $p)) {
                $m->addHelpButton($name, 'set_' . $name, $p);
            }
        }
        if ($step === 'admissions') {
            $m->setDefault('idyear', 1);
            $m->setDefault('idpadding', 4);
        }
        if ($step === 'school') {
            $m->addRule('schoolname', null, 'required', null, 'client');
            $m->addElement(
                'filemanager',
                'logo',
                get_string('set_logo', $p),
                null,
                ['maxfiles' => 1, 'subdirs' => 0, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
            );
        }
        $this->add_action_buttons(false, get_string('savecontinue', $p));
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
        foreach (\local_studentrecords\local\catalog::SCHOOL as $name => [$type]) {
            $v = trim((string)($data[$name] ?? ''));
            if ($type === 'percent' && $v !== '' && (!is_numeric($v) || $v <= 0 || $v > 100)) {
                $errors[$name] = get_string('err_percent', 'local_studentrecords');
            }
            if ($type === 'percent0' && $v !== '' && (!is_numeric($v) || $v < 0 || $v > 100)) {
                $errors[$name] = get_string('err_percent0', 'local_studentrecords');
            }
            if ($type === 'number' && $v !== '' && (!ctype_digit($v) || $v < 1 || $v > 10)) {
                $errors[$name] = get_string('err_padding', 'local_studentrecords');
            }
            if ($type === 'hours' && $v !== '' && (!is_numeric($v) || $v <= 0 || $v > 100)) {
                $errors[$name] = get_string('err_hoursvalue', 'local_studentrecords');
            }
        }
        return $errors;
    }
}
