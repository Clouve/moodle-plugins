<?php

/**
 * Refunds (Finance staff): refunds figured by the school's refund rules when students drop or withdraw. Staff with the
 * approve refunds capability approve or reject them; a proposed refund can be figured again on another date (for
 * example the last date of attendance).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\refunds;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

require_login(null, false);
$id = optional_param('id', 0, PARAM_INT);
$status = optional_param('status', 'proposed', PARAM_ALPHA);
$status = in_array($status, ['all', 'proposed', 'approved', 'rejected', 'none'], true) ? $status : 'proposed';
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/refunds.php', ['status' => $status]);
staffarea::setup('refunds', $id ? new moodle_url('/local/studentrecords/refunds.php', ['id' => $id]) : $url);
$syscontext = context_system::instance();
$canapprove = has_capability('local/studentrecords:approverefund', $syscontext);
$manage = has_capability('local/studentrecords:managebilling', $syscontext);
$statusname = fn($s) => get_string('refundstatus_' . $s, $p);

if ($id) {
    $refund = $DB->get_record(refunds::REFUNDS, ['id' => $id], '*', MUST_EXIST);
    $student = students::get((int)$refund->studentid);
    $here = new moodle_url('/local/studentrecords/refunds.php', ['id' => $id]);
    $decide = null;
    $refigure = null;
    $ownrefund = (int)$student->userid === (int)$USER->id;
    if ($refund->status === 'proposed' && $canapprove && !$ownrefund) {
        $decide = new \local_studentrecords\form\refunddecide($here);
        $decide->set_data(['id' => $id]);
        if ($data = $decide->get_data()) {
            try {
                refunds::decide($id, $data->decision === 'approve', trim((string)$data->note));
            } catch (moodle_exception $e) {
                redirect($here, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
            }
            redirect($here, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
    if (refunds::can_refigure($refund) && $manage) {
        $refigure = new \local_studentrecords\form\refigure($here);
        $refigure->set_data(['id' => $id, 'refigure' => 1, 'eventdate' => (int)$refund->eventdate]);
        if (optional_param('refigure', 0, PARAM_INT) && ($data = $refigure->get_data())) {
            refunds::refigure($id, (int)$data->eventdate);
            redirect($here, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
    $titlekey = $refund->reason === 'aidreturn' ? 'aid_returntitle' : 'ref_title';
    $PAGE->set_heading(get_string($titlekey, $p, students::display_name($student)));
    echo staffarea::header();
    $waiting = count(refunds::listing('proposed'));
    echo html_writer::tag('p', get_string('refundreason_' . $refund->reason, $p) . ' ' .
        catalog::show_date((int)$refund->eventdate) . ' · ' . s($student->studentid) . ' · ' .
        html_writer::link(
            new moodle_url('/local/studentrecords/account.php', ['id' => $student->id]),
            get_string('openaccount', $p)
        ) .
        ($refund->status === 'proposed' ? ' · ' . get_string('ref_waiting', $p, $waiting) : ''), ['class' => 'sr-lead']);
    $shell = \local_studentrecords\local\shell::class;
    // Left: how it was worked out.
    // A return of aid goes to the Department of Education, not to the student.
    $aidreturn = $refund->reason === 'aidreturn';
    $left = html_writer::tag('h2', get_string($aidreturn ? 'ref_howaid' : 'ref_how', $p), ['class' => 'sr-h2 mt-0']);
    $left .= html_writer::tag('p', get_string('refundrule', $p) . ': ' . ($refund->rulename !== null ? s($refund->rulename) :
        get_string('norefundrule', $p)), ['class' => 'sr-muted']);
    $items = '';
    foreach (json_decode((string)$refund->details, true) ?: [] as $l) {
        $items .= html_writer::tag('li', html_writer::span(s($l['description']) . ' ' . html_writer::span(
            get_string(
                $aidreturn ? 'ref_paidout' : 'ref_charged',
                $p,
                (object)['net' => billing::money((float)$l['net']), 'pct' => format_float((float)$l['percent'], -1)]
            ),
            'sr-msg-meta'
        )) . html_writer::span(billing::money((float)$l['refund'])));
    }
    $left .= html_writer::tag('ul', $items, ['class' => 'sr-costlines']);
    $left .= html_writer::div(html_writer::span($aidreturn ? get_string('ref_dueaid', $p) :
        get_string('ref_due', $p, s(students::display_name($student)))) .
        html_writer::span(billing::money((float)$refund->amount)), 'sr-costtotal');
    if ($refund->duedate && in_array($refund->status, $aidreturn ? ['proposed'] : ['proposed', 'approved'], true)) {
        $days = (int)floor((time() - (int)$refund->duedate) / DAYSECS);
        $left .= $days > 0 ? html_writer::div(get_string('ref_overdue', $p, (object)['days' => $days,
            'date' => catalog::show_date((int)$refund->duedate)]), 'sr-note sr-note-bad') :
            html_writer::tag('p', get_string($aidreturn ? 'ref_returnby' : 'ref_payby', $p, catalog::show_date(
                (int)$refund->duedate
            )), ['class' => 'sr-muted']);
    }
    // Right: step 1 decide, step 2 money paid back.
    $right = html_writer::tag('h2', get_string('ref_step1', $p), ['class' => 'sr-card-title h4']) . html_writer::div(
        $shell::tag($statusname($refund->status), ['proposed' => 'warn', 'approved' => 'good', 'rejected' => 'bad',
        'none' => 'neutral'][$refund->status] ?? 'neutral'),
        'mb-2'
    );
    if ($decide) {
        ob_start();
        $decide->display();
        $right .= ob_get_clean();
    } else if ($refund->status === 'proposed') {
        $right .= html_writer::tag(
            'p',
            get_string($canapprove && $ownrefund ? 'err_refundself' : 'refundneedsapprover', $p),
            ['class' => 'sr-muted']
        );
    }
    if ($refund->decidedby) {
        $right .= html_writer::tag(
            'p',
            get_string('decidedby', $p) . ': ' . s(fullname(core_user::get_user($refund->decidedby))) .
            ', ' . catalog::show_date((int)$refund->timedecided) . ($refund->decisionnote ? ': ' . s($refund->decisionnote) : ''),
            ['class' => 'small']
        );
    }
    $right2 = html_writer::tag('h2', get_string($aidreturn ? 'ref_step2_aid' : 'ref_step2', $p), ['class' => 'sr-card-title h4']);
    $right2 .= html_writer::tag(
        'p',
        get_string($refund->status === 'approved' ? ($aidreturn ? 'ref_step2_aidopen' : 'ref_step2_open') : 'ref_step2_wait', $p),
        ['class' => 'sr-muted']
    );
    if ($refund->status === 'approved' && $manage && !$aidreturn) {
        $right2 .= html_writer::link(new moodle_url('/local/studentrecords/account.php', ['id' => $student->id,
            'action' => 'payout']), get_string('recordpayout', $p), ['class' => 'btn btn-primary w-100']);
    }
    echo html_writer::div(
        html_writer::div($left, 'sr-col-main') . html_writer::div(html_writer::div($right, 'sr-card mb-3') .
        html_writer::div($right2, 'sr-card' . ($refund->status === 'approved' ? '' : ' sr-card-dim')), 'sr-col-side'),
        'sr-columns'
    );
    if ($refigure) {
        echo html_writer::start_tag('details', ['class' => 'sr-details mt-4']);
        echo html_writer::tag('summary', get_string('refigure', $p), ['class' => 'sr-h2']);
        $refigure->display();
        echo html_writer::end_tag('details');
    }
    echo html_writer::link($url, get_string('back'));
    echo $OUTPUT->footer();
    return;
}

echo staffarea::header();
$tabs = [];
foreach (['proposed', 'approved', 'rejected', 'none', 'all'] as $s) {
    $tabs[] = new tabobject(
        $s,
        new moodle_url('/local/studentrecords/refunds.php', ['status' => $s]),
        $s === 'all' ? get_string('all') : $statusname($s)
    );
}
echo $OUTPUT->tabtree($tabs, $status);
$table = new html_table();
$table->head = [get_string('date'), get_string('student', $p), get_string('hist_course', $p), get_string('term', $p),
    get_string('refundreason', $p), get_string('refundrule', $p), get_string('amountusd', $p), get_string('paybackby', $p),
    get_string('status', $p), ''];
$table->colclasses = [6 => 'text-end'];
foreach (refunds::listing($status) as $r) {
    $late = $r->status === 'proposed' && $r->duedate && $r->duedate < time();
    $table->data[] = [catalog::show_date((int)$r->eventdate), s(students::display_name($r)) . ' (' . s($r->studentno) . ')',
        s((string)$r->code), format_string((string)$r->termname), get_string('refundreason_' . $r->reason, $p),
        $r->rulename !== null ? s($r->rulename) : get_string('norefundrule', $p), billing::money((float)$r->amount),
        $r->duedate ? catalog::show_date((int)$r->duedate) . ($late ? ' ' . html_writer::span(
            get_string('overdue', $p),
            'badge bg-danger'
        ) : '') : '', $statusname($r->status),
        html_writer::link(new moodle_url('/local/studentrecords/refunds.php', ['id' => $r->id]), get_string('review', $p))];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('norefunds', $p));
echo $OUTPUT->footer();
