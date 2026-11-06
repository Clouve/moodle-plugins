<?php

/**
 * The annual FERPA notice for the student: read it and acknowledge it (once per academic year).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\ferpa;
use local_studentrecords\local\students;

$p = 'local_studentrecords';
require_login(null, false);
require_capability('local/studentrecords:viewown', context_system::instance());
$student = students::get_by_user((int)$USER->id);
if (!$student) {
    throw new moodle_exception('norecord', $p);
}
$url = new moodle_url('/local/studentrecords/ferpa.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('ferpaannual', $p));
$PAGE->set_heading(get_string('pluginname', $p));
if (optional_param('ack', 0, PARAM_BOOL)) {
    require_sesskey();
    ferpa::acknowledge($student, 'online');
    redirect(
        new moodle_url('/local/studentrecords/student.php', ['userid' => $USER->id]),
        get_string('ferpaacked', $p),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ferpaannual', $p) . ' ' . ferpa::current_year());
$text = catalog::setting('ferpanotice');
echo $text !== '' ? html_writer::div(format_text($text, FORMAT_PLAIN), 'mb-3') :
    $OUTPUT->notification(get_string('ferpanotext', $p), 'info');
$ack = ferpa::acknowledgment((int)$student->id);
if ($ack) {
    echo $OUTPUT->notification(get_string('ferpaackedon', $p, catalog::show_date((int)$ack->acknowledged)), 'success');
} else if ($text !== '') {
    echo $OUTPUT->single_button(new moodle_url($url, ['ack' => 1, 'sesskey' => sesskey()]), get_string('ferpaack', $p), 'post');
}
echo $OUTPUT->footer();
