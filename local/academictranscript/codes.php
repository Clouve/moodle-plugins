<?php

/**
 * Hand-entered transcript codes (P, NP, I, NC, W) and notes for the students of one course.
 * The gradebook is never changed; the code replaces the letter grade on the transcript only.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

use local_academictranscript\local\posting;
use local_academictranscript\local\srpost;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/academictranscript:entercodes', $context);

$url = new moodle_url('/local/academictranscript/codes.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('transcriptcodes', 'local_academictranscript'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

// Students of this course.
$students = [];
foreach (array_keys(get_archetype_roles('student')) as $roleid) {
    foreach (
        get_role_users(
            $roleid,
            $context,
            false,
            'u.id, ' . implode(', ', array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields())),
            'u.lastname, u.firstname'
        ) as $u
    ) {
        $students[$u->id] = $u;
    }
}
$existing = $DB->get_records('local_academictranscript_code', ['courseid' => $course->id], '', 'userid, id, code, note, reason,
    usermodified, timemodified');

$errors = [];
if (data_submitted() && confirm_sesskey()) {
    $codes = optional_param_array('code', [], PARAM_ALPHANUMEXT);
    $notes = optional_param_array('note', [], PARAM_TEXT);
    $reasons = optional_param_array('reason', [], PARAM_TEXT);
    $changed = 0;
    foreach ($students as $uid => $u) {
        $code = strtoupper($codes[$uid] ?? '');
        $code = isset(posting::marks_for(srpost::target($course->id, $uid))[$code]) ? $code : '';
        $note = core_text::substr(trim($notes[$uid] ?? ''), 0, 255);
        $reason = core_text::substr(trim($reasons[$uid] ?? ''), 0, 255);
        $old = $existing[$uid] ?? null;
        $oldcode = $old ? (string)$old->code : '';
        $oldnote = $old ? (string)$old->note : '';
        $oldreason = $old ? (string)$old->reason : '';
        if ($code === $oldcode && $note === $oldnote && $reason === $oldreason) {
            continue;
        }
        if ($code !== $oldcode && $reason === '') {
            $errors[] = get_string('reasonrequired', 'local_academictranscript', fullname($u));
            continue;
        }
        $record = (object)['userid' => $uid, 'courseid' => $course->id, 'code' => $code === '' ? null : $code,
            'note' => $note, 'reason' => $reason, 'usermodified' => $USER->id, 'timemodified' => time()];
        if ($old) {
            $record->id = $old->id;
            $DB->update_record('local_academictranscript_code', $record);
        } else {
            $DB->insert_record('local_academictranscript_code', $record);
        }
        \local_academictranscript\event\code_updated::create([
            'context' => $context,
            'relateduserid' => $uid,
            'other' => ['oldcode' => $oldcode, 'newcode' => $code, 'oldnote' => $oldnote, 'newnote' => $note,
                'reason' => $reason],
        ])->trigger();
        $changed++;
    }
    if (!$errors) {
        redirect(
            $url,
            get_string('codessaved', 'local_academictranscript', $changed),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $existing = $DB->get_records('local_academictranscript_code', ['courseid' => $course->id], '', 'userid, id, code, note, reason,
        usermodified, timemodified');
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('transcriptcodes', 'local_academictranscript'));
echo html_writer::tag('p', get_string('codesintro', 'local_academictranscript'));
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/local/academictranscript/post.php', ['courseid' => $course->id]),
    get_string('postgrades', 'local_academictranscript')
));
foreach ($errors as $error) {
    echo $OUTPUT->notification(s($error), 'error');
}

if (!$students) {
    echo $OUTPUT->notification(get_string('nostudents', 'local_academictranscript'), 'info');
    echo $OUTPUT->footer();
    die();
}

$item = grade_item::fetch_course_item($course->id);
$menu = function (array $marks): array {
    $options = ['' => get_string('code_none', 'local_academictranscript')];
    foreach ($marks as $c => $label) {
        $options[$c] = s($c) . ' - ' . $label;
    }
    return $options;
};

$table = new html_table();
$table->head = [get_string('studentname', 'local_academictranscript'), get_string('coursetotal', 'local_academictranscript'),
    get_string('col_code_manual', 'local_academictranscript'), get_string('col_note', 'local_academictranscript'),
    get_string('reason', 'local_academictranscript'), get_string('lastchanged', 'local_academictranscript'),
    get_string('postedcol', 'local_academictranscript')];
foreach ($students as $uid => $u) {
    $old = $existing[$uid] ?? null;
    $target = srpost::target($course->id, $uid);
    $total = '';
    if ($item) {
        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $uid]);
        if ($grade && $grade->finalgrade !== null && $item->grademax > $item->grademin) {
            $pct = grade_grade::standardise_score(
                (float)$grade->finalgrade,
                (float)$item->grademin,
                (float)$item->grademax,
                0,
                100
            );
            $total = format_float($pct, 2) . '% (' . (posting::letter($pct, $target) ?? '-') . ')';
        }
    }
    $changedby = '';
    if ($old && $old->usermodified) {
        $changedby = userdate($old->timemodified, get_string('strftimedatetimeshort', 'langconfig')) . ', '
            . fullname(core_user::get_user($old->usermodified));
    }
    $table->data[] = [
        html_writer::link(new moodle_url('/local/academictranscript/index.php', ['userid' => $uid]), fullname($u)),
        $total,
        html_writer::select(
            $menu(posting::marks_for($target)),
            "code[$uid]",
            $old ? (string)$old->code : '',
            false,
            ['class' => 'custom-select', 'aria-label' => get_string('col_code_manual', 'local_academictranscript')]
        ),
        html_writer::empty_tag('input', ['type' => 'text', 'name' => "note[$uid]", 'value' => $old ? $old->note : '',
            'maxlength' => 255, 'size' => 30, 'class' => 'form-control',
            'aria-label' => get_string('col_note', 'local_academictranscript')]),
        html_writer::empty_tag('input', ['type' => 'text', 'name' => "reason[$uid]", 'value' => $old ? $old->reason : '',
            'maxlength' => 255, 'size' => 30, 'class' => 'form-control',
            'aria-label' => get_string('reason', 'local_academictranscript')]),
        $changedby,
        ($pv = posting::preview($course->id, $uid)['posted']) ?
            s($pv->grade) . ' (' . userdate($pv->timeposted, get_string('strftimedate', 'langconfig')) . ')' : '-',
    ];
}
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::table($table);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('savechanges')]);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
