<?php

/**
 * Grade changes: request one for a completed course (records staff); list of requests; approve or reject (a second
 * person with the approve capability; never the person who asked, and never the student). An approved change is applied at
 * once. Someone with the approve capability who doesn't manage students sees only the requests they can decide.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\academic;
use local_studentrecords\local\access;
use local_studentrecords\local\catalog;
use local_studentrecords\local\history_view;
use local_studentrecords\local\registration;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$regid = optional_param('reg', 0, PARAM_INT);
$state = optional_param('state', 'pending', PARAM_ALPHA);
$state = in_array($state, ['pending', 'approved', 'rejected', 'all'], true) ? $state : 'pending';
$action = optional_param('action', '', PARAM_ALPHA);
$p = 'local_studentrecords';
require_login(null, false);
staffarea::setup('gradechanges', new moodle_url('/local/studentrecords/gradechange.php', array_filter(['reg' => $regid,
    'state' => $state])));
$url = new moodle_url('/local/studentrecords/gradechange.php');
$syscontext = context_system::instance();
$canapprove = has_capability('local/studentrecords:approvegradechange', $syscontext);
$manage = access::can_manage_students();
$state = $manage ? $state : 'pending';

if ($regid) {
    require_capability('local/studentrecords:managestudents', $syscontext);
    $reg = $DB->get_record(registration::REG, ['id' => $regid], '*', MUST_EXIST);
    $offer = registration::offer((int)$reg->offerid);
    $student = students::get((int)$reg->studentid);
    $back = new moodle_url('/local/studentrecords/student.php', ['id' => $student->id]);
    $text = s(students::display_name($student)) . ': ' . s($offer->code . ' ' . $offer->section) . ' - ' .
        format_string($offer->title) . ' (' . format_string($offer->termname) . ') - ' . get_string('finalgrade', $p) .
        ' ' . s((string)$reg->grade);
    $form = new \local_studentrecords\form\gradechange(new moodle_url($url, ['reg' => $regid]), ['text' => $text,
        'current' => $reg->grade]);
    $form->set_data(['reg' => $regid, 'newgrade' => $reg->grade]);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    $error = '';
    if ($data = $form->get_data()) {
        try {
            academic::request_change($regid, $data->newgrade, $data->newpct === '' ? null : (float)$data->newpct, $data->reason);
            redirect($back, get_string('changerequested', $p), null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
    }
    echo staffarea::header();
    echo $OUTPUT->heading(get_string('requestchange', $p));
    if ($error !== '') {
        echo $OUTPUT->notification(s($error), 'error');
    }
    $form->display();
    echo $OUTPUT->footer();
    return;
}

if ($action === 'approve' || $action === 'reject') {
    require_sesskey();
    $changeid = required_param('change', PARAM_INT);
    $note = optional_param('note', '', PARAM_TEXT);
    try {
        academic::decide_change($changeid, $action === 'approve', $note);
        redirect(
            $url,
            get_string($action === 'approve' ? 'changeapproved' : 'changerejected', $p),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($url, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo staffarea::header();
echo html_writer::tag('p', get_string('gradechanges_intro', $p));
if ($manage) {
    $tabs = [];
    foreach (['pending', 'approved', 'rejected', 'all'] as $s) {
        $tabs[] = new tabobject($s, new moodle_url($url, ['state' => $s]), get_string('changestate_' . $s, $p));
    }
    echo $OUTPUT->tabtree($tabs, $state);
}
$openrecords = access::sees_all_students();
$table = new html_table();
$table->attributes['class'] = 'generaltable sr-changes';
$table->head = [get_string('date'), get_string('name'), get_string('hist_course', $p), get_string('term', $p),
    get_string('oldvalue', $p), get_string('newvalue', $p), get_string('requestedby', $p), get_string('changestate', $p)];
$line = fn(string $label, string $body) => html_writer::div(
    html_writer::div($label, 'sr-change-label') . html_writer::div($body, 'sr-change-body'),
    'sr-change-line'
);
foreach (academic::changes($state === 'all' ? '' : $state) as $c) {
    $candecide = academic::can_decide($c);
    // Someone who only approves gets what deciding needs, and no other request.
    if (!$manage && !$candecide) {
        continue;
    }
    $decision = '';
    if ($candecide) {
        $decision = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false),
            'class' => 'sr-change-form']) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'change', 'value' => $c->id]) .
            html_writer::empty_tag('input', ['type' => 'text', 'name' => 'note', 'class' => 'form-control form-control-sm',
                'placeholder' => get_string('decisionnote_short', $p), 'aria-label' => get_string('decisionnote_short', $p)]) .
            html_writer::tag('button', get_string('approve', $p), ['type' => 'submit', 'name' => 'action', 'value' => 'approve',
                'class' => 'btn btn-sm btn-primary']) .
            html_writer::tag('button', get_string('reject', $p), ['type' => 'submit', 'name' => 'action', 'value' => 'reject',
                'class' => 'btn btn-sm btn-secondary']) .
            html_writer::end_tag('form');
    } else if ($c->state === 'pending') {
        $own = (int)$c->userid === (int)$USER->id ? 'changeownrecord' : 'changeownrequest';
        $decision = html_writer::span(get_string($canapprove ? $own : 'changeneedsapprover', $p), 'text-muted small');
    } else {
        $decision = s(history_view::user_name((int)$c->decidedby)) . ', ' . catalog::show_date((int)$c->timedecided) .
            ($c->decisionnote ? ': ' . s($c->decisionnote) : '');
    }
    $name = s(students::display_name($c, access::hide_directory($c)));
    $facts = new html_table_row();
    $facts->attributes['class'] = 'sr-change-facts';
    foreach (
        [userdate($c->timerequested, get_string('strftimedatetimeshort', 'langconfig')), $openrecords ? html_writer::link(
            new moodle_url('/local/studentrecords/student.php', ['id' => $c->studentid]),
            $name
        ) : $name, s($c->code . ' ' . $c->section), format_string($c->termname), s((string)$c->oldgrade),
        s($c->newgrade) . ($c->newpct !== null ? ' (' . format_float($c->newpct, 1) . '%)' : ''),
        s(history_view::user_name((int)$c->requestedby)), get_string('changestate_' . $c->state, $p)] as $i => $fact
    ) {
        $cell = new html_table_cell($fact);
        $cell->attributes['data-label'] = $table->head[$i];
        $facts->cells[] = $cell;
    }
    // The reason and the decision take the whole width: the request is read first, and the buttons fit beside the drawer.
    $detail = new html_table_cell($line(get_string('changereason', $p), nl2br(s((string)$c->reason))) .
        $line(get_string('decision', $p), $decision));
    $detail->colspan = count($table->head);
    $row = new html_table_row([$detail]);
    $row->attributes['class'] = 'sr-change-detail';
    array_push($table->data, $facts, $row);
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nochanges', $p), 'info');
echo $OUTPUT->footer();
