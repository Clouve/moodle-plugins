<?php

/**
 * Enrollment verification letter: release check (FERPA), facts, issue (logged), PDF download of an issued letter.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\letters;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\student_sections;
use local_studentrecords\local\students;

$id = optional_param('id', 0, PARAM_INT);
$letterid = optional_param('letter', 0, PARAM_INT);
$p = 'local_studentrecords';
require_login(null, false);
require_capability('local/studentrecords:managestudents', context_system::instance());

if ($letterid) {
    $letter = $DB->get_record(letters::TABLE, ['id' => $letterid], '*', MUST_EXIST);
    $student = students::get((int)$letter->studentid);
    \local_studentrecords\local\audit::log('view', 'letter', $letterid, (int)$student->userid, $letter->letterno);
    $content = letters::pdf($letter);
    send_file($content, $letter->letterno . '.pdf', 0, 0, true, true, 'application/pdf');
    die();
}

$student = students::get($id);
$url = new moodle_url('/local/studentrecords/letter.php', ['id' => $id]);
$back = new moodle_url('/local/studentrecords/student.php', ['id' => $id]);
staffarea::start_page($url, 'students');
$PAGE->navbar->add(get_string('studentrecords', $p), new moodle_url('/local/studentrecords/students.php'));
$PAGE->navbar->add(students::display_name($student), $back);
$PAGE->navbar->add(get_string('enrollmentletter', $p), $url);
$form = new \local_studentrecords\form\letter($url);
$form->set_data(['id' => $id]);
if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    $newid = letters::issue($student, (string)$data->recipient);
    redirect($back, get_string('letterissued', $p, html_writer::link(
        new moodle_url($url, ['letter' => $newid]),
        get_string('downloadletter', $p)
    )), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo staffarea::header();
echo $OUTPUT->heading(get_string('enrollmentletter', $p) . ': ' . s(students::display_name($student)));
echo student_sections::release_check($student);
$facts = letters::facts($student);
$table = new html_table();
foreach (['studentid', 'program', 'status', 'term', 'termdates', 'hours', 'load', 'award'] as $k) {
    $table->data[] = [html_writer::tag('strong', get_string('letterfact_' . $k, $p)), s((string)$facts[$k])];
}
echo $OUTPUT->heading(get_string('letterpreview', $p), 4) . html_writer::table($table);
$form->display();
echo $OUTPUT->footer();
