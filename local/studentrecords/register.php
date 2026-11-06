<?php

/**
 * Registration of one student: register in an offering, drop, withdraw, enter a final grade (records staff).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\registration;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$id = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$regid = optional_param('reg', 0, PARAM_INT);
$p = 'local_studentrecords';
if (!in_array($action, ['add', 'drop', 'withdraw', 'grade'], true)) {
    throw new moodle_exception('invalidparameter', 'debug');
}
require_login(null, false);
require_capability('local/studentrecords:managestudents', context_system::instance());
$student = students::get($id);
$url = new moodle_url('/local/studentrecords/register.php', array_filter(['id' => $id, 'action' => $action, 'reg' => $regid]));
$back = new moodle_url('/local/studentrecords/student.php', ['id' => $id]);
staffarea::start_page($url, 'students');
$PAGE->navbar->add(get_string('studentrecords', $p), new moodle_url('/local/studentrecords/students.php'));
$PAGE->navbar->add(students::display_name($student), $back);
$PAGE->navbar->add(get_string('action_' . $action, $p), $url);
$custom = ['action' => $action];
$reg = null;
if ($action !== 'add') {
    $reg = $DB->get_record(registration::REG, ['id' => $regid, 'studentid' => $id], '*', MUST_EXIST);
    $offer = registration::offer((int)$reg->offerid);
    $custom['text'] = s($offer->code . ' ' . $offer->section) . ' - ' . format_string($offer->title) . ' (' .
        format_string($offer->termname) . ')';
}

if ($action === 'add') {
    $offers = [];
    foreach (registration::offers(0, true) as $o) {
        $offers[$o->id] = format_string($o->termname) . ': ' . s($o->code . ' ' . $o->section) . ' - ' .
            format_string($o->title) . ' (' . (int)$o->taken . '/' . ($o->capacity ?? '-') . ')';
    }
    $form = new \local_studentrecords\form\register($url, ['offers' => $offers]);
} else if ($action === 'grade') {
    $form = new \local_studentrecords\form\regrade($url, $custom);
} else {
    $form = new \local_studentrecords\form\endreg($url, $custom);
}
$form->set_data(['id' => $id, 'action' => $action, 'reg' => $regid] + ($reg && $reg->grade !== null ? ['grade' => $reg->grade,
    'gradepct' => $reg->gradepct === null ? '' : format_float($reg->gradepct, -1)] : []));
if ($form->is_cancelled()) {
    redirect($back);
}
$error = '';
if ($data = $form->get_data()) {
    try {
        if ($action === 'add') {
            registration::add($student, (int)$data->offerid, !empty($data->override), (string)$data->note);
        } else if ($action === 'grade') {
            registration::set_grade($regid, $data->grade, $data->gradepct === '' ? null : (float)$data->gradepct, 'staff');
        } else {
            registration::end($regid, $action, !empty($data->override), (string)$data->note);
        }
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo staffarea::header();
echo $OUTPUT->heading(s(students::display_name($student)) . ': ' . get_string('action_' . $action, $p));
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}
if ($action === 'add') {
    echo html_writer::tag('p', get_string('register_intro', $p));
}
$form->display();
echo $OUTPUT->footer();
