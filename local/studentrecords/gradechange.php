<?php

/**
 * Grade changes: request one for a completed course (records staff); list of requests; approve or reject (a second
 * person with the approve capability; never the person who asked). An approved change is applied at once.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\academic;
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
    die();
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
$tabs = [];
foreach (['pending', 'approved', 'rejected', 'all'] as $s) {
    $tabs[] = new tabobject($s, new moodle_url($url, ['state' => $s]), get_string('changestate_' . $s, $p));
}
echo $OUTPUT->tabtree($tabs, $state);
$table = new html_table();
$table->head = [get_string('date'), get_string('name'), get_string('hist_course', $p), get_string('term', $p),
    get_string('oldvalue', $p), get_string('newvalue', $p), get_string('changereason', $p), get_string('requestedby', $p),
    get_string('changestate', $p), ''];
foreach (academic::changes($state === 'all' ? '' : $state) as $c) {
    $decision = '';
    if ($c->state === 'pending' && $canapprove && (int)$c->requestedby !== (int)$USER->id) {
        $decision = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-flex gap-1']) .
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
        $decision = html_writer::span(get_string($canapprove ? 'changeownrequest' : 'changeneedsapprover', $p), 'text-muted small');
    } else {
        $decision = s(history_view::user_name((int)$c->decidedby)) . ', ' . catalog::show_date((int)$c->timedecided) .
            ($c->decisionnote ? ': ' . s($c->decisionnote) : '');
    }
    $table->data[] = [userdate($c->timerequested, get_string('strftimedatetimeshort', 'langconfig')),
        html_writer::link(
            new moodle_url('/local/studentrecords/student.php', ['id' => $c->studentid]),
            s(students::display_name($c))
        ), s($c->code . ' ' . $c->section), format_string($c->termname), s((string)$c->oldgrade),
        s($c->newgrade) . ($c->newpct !== null ? ' (' . format_float($c->newpct, 1) . '%)' : ''), nl2br(s((string)$c->reason)),
        s(history_view::user_name((int)$c->requestedby)), get_string('changestate_' . $c->state, $p), $decision];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nochanges', $p), 'info');
echo $OUTPUT->footer();
