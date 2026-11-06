<?php

/**
 * Post final grades for the students of one course. Posting copies the grade to the transcript
 * and locks the student's course total in the gradebook; later changes need admin approval.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\posting;
use local_academictranscript\local\srpost;
use local_academictranscript\local\terms;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/academictranscript:postgrades', $context);

$url = new moodle_url('/local/academictranscript/post.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('postgrades', 'local_academictranscript'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

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

if (data_submitted() && confirm_sesskey()) {
    $selected = optional_param_array('post', [], PARAM_INT);
    $ok = 0;
    $errors = [];
    foreach ($selected as $uid => $checked) {
        if (!$checked || !isset($students[$uid])) {
            continue;
        }
        try {
            posting::post($course->id, (int)$uid);
            $ok++;
        } catch (moodle_exception $e) {
            $errors[] = fullname($students[$uid]) . ': ' . $e->getMessage();
        }
    }
    $message = get_string('postedcount', 'local_academictranscript', $ok);
    if ($errors) {
        $message .= html_writer::alist(array_map('s', $errors));
    }
    redirect($url, $message, null, $errors ? \core\output\notification::NOTIFY_WARNING :
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('postgrades', 'local_academictranscript'));
echo html_writer::tag('p', get_string(srpost::active() ? 'postintrosr' : 'postintro', 'local_academictranscript'));

if (!$students) {
    echo $OUTPUT->notification(get_string('nostudents', 'local_academictranscript'), 'info');
    echo $OUTPUT->footer();
    die();
}

$termnames = terms::menu(false);
$canpostany = false;
$table = new html_table();
$table->head = [get_string('studentname', 'local_academictranscript'), get_string('term', 'local_academictranscript'),
    get_string('tobeposted', 'local_academictranscript'), get_string('status'), get_string('post', 'local_academictranscript')];
foreach ($students as $uid => $u) {
    $p = posting::preview($course->id, $uid);
    $term = $p['termid'] ? ($termnames[$p['termid']] ?? '') : get_string('termnotset', 'local_academictranscript');
    $percent = $p['percent'] !== null ? ' (' . format_float($p['percent'], 2) . '%)' : '';
    $grade = $p['grade'] === null ? '-' : $p['grade'] . $percent;
    if ($p['posted']) {
        $status = get_string('postedon', 'local_academictranscript', ['grade' => s($p['posted']->grade),
            'date' => userdate($p['posted']->timeposted, get_string('strftimedatetimeshort', 'langconfig'))]);
        $linked = srpost::posted_target($p['posted']);
        if ($linked && $linked['grade'] !== '' && $linked['grade'] !== $p['posted']->grade) {
            $status .= ' ' . get_string('nowinsr', 'local_academictranscript', s($linked['grade']));
        }
        $status .= ' ' . html_writer::link(
            new moodle_url('/local/academictranscript/change.php', ['id' => $p['posted']->id]),
            get_string('requestchange', 'local_academictranscript')
        );
        $box = '';
    } else if ($p['error']) {
        $status = html_writer::span(s($p['error']), 'text-danger');
        $box = '';
    } else {
        $status = get_string('notposted', 'local_academictranscript');
        $box = html_writer::checkbox("post[$uid]", 1, false, '', ['aria-label' => get_string('post', 'local_academictranscript')]);
        $canpostany = true;
    }
    $table->data[] = [fullname($u), $term, s($grade), $status, $box];
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false),
    'onsubmit' => 'return confirm(' . json_encode(get_string('postconfirm', 'local_academictranscript')) . ');']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::table($table);
if ($canpostany) {
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
        'value' => get_string('postselected', 'local_academictranscript')]);
}
echo html_writer::end_tag('form');

$older = $DB->get_records_sql("SELECT p.*, t.name AS termname
                                 FROM {local_academictranscript_posted} p
                            LEFT JOIN {local_academictranscript_term} t ON t.id = p.termid
                                WHERE p.courseid = ?
                             ORDER BY p.timeposted DESC", [$course->id]);
if ($older) {
    echo $OUTPUT->heading(get_string('postedattempts', 'local_academictranscript'), 4);
    $t2 = new html_table();
    $t2->head = [get_string('studentname', 'local_academictranscript'), get_string('term', 'local_academictranscript'),
        get_string('col_grade', 'local_academictranscript'), get_string('postedby', 'local_academictranscript'), ''];
    foreach ($older as $p) {
        $who = core_user::get_user($p->userid);
        $t2->data[] = [$who ? fullname($who) : $p->userid, s((string)$p->termname), s($p->grade),
            fullname(core_user::get_user($p->postedby)) . ', ' . userdate(
                $p->timeposted,
                get_string('strftimedatetimeshort', 'langconfig')
            ),
            html_writer::link(
                new moodle_url('/local/academictranscript/change.php', ['id' => $p->id]),
                get_string('requestchange', 'local_academictranscript')
            )];
    }
    echo html_writer::table($t2);
}
echo $OUTPUT->footer();
