<?php

/**
 * Admins: edit the student details printed on the transcript.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

$userid = required_param('userid', PARAM_INT);
$user = core_user::get_user($userid, '*', MUST_EXIST);

require_login(null, false);
$syscontext = context_system::instance();
require_capability('local/academictranscript:managerecords', $syscontext);

$url = new moodle_url('/local/academictranscript/record.php', ['userid' => $userid]);
$back = new moodle_url('/local/academictranscript/index.php', ['userid' => $userid]);
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('editdetails', 'local_academictranscript'));
$PAGE->set_heading(fullname($user));

$record = $DB->get_record('local_academictranscript_student', ['userid' => $userid]);
$form = new \local_academictranscript\form\student_record($url);
$defaults = $record ? clone $record : new stdClass();
$defaults->userid = $userid;
if (!$record || trim((string)$record->studentid) === '') {
    $defaults->studentid = $user->idnumber;
}
$form->set_data($defaults);

if ($form->is_cancelled()) {
    redirect($back);
} else if ($data = $form->get_data()) {
    $new = (object)[
        'userid' => $userid,
        'studentid' => trim($data->studentid),
        'programid' => empty($data->programid) ? null : (int)$data->programid,
        'qualification' => trim($data->qualification),
        'dob' => empty($data->dob) ? null : $data->dob,
        'admitterm' => empty($data->admitterm) ? null : (int)$data->admitterm,
        'status' => in_array($data->status, \local_academictranscript\local\transcript::STATUSES) ? $data->status : null,
        'awarddate' => empty($data->awarddate) ? null : $data->awarddate,
        'usermodified' => $USER->id,
        'timemodified' => time(),
    ];
    if ($record) {
        $new->id = $record->id;
        $DB->update_record('local_academictranscript_student', $new);
    } else {
        $DB->insert_record('local_academictranscript_student', $new);
    }
    redirect($back, get_string('detailssaved', 'local_academictranscript'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editdetails', 'local_academictranscript') . ': ' . fullname($user));
$form->display();
echo $OUTPUT->footer();
