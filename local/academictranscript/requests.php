<?php

/**
 * Transcript requests (registrar): pending, on hold and recently issued Official transcript requests.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\requests;
use local_academictranscript\local\transcript;

require_login(null, false);
$syscontext = context_system::instance();
require_capability('local/academictranscript:issueofficial', $syscontext);
$at = 'local_academictranscript';
$url = new moodle_url('/local/academictranscript/requests.php');
$PAGE->set_context($syscontext);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('queue_title', $at));
$PAGE->set_heading(get_string('queue_title', $at));

$do = optional_param('do', '', PARAM_ALPHA);
if ($do !== '') {
    require_sesskey();
    $rid = required_param('request', PARAM_INT);
    try {
        if ($do === 'issue' || $do === 'reissue') {
            $issue = requests::issue($rid, $do === 'reissue');
            $r = $DB->get_record(requests::TABLE, ['id' => $rid]);
            redirect(
                $url,
                get_string('queue_issued', $at, (object)['name' => s(fullname(core_user::get_user((int)$r->userid))),
                'to' => s($r->recipient), 'code' => transcript::format_code($issue->verifycode)]),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } else if ($do === 'cancel') {
            requests::cancel($rid, optional_param('note', '', PARAM_TEXT));
            redirect($url, get_string('queue_cancelled', $at), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (moodle_exception $e) {
        // A second click on Issue or Reissue isn't a failure: the first one made the copy.
        $level = in_array($e->errorcode, ['req_err_issued', 'req_err_justissued'], true) ?
            \core\output\notification::NOTIFY_INFO : \core\output\notification::NOTIFY_ERROR;
        redirect($url, s($e->getMessage()), null, $level);
    }
}

$all = $DB->get_records(requests::TABLE, null, 'timecreated ASC, id ASC');
$groups = ['pending' => [], 'waiting' => [], 'onhold' => [], 'issued' => []];
$today = usergetdate(time());
$monthstart = make_timestamp($today['year'], $today['mon'], 1);
$issuedmonth = 0;
foreach ($all as $r) {
    $state = requests::state($r);
    if ($state === 'issued') {
        $issuedmonth += (int)$r->timeissued >= $monthstart ? 1 : 0;
    }
    if (isset($groups[$state])) {
        $groups[$state][] = $r;
    }
}
$button = function (string $do, int $rid, string $label, string $class) use ($url) {
    return html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',
        'value' => sesskey()]) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => $do]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'request', 'value' => $rid]) .
        html_writer::tag('button', $label, ['type' => 'submit', 'class' => $class]), ['method' => 'post',
        'action' => $url->out_omit_querystring(), 'class' => 'd-inline']);
};
// Nobody issues their own transcript, so their own row shows why in place of the button.
$issue = function (string $do, \stdClass $r, string $label, string $class) use ($button, $at) {
    global $USER;
    return (int)$r->userid === (int)$USER->id ? html_writer::span(get_string('issueown', $at), 'text-muted small') :
        $button($do, (int)$r->id, $label, $class);
};
$cancel = function (\stdClass $r) use ($url, $at) {
    $id = 'queuecancel' . $r->id;
    $form = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do', 'value' => 'cancel']) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'request', 'value' => $r->id]) .
        html_writer::label(get_string('queue_cancelreason', $at), $id) .
        html_writer::empty_tag('input', ['type' => 'text', 'name' => 'note', 'id' => $id, 'maxlength' => 255,
            'class' => 'form-control form-control-sm']);
    if ($r->charged) {
        $form .= html_writer::tag('p', get_string('queue_cancelfee', $at, get_string(
            'req_money',
            $at,
            format_float((float)$r->fee, 2)
        )), ['class' => 'small']);
    }
    $form .= html_writer::tag('button', get_string('queue_cancel', $at), ['type' => 'submit',
        'class' => 'btn btn-outline-danger btn-sm']);
    return html_writer::tag('details', html_writer::tag('summary', get_string('queue_cancel', $at), [
        'class' => 'btn btn-outline-secondary btn-sm',
    ]) . html_writer::tag('form', $form, ['method' => 'post', 'action' => $url->out_omit_querystring()]), ['class' => 'at-cancel']);
};
$student = function (\stdClass $r) {
    $u = core_user::get_user((int)$r->userid);
    return html_writer::link(new moodle_url('/local/academictranscript/index.php', ['userid' => $r->userid]), s(fullname($u)));
};
$date = fn(int $t) => userdate($t, get_string('strftimedateshortmonthabbr', 'langconfig'));
$what = fn(\stdClass $r) => get_string('req_delivery_' . $r->delivery, 'local_academictranscript') .
    ($r->timing !== 'now' ? ' · ' . get_string('req_timing_' . $r->timing, 'local_academictranscript') : '') .
    ((float)$r->fee > 0 ? ' · ' . get_string('req_money', 'local_academictranscript', format_float((float)$r->fee, 2)) : '');

echo $OUTPUT->header();
if (class_exists('\\local_studentrecords\\local\\shell')) {
    echo \local_studentrecords\local\shell::inline_menu();
}
$tile = fn($label, $value, $tone = '') => html_writer::div(html_writer::div(s($label), 'sr-tile-label') .
    html_writer::div((string)$value, 'sr-tile-value' . ($tone ? ' sr-tone-' . $tone : '')), 'sr-tile');
echo html_writer::div($tile(get_string('queue_pending', $at), count($groups['pending']) + count($groups['waiting'])) .
    $tile(get_string('queue_onhold', $at), count($groups['onhold']), count($groups['onhold']) ? 'warn' : '') .
    $tile(get_string('queue_issuedmonth', $at), $issuedmonth), 'sr-tiles sr-tiles-3');

// Pending and waiting.
echo html_writer::tag('h2', get_string('queue_pending', $at), ['class' => 'sr-h2']);
$t = new html_table();
$t->attributes['class'] = 'sr-table';
$t->head = [get_string('queue_student', $at), get_string('queue_type', $at), get_string('req_sentto', $at),
    get_string('req_requested', $at), ''];
foreach (array_merge($groups['pending'], $groups['waiting']) as $r) {
    $to = s($r->recipient) . ($r->email ? html_writer::div(s($r->email), 'sr-msg-meta') : '') .
        ($r->address ? html_writer::div(nl2br(s($r->address)), 'sr-msg-meta') : '');
    $action = requests::state($r) === 'waiting' ? html_writer::span(
        get_string('queue_waits_' . $r->timing, $at),
        'sr-tag sr-tag-neutral'
    ) : $issue('issue', $r, get_string('queue_issue', $at), 'btn btn-primary btn-sm');
    $t->data[] = [$student($r), s($what($r)), $to, $date((int)$r->timecreated),
        html_writer::div($action . $cancel($r), 'at-actions')];
}
echo $t->data ? html_writer::table($t) : html_writer::div(get_string('queue_nopending', $at), 'sr-empty');

// On hold.
echo html_writer::tag('h2', get_string('queue_onhold', $at), ['class' => 'sr-h2']);
$t = new html_table();
$t->attributes['class'] = 'sr-table';
$t->head = [get_string('queue_student', $at), get_string('queue_reason', $at), get_string('req_requested', $at), ''];
foreach ($groups['onhold'] as $r) {
    $reasons = implode(' ', array_map(
        fn($h) => html_writer::span(s($h), 'sr-tag sr-tag-warn'),
        requests::holds((int)$r->userid)
    ));
    $link = '';
    if ($sr = \local_studentrecords\local\students::get_by_user((int)$r->userid)) {
        $link = html_writer::link(
            new moodle_url('/local/studentrecords/student.php', ['id' => $sr->id, 'tab' => 'holds']),
            get_string('queue_viewhold', $at)
        );
    }
    $t->data[] = [$student($r), $reasons, $date((int)$r->timecreated), html_writer::div($link . $cancel($r), 'at-actions')];
}
echo $t->data ? html_writer::table($t) : html_writer::div(get_string('queue_noonhold', $at), 'sr-empty');

// Recently issued.
echo html_writer::tag('h2', get_string('queue_recent', $at), ['class' => 'sr-h2']);
$t = new html_table();
$t->attributes['class'] = 'sr-table';
$t->head = [get_string('queue_student', $at), get_string('req_sentto', $at), get_string('queue_issuedon', $at),
    get_string('verifycode', $at), ''];
foreach (array_slice(array_reverse($groups['issued']), 0, 20) as $r) {
    $issue = $r->issueid ? $DB->get_record('local_academictranscript_issue', ['id' => $r->issueid]) : null;
    $t->data[] = [$student($r), s($r->recipient) . ' · ' . get_string('req_delivery_' . $r->delivery, $at),
        $date((int)$r->timeissued), $issue ? s(transcript::format_code($issue->verifycode)) : '',
        ($issue ? html_writer::link(
            new moodle_url('/local/academictranscript/issued.php', ['id' => $issue->id]),
            get_string('download'),
            ['class' => 'me-2']
        ) : '') . $issue(
            'reissue',
            $r,
            get_string('queue_reissue', $at),
            'btn btn-outline-secondary btn-sm'
        )];
}
echo $t->data ? html_writer::table($t) : html_writer::div(get_string('queue_noissued', $at), 'sr-empty');
echo html_writer::tag('p', get_string('queue_note', $at), ['class' => 'sr-muted small']);
echo $OUTPUT->footer();
