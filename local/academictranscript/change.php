<?php

/**
 * Request a change to a posted grade (teachers of the course, admins). An admin must approve it.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\posting;
use local_academictranscript\local\srpost;

$id = required_param('id', PARAM_INT);
$posted = $DB->get_record('local_academictranscript_posted', ['id' => $id], '*', MUST_EXIST);
$course = get_course($posted->courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/academictranscript:postgrades', $context);

$url = new moodle_url('/local/academictranscript/change.php', ['id' => $id]);
$back = new moodle_url('/local/academictranscript/post.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('requestchange', 'local_academictranscript'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

$student = core_user::get_user($posted->userid, '*', MUST_EXIST);
$term = $DB->get_field('local_academictranscript_term', 'name', ['id' => $posted->termid]);
// With Student Records as the transcript source, the change is a grade change request there, and the Registrar approves it.
// The request goes to the registration that got this grade: a course can run more than once.
$sr = srpost::posted_target($posted);
// A row with no registration behind it is not on that student's transcript, so a change here would never print.
$unlinked = !$sr && srpost::has_file((int)$posted->userid);
$grade = $sr ? $sr['grade'] : $posted->grade;
$current = $grade . ' — ' . fullname($student) . ' — ' . $posted->coursecode . ' — ' . $term;
$form = new \local_academictranscript\form\change_request($url, ['current' => $current,
    'letters' => $sr ? srpost::letters() : null]);
$form->set_data(['id' => $id, 'newgrade' => $grade, 'newnote' => $posted->note]);

if ($sr) {
    $pending = srpost::change_pending($sr['regid']);
} else {
    $pending = $DB->record_exists('local_academictranscript_change', ['postedid' => $id, 'status' => 'pending']);
}
if ($form->is_cancelled()) {
    redirect($back);
} else if (!$pending && !$unlinked && ($data = $form->get_data())) {
    if ($sr) {
        try {
            srpost::request_change($sr['regid'], $data->newgrade, $data->reason);
        } catch (moodle_exception $e) {
            redirect($back, s($e->getMessage()), null, \core\output\notification::NOTIFY_WARNING);
        }
        redirect(
            $back,
            get_string('changerequestedsr', 'local_academictranscript'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    posting::request_change($id, $data->newgrade, (string)$data->newnote, $data->reason);
    redirect($back, get_string('changerequested', 'local_academictranscript'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('requestchange', 'local_academictranscript'));
if ($unlinked) {
    echo $OUTPUT->notification(get_string('changeunlinked', 'local_academictranscript'), 'warning');
    echo $OUTPUT->continue_button($back);
} else if ($pending) {
    echo $OUTPUT->notification(get_string('changepending', 'local_academictranscript'), 'info');
    echo $OUTPUT->continue_button($back);
} else {
    echo html_writer::tag('p', get_string($sr ? 'changeintrosr' : 'changeintro', 'local_academictranscript'));
    $form->display();
}

$history = $DB->get_records('local_academictranscript_change', ['postedid' => $id], 'timerequested DESC');
if ($history) {
    echo $OUTPUT->heading(get_string('changehistory', 'local_academictranscript'), 4);
    $table = new html_table();
    $table->head = [get_string('date'), get_string('change', 'local_academictranscript'),
        get_string('reason', 'local_academictranscript'), get_string('requestedby', 'local_academictranscript'),
        get_string('status'), get_string('decidedby', 'local_academictranscript')];
    foreach ($history as $c) {
        $table->data[] = [userdate($c->timerequested, get_string('strftimedatetimeshort', 'langconfig')),
            s($c->oldgrade . ' → ' . $c->newgrade), s($c->reason), fullname(core_user::get_user($c->requestedby)),
            get_string('changestatus_' . $c->status, 'local_academictranscript'),
            $c->decidedby ? fullname(core_user::get_user($c->decidedby)) .
                ($c->decisionnote ? ': ' . s($c->decisionnote) : '') : ''];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
