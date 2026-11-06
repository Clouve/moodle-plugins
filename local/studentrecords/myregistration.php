<?php

/**
 * Registration for students: register for classes themselves (when the school turns it on): pick classes, review the
 * cost and confirm. Full sections offer their waitlist. Always shows the student's classes and waitlists.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\registration;
use local_studentrecords\local\selfreg;
use local_studentrecords\local\shell;
use local_studentrecords\local\students;
use local_studentrecords\local\waitlist;

require_login(null, false);
$p = 'local_studentrecords';
$syscontext = context_system::instance();
require_capability('local/studentrecords:viewown', $syscontext);
$student = students::get_by_user((int)$USER->id);
if (!$student) {
    throw new moodle_exception('norecord', $p);
}
$step = optional_param('step', 'choose', PARAM_ALPHA);
$termid = optional_param('termid', 0, PARAM_INT);
$q = trim(optional_param('q', '', PARAM_TEXT));
$action = optional_param('action', '', PARAM_ALPHA);
$offerid = optional_param('offer', 0, PARAM_INT);
$closed = selfreg::closed_notice($student);
$open = $closed === null ? selfreg::open_terms() : [];
if (!isset($open[$termid])) {
    $termid = $open ? (int)array_key_first($open) : 0;
}
$url = new moodle_url('/local/studentrecords/myregistration.php', array_filter(['termid' => $termid, 'q' => $q,
    'step' => $step !== 'choose' ? $step : '']));
$PAGE->set_context($syscontext);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myreg_title', $p) . ' - ' . get_string('pluginname', $p));
$PAGE->set_heading(get_string('myreg_title', $p));
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(get_string('pluginname', $p), new moodle_url('/local/studentrecords/index.php'));
$PAGE->navbar->add(get_string('nav_registration', $p), $url);

// Actions (POST with sesskey).
if ($action !== '') {
    require_sesskey();
    $back = new moodle_url('/local/studentrecords/myregistration.php', array_filter(['termid' => $termid, 'q' => $q]));
    try {
        if ($action === 'add') {
            selfreg::add($student, $offerid);
        } else if ($action === 'remove') {
            selfreg::remove($student, $offerid);
        } else if ($action === 'wait') {
            selfreg::wait($student, $offerid);
            redirect($back, get_string('myreg_waitjoined', $p), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($action === 'leave') {
            $w = waitlist::entry((int)$student->id, $offerid);
            if ($w) {
                waitlist::leave((int)$w->id);
            }
        } else if ($action === 'confirm') {
            $result = selfreg::confirm($student, $termid);
            $SESSION->local_studentrecords_regdone = ['termid' => $termid, 'confirmation' => $result['confirmation'],
                'done' => array_map(fn($o) => (int)$o->id, $result['done']),
                'failed' => array_map(fn($f) => [(int)$f[0]->id, $f[1]], $result['failed'])];
            redirect(new moodle_url('/local/studentrecords/myregistration.php', ['termid' => $termid, 'step' => 'done']));
        }
    } catch (moodle_exception $e) {
        redirect($back, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($step === 'review' ? new moodle_url($back, ['step' => 'review']) : $back);
}

/**
 * A small POST button form.
 *
 * @param moodle_url $url
 * @param array $params
 * @param string $label
 * @param string $class
 * @param string $arialabel
 * @return string
 */
function local_studentrecords_myreg_button(
    moodle_url $url,
    array $params,
    string $label,
    string $class,
    string $arialabel = ''
): string {
    $hidden = '';
    foreach ($params + $url->params() + ['sesskey' => sesskey()] as $k => $v) {
        $hidden .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
    $attrs = ['type' => 'submit', 'class' => $class];
    if ($arialabel !== '') {
        $attrs['aria-label'] = $arialabel;
    }
    return html_writer::tag('form', $hidden . html_writer::tag('button', $label, $attrs), ['method' => 'post',
        'action' => $url->out_omit_querystring(), 'class' => 'd-inline']);
}

/**
 * Credits of a class ("3 cr"), or clock hours ("40 clock hrs").
 *
 * @param stdClass $o offering with hours and unittype
 * @return string
 */
function local_studentrecords_myreg_hours(stdClass $o): string {
    return $o->unittype === 'clock' ? get_string('hours_clockshort', 'local_studentrecords', format_float((float)$o->hours, -1)) :
        get_string('myreg_cr', 'local_studentrecords', format_float((float)$o->hours, -1));
}

echo $OUTPUT->header();
echo shell::inline_menu();

if ($closed !== null) {
    echo $OUTPUT->notification($closed, \core\output\notification::NOTIFY_INFO);
    // The student's classes and waitlists still show.
    $rows = $DB->get_records_sql("SELECT r.offerid, t.name AS termname FROM {" . registration::REG . "} r
        JOIN {" . registration::OFFER . "} o ON o.id = r.offerid JOIN {local_studentrecords_term} t ON t.id = o.termid
        WHERE r.studentid = ? AND r.status = 'registered' ORDER BY t.startdate", [$student->id]);
    echo html_writer::tag('h2', get_string('myreg_myclasses', $p), ['class' => 'sr-h2']);
    if ($rows) {
        $items = '';
        foreach ($rows as $r) {
            $o = registration::offer((int)$r->offerid);
            $items .= html_writer::tag('li', s($o->code . ' ' . $o->section) . ' ' . format_string($o->title) . ' · ' .
                format_string($o->termname) . (selfreg::meets_text($o) !== '' ? ' · ' . s(selfreg::meets_text($o, true)) : ''));
        }
        echo html_writer::tag('ul', $items, ['class' => 'sr-msgs']);
    } else {
        echo shell::empty_state(get_string('home_noclasses', $p));
    }
    // What the student picked or waits for: they can still take it back.
    $pending = selfreg::pending($student);
    $here = new moodle_url('/local/studentrecords/myregistration.php');
    $item = function (stdClass $o, string $action, string $label, string $arialabel) use ($here) {
        return html_writer::tag('li', html_writer::span(s($o->code . ' ' . $o->section) . ' ' . format_string($o->title)) . ' ' .
            local_studentrecords_myreg_button(
                $here,
                ['action' => $action, 'offer' => $o->id],
                $label,
                'btn btn-link btn-sm',
                $arialabel
            ));
    };
    $items = '';
    foreach ($pending['cart'] as $o) {
        $items .= $item($o, 'remove', get_string('remove'), get_string('myreg_removeone', $p, $o->code . ' ' . $o->section));
    }
    foreach ($pending['waits'] as $o) {
        $items .= $item($o, 'leave', get_string('myreg_leave', $p), get_string('myreg_leaveone', $p, $o->code . ' ' . $o->section));
    }
    if ($items !== '') {
        echo html_writer::tag('h2', get_string('myreg_pending', $p), ['class' => 'sr-h2']);
        echo html_writer::tag('ul', $items, ['class' => 'sr-msgs']);
    }
    echo $OUTPUT->footer();
    die();
}

$term = $open[$termid];
$cart = selfreg::cart($student, $termid);
$registered = selfreg::registered($student, $termid);
$clashes = selfreg::clashes($cart + $registered);
$estimate = billing::enabled() ? billing::estimate($student, array_values($cart)) : null;
$base = new moodle_url('/local/studentrecords/myregistration.php', array_filter(['termid' => $termid, 'q' => $q]));

// Steps.
$steps = ['choose' => 'myreg_step1', 'review' => 'myreg_step2', 'done' => 'myreg_step3'];
$stepitems = '';
$i = 0;
foreach ($steps as $key => $label) {
    $i++;
    $attrs = ['class' => 'sr-step' . ($key === $step ? ' active' : '')];
    if ($key === $step) {
        $attrs['aria-current'] = 'step';
    }
    $stepitems .= html_writer::tag('li', get_string($label, $p, $i), $attrs);
}
echo html_writer::tag('ol', $stepitems, ['class' => 'sr-steps', 'aria-label' => get_string('myreg_steps', $p)]);

if ($step === 'done') {
    $done = $SESSION->local_studentrecords_regdone ?? null;
    if (!$done || (int)$done['termid'] !== $termid) {
        redirect($base);
    }
    if ($done['done']) {
        echo html_writer::div(
            html_writer::tag('h2', get_string('myreg_done', $p), ['class' => 'sr-h2 mt-0']) .
            html_writer::tag('p', format_string($term->name) . ' · ' . get_string('myreg_confirmno', $p, s($done['confirmation']))),
            'sr-success',
            ['role' => 'status']
        );
    }
    foreach ($done['failed'] as [$fid, $msg]) {
        $o = registration::offer((int)$fid);
        echo $OUTPUT->notification(s($o->code . ' ' . $o->section) . ': ' . s($msg), \core\output\notification::NOTIFY_ERROR);
    }
    $items = '';
    foreach (selfreg::registered($student, $termid) as $o) {
        $items .= html_writer::tag('li', html_writer::div(s($o->code) . ' ' . format_string($o->title) . html_writer::span(
            local_studentrecords_myreg_hours($o),
            'float-end'
        ), 'sr-msg-subject') .
            html_writer::div(s(selfreg::meets_text($o, true)), 'sr-msg-meta'));
    }
    echo html_writer::tag('ul', $items, ['class' => 'sr-msgs']);
    if (billing::enabled()) {
        echo html_writer::tag(
            'p',
            get_string('myreg_balancenow', $p, billing::money(max(0, billing::balance((int)$student->id)))),
            ['class' => 'sr-total']
        );
        echo html_writer::div(html_writer::link(
            new moodle_url('/local/studentrecords/account.php', ['id' => $student->id]),
            get_string('myreg_gotoaccount', $p),
            ['class' => 'btn btn-primary']
        ), 'sr-actions');
    }
    unset($SESSION->local_studentrecords_regdone);
    echo $OUTPUT->footer();
    die();
}

// Cart panel (choose step) or review (review step).
$panel = html_writer::tag('h2', get_string('myreg_myterm', $p, format_string($term->name)), ['class' => 'sr-card-title']);
$hours = 0.0;
if ($cart || $registered) {
    $items = '';
    foreach ($registered as $o) {
        $hours += $o->unittype === 'clock' ? 0 : (float)$o->hours;
        $items .= html_writer::tag('li', s($o->code . ' · ') . local_studentrecords_myreg_hours($o) .
            ' ' . shell::tag(get_string('myreg_registered', $p), 'good'));
    }
    foreach ($cart as $o) {
        $hours += $o->unittype === 'clock' ? 0 : (float)$o->hours;
        $items .= html_writer::tag('li', s($o->code . ' · ') . local_studentrecords_myreg_hours($o) .
            ' ' . local_studentrecords_myreg_button(
                $base,
                ['action' => 'remove', 'offer' => $o->id, 'step' => $step],
                get_string('remove'),
                'btn btn-link btn-sm float-end',
                get_string('myreg_removeone', $p, $o->code)
            ));
    }
    $panel .= html_writer::tag('ul', $items, ['class' => 'sr-cartlist']);
} else {
    $panel .= shell::empty_state(get_string('myreg_cartempty', $p));
}
if ($estimate && $cart) {
    $rows = '';
    foreach ($estimate['lines'] as $l) {
        $rows .= html_writer::tag('li', html_writer::span(s($l['label'])) . html_writer::span(billing::money($l['amount'])));
    }
    $panel .= html_writer::tag('ul', $rows, ['class' => 'sr-costlines']);
    $panel .= html_writer::div(html_writer::span(get_string('myreg_estimate', $p)) .
        html_writer::span(billing::money($estimate['total'])), 'sr-costtotal');
}
$fulltime = (float)(\local_studentrecords\local\catalog::setting('fulltimehours') !== '' ?
    \local_studentrecords\local\catalog::setting('fulltimehours') : 12);
if ($hours > 0 && $hours < $fulltime) {
    $panel .= html_writer::tag('p', get_string('myreg_fulltime', $p, (object)['need' => format_float($fulltime, -1),
        'have' => format_float($hours, -1)]), ['class' => 'sr-muted small']);
}
foreach ($clashes as [$a, $b]) {
    $panel .= html_writer::div(get_string('myreg_clash', $p, (object)['a' => s($a->code . ' ' . $a->section),
        'b' => s($b->code . ' ' . $b->section)]), 'alert alert-danger', ['role' => 'alert']);
}
if ($step === 'review') {
    if ($cart && !$clashes) {
        $panel .= html_writer::tag('p', get_string('myreg_confirmtext', $p), ['class' => 'small']);
        $panel .= local_studentrecords_myreg_button(
            $base,
            ['action' => 'confirm', 'termid' => $termid],
            get_string('myreg_confirm', $p),
            'btn btn-primary w-100'
        );
    }
    $panel .= html_writer::div(html_writer::link($base, get_string('myreg_back', $p)), 'mt-3');
    echo html_writer::div($panel, 'sr-card sr-review');
    echo $OUTPUT->footer();
    die();
}
if ($cart && !$clashes) {
    $panel .= html_writer::link(new moodle_url($base, ['step' => 'review']), get_string('myreg_review', $p), [
        'class' => 'btn btn-primary w-100 mt-2']);
}

// Choose: term, search, list of sections.
$termoptions = '';
foreach ($open as $t) {
    $termoptions .= html_writer::tag('option', format_string($t->name), ['value' => $t->id] +
        ((int)$t->id === $termid ? ['selected' => 'selected'] : []));
}
$filter = html_writer::div(html_writer::label(get_string('term', $p), 'srregterm') .
    html_writer::tag('select', $termoptions, ['name' => 'termid', 'id' => 'srregterm', 'class' => 'form-select']), 'sr-field');
$filter .= html_writer::div(html_writer::label(get_string('myreg_find', $p), 'srregq') .
    html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'id' => 'srregq', 'value' => $q,
    'class' => 'form-control', 'placeholder' => get_string('myreg_findhint', $p)]), 'sr-field sr-field-grow');
$filter .= html_writer::div(html_writer::tag('button', get_string('search'), ['type' => 'submit',
    'class' => 'btn btn-outline-secondary']), 'sr-field sr-field-end');
$list = html_writer::tag('form', $filter, ['method' => 'get', 'action' => $base->out_omit_querystring(),
    'class' => 'sr-filter']);

$table = new html_table();
$table->attributes['class'] = 'sr-table';
$table->head = [get_string('home_class', $p), get_string('home_meets', $p), get_string('home_credits', $p),
    get_string('myreg_seats', $p), ''];
foreach (registration::offers($termid, true) as $o) {
    if ($q !== '' && stripos($o->code . ' ' . $o->title, $q) === false) {
        continue;
    }
    $o = registration::offer((int)$o->id);
    $teacher = '';
    if ($o->facultyid && ($f = $DB->get_record('local_studentrecords_faculty', ['id' => $o->facultyid]))) {
        $teacher = \local_studentrecords\local\catalog::faculty_name($f);
    }
    $name = html_writer::tag('strong', s($o->code)) . ' ' . format_string($o->title) .
        html_writer::div(
            get_string('myreg_section', $p, s($o->section)) . ($teacher !== '' ? ' · ' . s($teacher) : ''),
            'sr-msg-meta'
        );
    $taken = registration::taken((int)$o->id);
    $full = selfreg::is_full($student, $o);
    $wait = waitlist::entry((int)$student->id, (int)$o->id);
    if ($o->capacity === null) {
        $seats = get_string('myreg_nolimit', $p);
    } else if ($full) {
        $seats = get_string('myreg_fullwait', $p, count(waitlist::for_offer((int)$o->id)));
    } else {
        $seats = get_string('myreg_seatsleft', $p, (object)['left' => max(0, (int)$o->capacity - $taken),
            'cap' => (int)$o->capacity]);
    }
    $label = $o->code . ' ' . $o->section;
    if (isset($registered[$o->id])) {
        $button = shell::tag(get_string('myreg_registered', $p), 'good');
    } else if (isset($cart[$o->id])) {
        $button = shell::tag(get_string('myreg_added', $p), 'good');
    } else if ($wait) {
        $button = shell::tag(get_string(
            $wait->status === 'offered' ? 'myreg_seatoffered' : 'myreg_onwait',
            $p,
            waitlist::position((int)$student->id, (int)$o->id)
        ), $wait->status === 'offered' ? 'good' : 'info') .
            ($wait->status === 'offered' ? ' ' . local_studentrecords_myreg_button(
                $base,
                ['action' => 'add', 'offer' => $o->id],
                get_string('add'),
                'btn btn-outline-secondary btn-sm',
                get_string('myreg_addone', $p, $label)
            ) : '') .
            ' ' . local_studentrecords_myreg_button(
                $base,
                ['action' => 'leave', 'offer' => $o->id],
                get_string('myreg_leave', $p),
                'btn btn-link btn-sm',
                get_string('myreg_leaveone', $p, $label)
            );
    } else if ($full) {
        $button = local_studentrecords_myreg_button(
            $base,
            ['action' => 'wait', 'offer' => $o->id],
            get_string('myreg_joinwait', $p),
            'btn btn-outline-secondary btn-sm',
            get_string('myreg_joinwaitone', $p, $label)
        );
    } else {
        $problems = selfreg::problems($student, $o);
        $button = $problems ? html_writer::span(s(get_string($problems[0][0], $p, $problems[0][1])), 'sr-muted small') :
            local_studentrecords_myreg_button(
                $base,
                ['action' => 'add', 'offer' => $o->id],
                get_string('add'),
                'btn btn-outline-secondary btn-sm',
                get_string('myreg_addone', $p, $label)
            );
    }
    $table->data[] = [$name, s(selfreg::meets_text($o, true) ?: '-'),
        \local_studentrecords\local\homes::hours_text((float)$o->hours, (string)$o->unittype), $seats,
        $button];
}
$list .= $table->data ? html_writer::div(html_writer::table($table), 'table-responsive') :
    shell::empty_state(get_string('myreg_noclasses', $p));
echo html_writer::div(html_writer::div($list, 'sr-col-main') . html_writer::div(
    html_writer::div($panel, 'sr-card'),
    'sr-col-side'
), 'sr-columns');
echo $OUTPUT->footer();
