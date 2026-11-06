<?php

/**
 * View as student: records staff see a student's home and academic record as the student sees them (read only,
 * every view is logged). The staff member stays logged in as themselves.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\access;
use local_studentrecords\local\audit;
use local_studentrecords\local\homes;
use local_studentrecords\local\record_view;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$id = required_param('id', PARAM_INT);
$p = 'local_studentrecords';
require_login(null, false);
staffarea::setup('students', new moodle_url('/local/studentrecords/viewas.php', ['id' => $id]));
$student = students::get($id);
$name = students::display_name($student, access::hide_directory($student));
$PAGE->set_heading(get_string('viewas_title', $p, $name));
$PAGE->navbar->add($name, new moodle_url('/local/studentrecords/student.php', ['id' => $id]));
$PAGE->navbar->add(get_string('file_viewas', $p));
audit::log('view', 'student', (int)$student->id, (int)$student->userid, 'view as student');

echo staffarea::header();
echo $OUTPUT->notification(get_string('viewas_banner', $p, s($name)), \core\output\notification::NOTIFY_INFO);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/studentrecords/student.php', ['id' => $id]),
    get_string('viewas_back', $p),
    ['class' => 'btn btn-outline-secondary']
), 'sr-actions');
echo html_writer::start_div('sr-viewas');
echo html_writer::tag('h2', get_string('nav_myhome', $p), ['class' => 'sr-h2']);
echo homes::student($student);
echo html_writer::tag('h2', get_string('nav_myacademicrecord', $p), ['class' => 'sr-h2 mt-5']);
echo record_view::render($student);
echo html_writer::end_div();
echo $OUTPUT->footer();
