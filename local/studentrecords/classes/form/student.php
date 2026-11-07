<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A student record.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class student extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $catalog = \local_studentrecords\local\catalog::class;
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        if (empty($this->_customdata['id'])) {
            $m->addElement('autocomplete', 'userid', get_string('moodleuser', $p), [], [
                'ajax' => 'core_user/form_user_selector', 'multiple' => false]);
            $m->addRule('userid', null, 'required', null, 'client');
        }
        $m->addElement('header', 'h_identity', get_string('sec_identity', $p));
        $m->addElement('text', 'studentid', get_string('studentid', $p), ['size' => 20]);
        $m->setType('studentid', PARAM_TEXT);
        if (\local_studentrecords\local\admissions::auto_id()) {
            $m->addHelpButton('studentid', 'studentidauto', $p);
        } else {
            $m->addRule('studentid', null, 'required', null, 'client');
        }
        foreach (['legalfirstname', 'legalmiddlename', 'legallastname', 'preferredname'] as $f) {
            $m->addElement('text', $f, get_string($f, $p), ['size' => 40]);
            $m->setType($f, PARAM_TEXT);
        }
        $m->addRule('legalfirstname', null, 'required', null, 'client');
        $m->addRule('legallastname', null, 'required', null, 'client');
        // Date of birth: three menus that start blank, so no date is saved unless staff pick one.
        $days = ['' => get_string('day', 'form')] + array_combine(range(1, 31), range(1, 31));
        $months = ['' => get_string('month', 'form')];
        foreach (range(1, 12) as $month) {
            $months[$month] = userdate(gmmktime(12, 0, 0, $month, 15, 2000), '%B', 'UTC', false);
        }
        $thisyear = (int)date('Y');
        $years = ['' => get_string('year', 'form')] + array_combine(range($thisyear, 1920), range($thisyear, 1920));
        $m->addGroup([
            $m->createElement('select', 'dob_day', get_string('day', 'form'), $days),
            $m->createElement('select', 'dob_month', get_string('month', 'form'), $months),
            $m->createElement('select', 'dob_year', get_string('year', 'form'), $years),
        ], 'dobgroup', get_string('dob', $p), ' ', false);
        $m->addHelpButton('dobgroup', 'dob', $p);
        $m->addElement('header', 'h_contact', get_string('sec_contact', $p));
        $m->addElement('text', 'email', get_string('email'), ['size' => 40]);
        $m->setType('email', PARAM_EMAIL);
        $m->addElement('text', 'phone', get_string('phone', $p), ['size' => 20]);
        $m->setType('phone', PARAM_TEXT);
        $m->addElement('textarea', 'usaddress', get_string('usaddress', $p), ['rows' => 3, 'cols' => 50]);
        $m->setType('usaddress', PARAM_TEXT);
        $m->addElement('textarea', 'homeaddress', get_string('homeaddress', $p), ['rows' => 3, 'cols' => 50]);
        $m->setType('homeaddress', PARAM_TEXT);
        $m->addElement(
            'select',
            'homecountry',
            get_string('homecountry', $p),
            ['' => get_string('none')] + get_string_manager()->get_list_of_countries()
        );
        $m->addElement('header', 'h_academic', get_string('sec_academic', $p));
        if (!empty($this->_customdata['own'])) {
            $m->addElement('static', 'ownnote', '', get_string('ownrecord_note', $p));
        }
        $m->addElement('select', 'programid', get_string('program', $p), [0 => get_string('none')] + $catalog::programs());
        $m->addElement('select', 'admittermid', get_string('admitterm', $p), [0 => get_string('none')] +
            array_map('format_string', $catalog::terms()));
        $m->addElement('text', 'catalogyear', get_string('catalogyear', $p), ['size' => 12]);
        $m->setType('catalogyear', PARAM_TEXT);
        $m->addHelpButton('catalogyear', 'catalogyear', $p);
        $statuses = [];
        foreach ($catalog::STATUSES as $s) {
            $statuses[$s] = get_string('status_' . $s, $p);
        }
        $m->addElement('select', 'status', get_string('status', $p), $statuses);
        $m->addElement('text', 'statusnote', get_string('statusnote', $p), ['size' => 60]);
        $m->setType('statusnote', PARAM_TEXT);
        $m->addHelpButton('statusnote', 'statusnote', $p);
        $m->addElement('date_selector', 'withdrawdate', get_string('withdrawdate', $p), ['optional' => true]);
        $m->addElement('date_selector', 'lastattended', get_string('lastattended', $p), ['optional' => true]);
        $m->addHelpButton('lastattended', 'lastattended', $p);
        $m->addElement('select', 'loadtype', get_string('loadtype', $p), ['' => get_string('none'),
            'fulltime' => get_string('load_fulltime', $p), 'parttime' => get_string('load_parttime', $p)]);
        $m->addElement('select', 'advisorid', get_string('advisor', $p), [0 => get_string('none')] +
            array_map('s', $catalog::faculty()));
        $m->addElement('text', 'awardname', get_string('awardname', $p), ['size' => 50]);
        $m->setType('awardname', PARAM_TEXT);
        $m->addElement('date_selector', 'awarddate', get_string('awarddate', $p), ['optional' => true]);
        if (!empty($this->_customdata['own'])) {
            $m->hardFreeze(['programid', 'status', 'statusnote', 'awardname', 'awarddate']);
        }
        $m->addElement('header', 'h_reporting', get_string('sec_reporting', $p));
        $choices = [
            'residency' => ['instate', 'outofstate', 'international'],
            'citizenship' => ['citizen', 'permanent', 'other'],
            'firstgen' => ['yes', 'no', 'unknown'],
        ];
        foreach ($choices as $f => $values) {
            $options = ['' => get_string('notset', $p)];
            foreach ($values as $v) {
                $options[$v] = get_string($f . '_' . $v, $p);
            }
            $m->addElement('select', $f, get_string($f, $p), $options);
        }
        $m->addHelpButton('firstgen', 'firstgen', $p);
        $m->addElement('text', 'highschool', get_string('highschool', $p), ['size' => 50]);
        $m->setType('highschool', PARAM_TEXT);
        $m->addElement('text', 'hsyear', get_string('hsyear', $p), ['size' => 6]);
        $m->setType('hsyear', PARAM_RAW_TRIMMED);
        $m->addElement('header', 'h_privacy', get_string('sec_privacy', $p));
        $m->addElement('advcheckbox', 'ferpaoptout', get_string('ferpaoptout', $p));
        $m->addHelpButton('ferpaoptout', 'ferpaoptout', $p);
        if ($catalog::module_on('f1')) {
            $m->addElement('header', 'h_f1', get_string('sec_f1', $p));
            $m->addElement('advcheckbox', 'isf1', get_string('isf1', $p));
            $m->addElement('text', 'visatype', get_string('visatype', $p), ['size' => 10]);
            $m->setType('visatype', PARAM_TEXT);
            $m->addElement('text', 'sevisid', get_string('sevisid', $p), ['size' => 15]);
            $m->setType('sevisid', PARAM_TEXT);
        }
        $m->addElement('header', 'h_docs', get_string('sec_documents', $p));
        $m->addElement('filemanager', 'documents', get_string('documents', $p), null, ['maxfiles' => 20, 'subdirs' => 0]);
        $m->addElement('textarea', 'notes', get_string('notes', $p), ['rows' => 3, 'cols' => 60]);
        $m->setType('notes', PARAM_TEXT);
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
        global $DB, $USER;
        $errors = parent::validation($data, $files);
        $hsyear = trim((string)($data['hsyear'] ?? ''));
        if ($hsyear !== '' && (!ctype_digit($hsyear) || (int)$hsyear < 1900 || (int)$hsyear > 2100)) {
            $errors['hsyear'] = get_string('err_year', 'local_studentrecords');
        }
        $other = trim($data['studentid']) === '' ? false :
            $DB->get_field('local_studentrecords_student', 'id', ['studentid' => trim($data['studentid'])]);
        if (trim($data['studentid']) === '' && !\local_studentrecords\local\admissions::auto_id()) {
            $errors['studentid'] = get_string('required');
        }
        foreach (
            \local_studentrecords\local\admissions::status_problems(
                $data['status'],
                empty($data['withdrawdate']) ? null : (int)$data['withdrawdate'],
                empty($data['lastattended']) ? null : (int)$data['lastattended']
            ) as $field => $key
        ) {
            $errors[$field] = get_string($key, 'local_studentrecords');
        }
        if ($other && $other != $data['id']) {
            $errors['studentid'] = get_string('err_duplicate', 'local_studentrecords');
        }
        if (
            empty($data['id']) && !empty($data['userid']) &&
                $DB->record_exists('local_studentrecords_student', ['userid' => $data['userid']])
        ) {
            $errors['userid'] = get_string('err_hasrecord', 'local_studentrecords');
        }
        if (empty($data['id']) && (int)($data['userid'] ?? 0) === (int)$USER->id) {
            $errors['userid'] = get_string('err_ownrecord', 'local_studentrecords');
        }
        $dob = \local_studentrecords\local\dates::parts_to_ymd(
            $data['dob_day'] ?? '',
            $data['dob_month'] ?? '',
            $data['dob_year'] ?? ''
        );
        if ($dob === false) {
            $errors['dobgroup'] = get_string('err_dob', 'local_studentrecords');
        } else if ($dob !== null && $dob > date('Y-m-d')) {
            $errors['dobgroup'] = get_string('err_dobfuture', 'local_studentrecords');
        }
        if (!empty($data['email']) && !validate_email($data['email'])) {
            $errors['email'] = get_string('invalidemail');
        }
        return $errors;
    }
}
