<?php

/**
 * Degree audit of a student (records staff and the student; printable with print=1).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\access;
use local_studentrecords\local\audit;
use local_studentrecords\local\catalog;
use local_studentrecords\local\degreeaudit;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$id = required_param('id', PARAM_INT);
$print = optional_param('print', 0, PARAM_BOOL);
$p = 'local_studentrecords';
require_login(null, false);
$student = students::get($id);
$own = (int)$student->userid === (int)$USER->id;
if (!access::sees_all_students() && !($own && has_capability('local/studentrecords:viewown', context_system::instance()))) {
    throw new required_capability_exception(context_system::instance(), 'local/studentrecords:viewstudents', 'nopermissions', '');
}
if (access::hide_directory($student)) {
    $student->preferredname = null;
}
$url = new moodle_url('/local/studentrecords/degreeaudit.php', array_filter(['id' => $id, 'print' => $print]));
$staff = staffarea::can_open('students');
if ($staff) {
    staffarea::start_page($url, 'students');
    $PAGE->navbar->add(get_string('studentrecords', $p), new moodle_url('/local/studentrecords/students.php'));
    $PAGE->navbar->add(students::display_name($student), new moodle_url('/local/studentrecords/student.php', ['id' => $id]));
    $PAGE->navbar->add(get_string('degreeaudit', $p), $url);
} else {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('standard');
    $PAGE->set_heading(get_string('pluginname', $p));
}
$PAGE->set_title(get_string('degreeaudit', $p));
if ($print) {
    $PAGE->set_pagelayout('print');
}
// Staff actions: course substitution, graduation approval (records staff).
$manage = $staff && access::can_manage_students();
if ($manage && optional_param('subst', 0, PARAM_BOOL)) {
    require_sesskey();
    try {
        degreeaudit::add_substitution(
            $student,
            required_param('reqcourse', PARAM_INT),
            required_param('subcourse', PARAM_INT),
            optional_param('note', '', PARAM_TEXT)
        );
        redirect($url, get_string('da_substsaved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($url, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
}
$openapp = null;
foreach (\local_studentrecords\local\graduation::applications((int)$student->id) as $app) {
    if ($app->state === 'applied') {
        $openapp = $app;
        break;
    }
}
if ($manage && $openapp && ($decision = optional_param('gradreview', '', PARAM_ALPHA))) {
    require_sesskey();
    try {
        \local_studentrecords\local\graduation::review((int)$openapp->id, $decision === 'approve', optional_param(
            'note',
            '',
            PARAM_TEXT
        ), optional_param('diplomaname', '', PARAM_TEXT));
        redirect(
            $url,
            get_string($decision === 'approve' ? 'da_approved' : 'da_sentback', $p),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($url, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
}
audit::log('view', 'degreeaudit', $id, (int)$student->userid);
$result = degreeaudit::run($student);

echo $staff && !$print ? staffarea::header() : $OUTPUT->header();
echo $OUTPUT->heading(get_string('degreeaudit', $p) . ': ' . s(students::display_name($student)));
if (!$result) {
    echo $OUTPUT->notification(get_string('auditnoprogram', $p), 'info');
    echo $OUTPUT->footer();
    return;
}
$num = fn($v) => $v === null ? '-' : format_float($v, -1);
$unit = get_string('unit_' . $result['unittype'], $p);
echo html_writer::tag('p', s($student->studentid) . ' · ' . s($result['programcode']) . ' - ' . $result['programname'] .
    ' · ' . get_string('auditdate', $p, userdate(time(), get_string('strftimedate', 'langconfig'), catalog::timezone())));
echo $OUTPUT->notification(
    get_string($result['complete'] ? 'audit_complete' : 'audit_incomplete', $p),
    $result['complete'] ? 'success' : 'warning'
);
$shell = \local_studentrecords\local\shell::class;
$rows = '';
$row = function (string $title, string $detail, string $value, string $tone, string $tag) use ($shell) {
    return html_writer::tag('li', html_writer::div(html_writer::tag('strong', $title) . ($detail !== '' ?
        html_writer::div($detail, 'sr-msg-meta') : ''), 'sr-req-name') . html_writer::div($value, 'sr-req-value') .
        html_writer::div($shell::tag($tag, $tone), 'sr-req-tag'));
};
foreach ($result['groups'] as $g) {
    if ($g['requirement'] === 'track' && $g['name'] !== $result['track']) {
        continue;
    }
    $codes = implode(', ', array_map(fn($r) => s($r['code']) . ($r['source'] === 'subst' ? '*' : ''), $g['rows']));
    $pending = !$g['complete'] && $g['met'] + $g['inprogress'] + 0.001 >= (float)($g['needed'] ?? 0) &&
        !array_filter($g['rows'], fn($r) => in_array(
            $r['state'],
            ['missing', 'notpassed'],
            true
        ) && $g['requirement'] !== 'elective');
    $rows .= $row(
        s($g['name']),
        $codes,
        get_string('da_ofhours', $p, (object)['met' => $num($g['met']),
            'needed' => $g['needed'] === null ? '-' : $num($g['needed']),
            'unit' => $unit]),
        $g['complete'] ? 'good' : ($pending ? 'warn' : 'bad'),
        get_string($g['complete'] ? 'da_met' : ($pending ? 'da_pending' : 'da_notmet'), $p)
    );
}
if (!$result['auditable']) {
    $rows .= $row(
        get_string('audit_hoursearned', $p),
        get_string('da_nothingtocheck', $p),
        $num($result['earned']) . ' ' . $unit,
        'neutral',
        get_string('notset', $p)
    );
} else if (!$result['groups']) {
    // With no course list the hours are the requirement.
    $pending = !$result['hoursok'] && $result['earned'] + $result['inprogress'] + 0.001 >= (float)$result['totalhours'];
    $rows .= $row(
        get_string('audit_hoursearned', $p),
        get_string('da_nocourselist', $p, \core_text::strtolower($unit)),
        get_string('da_ofhours', $p, (object)['met' => $num($result['earned']),
            'needed' => $result['totalhours'] ? $num($result['totalhours']) : '-', 'unit' => $unit]),
        $result['hoursok'] ? 'good' : ($pending ? 'warn' : 'bad'),
        get_string($result['hoursok'] ? 'da_met' : ($pending ? 'da_pending' : 'da_notmet'), $p)
    );
}
if ($result['mingpa'] !== null) {
    $rows .= $row(
        get_string('da_mingpa', $p, format_float($result['mingpa'], 2)),
        '',
        $result['gpa'] === null ? '-' :
        format_float($result['gpa'], 2),
        $result['gpaok'] ? 'good' : 'bad',
        get_string($result['gpaok'] ? 'da_met' : 'da_notmet', $p)
    );
}
if (!empty($result['residentneeded'])) {
    $ok = $result['atschool'] + 0.001 >= $result['residentneeded'];
    $rows .= $row(
        get_string('da_atschool', $p, $num($result['residentneeded'])),
        '',
        $num($result['atschool']) . ' ' . $unit,
        $ok ? 'good' : 'warn',
        get_string($ok ? 'da_met' : 'da_notmet', $p)
    );
}
if ($result['inprogress'] > 0) {
    $rows .= $row(
        get_string('da_inprogress', $p),
        '',
        $num($result['inprogress']) . ' ' . $unit,
        'warn',
        get_string('da_pending', $p)
    );
}
$n = \local_studentrecords\local\cards::student_numbers($student);
$clear = !$n['holds'] && ($n['balance'] === null || $n['balance'] <= 0.004);
// Staff who can't see billing get the verdict in the tag, not the amount.
$showmoney = $n['balance'] !== null && ($own || has_capability('local/studentrecords:viewbilling', context_system::instance()));
$rows .= $row(
    get_string('da_clear', $p),
    '',
    ($showmoney ? \local_studentrecords\local\billing::money(max(
        0,
        $n['balance']
    )) . ' · ' : '') . get_string('home_holds', $p) . ' ' . $n['holds'],
    $clear ? 'good' : 'bad',
    get_string($clear ? 'da_met' : 'da_notmet', $p)
);
$main = html_writer::tag('ul', $rows, ['class' => 'sr-reqs']);
if (array_filter($result['groups'], fn($g) => array_filter($g['rows'], fn($r) => $r['source'] === 'subst'))) {
    $main .= html_writer::tag('p', get_string('da_substnote', $p), ['class' => 'sr-muted small']);
}
$total = (float)($result['totalhours'] ?? 0);
$done = $result['earned'] + $result['inprogress'];
$pct = \local_studentrecords\local\record_view::percent($done, $total);
$panel = html_writer::tag('h2', get_string('da_approval', $p), ['class' => 'sr-card-title h3']);
// With no course list and no total hours there is nothing to measure progress against.
if ($result['groups'] || $total > 0) {
    $panel .= html_writer::div(html_writer::span(get_string('da_progress', $p, (object)['done' => $num($done),
        'total' => $num($total), 'unit' => $unit])) . html_writer::tag('strong', $pct . '%'), 'sr-card-row');
    $panel .= html_writer::div(html_writer::span('', 'sr-progress-bar', ['style' => 'width:' . $pct . '%']), 'sr-progress', [
        'role' => 'progressbar', 'aria-valuenow' => $pct, 'aria-valuemin' => 0, 'aria-valuemax' => 100,
        'aria-label' => get_string('rec_progresslabel', $p)]);
}
if ($result['complete']) {
    $panel .= html_writer::div(get_string('da_readyfinal', $p), 'sr-note sr-note-good');
} else if (!empty($result['completeifpassed'])) {
    $panel .= html_writer::div(get_string('da_conditional', $p), 'sr-note sr-note-warn');
} else {
    $panel .= html_writer::div(get_string($result['auditable'] ? 'da_notready' : 'da_notauditable', $p), 'sr-note sr-note-bad');
}
if ($manage && $openapp && $own && !$print) {
    $panel .= html_writer::tag('p', get_string('gradownapp', $p), ['class' => 'sr-muted small mb-0']);
} else if ($manage && $openapp && !$print) {
    $f = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $f .= html_writer::label(get_string('da_diplomaname', $p), 'dadiploma', true, ['class' => 'd-block small mt-2']);
    $f .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'diplomaname', 'id' => 'dadiploma',
        'class' => 'form-control',
        'value' => trim($student->legalfirstname . ' ' . ($student->legalmiddlename ? $student->legalmiddlename . ' ' : '') .
        $student->legallastname)]);
    $f .= html_writer::label(get_string('da_note', $p), 'danote', true, ['class' => 'd-block small mt-2']);
    $f .= html_writer::tag('textarea', '', ['name' => 'note', 'id' => 'danote', 'rows' => 2, 'class' => 'form-control']);
    $f .= html_writer::tag('button', get_string('da_approve', $p), ['type' => 'submit', 'name' => 'gradreview',
        'value' => 'approve',
        'class' => 'btn btn-primary w-100 mt-3']);
    $f .= html_writer::tag('button', get_string('da_sendback', $p), ['type' => 'submit', 'name' => 'gradreview', 'value' => 'deny',
        'class' => 'btn btn-outline-secondary w-100 mt-2']);
    $panel .= html_writer::tag('form', $f, ['method' => 'post', 'action' => $url->out_omit_querystring() . '?id=' . $id]);
} else if ($staff && !$openapp) {
    $panel .= html_writer::tag('p', get_string('da_noapp', $p), ['class' => 'sr-muted small mb-0']);
}
echo html_writer::div(html_writer::div($main, 'sr-col-main') . html_writer::div(
    html_writer::div($panel, 'sr-card'),
    'sr-col-side'
), 'sr-columns');
echo html_writer::start_tag('details', ['class' => 'sr-details mt-4']);
echo html_writer::tag('summary', get_string($result['groups'] ? 'da_coursebycourse' : 'da_totals', $p), ['class' => 'sr-h2']);
$facts = [
    get_string('audit_hoursearned', $p) => $num($result['earned']) . ' / ' . $num($result['totalhours']) . ' ' . $unit .
        ($result['inprogress'] ? ' (' . get_string('audit_inprogresshours', $p, $num($result['inprogress'])) . ')' : ''),
    get_string('gpa', $p) => ($result['gpa'] === null ? '-' : format_float($result['gpa'], 2)) .
        ($result['mingpa'] !== null ? ' (' . get_string('audit_mingpa', $p, format_float($result['mingpa'], 2)) . ')' : ''),
];
if ($result['groups']) {
    $facts[get_string('audit_track', $p)] = $result['track'] !== '' ? s($result['track']) : '-';
}
$table = new html_table();
foreach ($facts as $k => $v) {
    $table->data[] = [html_writer::tag('strong', $k), $v];
}
echo html_writer::table($table);
$marks = ['met' => ['audit_met', 'text-success'], 'inprogress' => ['audit_inprogress', 'text-primary'],
    'notpassed' => ['audit_notpassed', 'text-danger'], 'missing' => ['audit_missing', 'text-muted']];
foreach ($result['groups'] as $g) {
    $chosen = $g['requirement'] !== 'track' || $g['name'] === $result['track'];
    $table = new html_table();
    $table->caption = s($g['name']) . ' - ' . get_string('req_' . $g['requirement'], $p) . ' - ' .
        get_string($g['complete'] ? 'audit_groupdone' : 'audit_groupopen', $p) .
        ($g['requirement'] === 'track' && !$chosen ? ' (' . get_string('audit_othertrack', $p) . ')' : '');
    $table->head = [get_string('hist_course', $p), get_string('coursetitle', $p), get_string('hours', $p),
        get_string('audit_state', $p), get_string('finalgrade', $p), get_string('term', $p)];
    foreach ($g['rows'] as $r) {
        [$label, $class] = $marks[$r['state']];
        $table->data[] = [s($r['code']), format_string($r['title']), $num($r['hours']), html_writer::span(get_string($label, $p) .
            ($r['source'] === 'transfer' ? ' (' . get_string('transfercredit', $p) . ')' : ''), $class), s($r['letter']),
            s($r['term'])];
    }
    $table->data[] = [html_writer::tag('strong', get_string('total')), '', $num($g['met']) . ' / ' .
        ($g['needed'] === null ? '-' : $num($g['needed'])), '', '', ''];
    echo html_writer::table($table);
}
echo html_writer::end_tag('details');
if ($result['missing']) {
    $items = [];
    foreach ($result['missing'] as [$key, $a]) {
        $items[] = get_string($key, $p, is_array($a) ? (object)array_map(fn($v) => is_float($v) ? format_float($v, 2) : $v, $a) :
            s($a));
    }
    echo $OUTPUT->heading(get_string('audit_stillneeded', $p), 4) . html_writer::alist($items);
}
if ($manage && !$own && !$print) {
    $req = [];
    foreach ($result['groups'] as $g) {
        foreach ($g['rows'] as $r) {
            if ($r['state'] !== 'met') {
                $req[(int)$r['courseid']] = s($r['code']) . ' ' . format_string($r['title']);
            }
        }
    }
    $taken = [];
    foreach (\local_studentrecords\local\registration::history($student)['terms'] as $t) {
        foreach ($t['rows'] as $r) {
            if ($r->status === 'completed' && $r->passed !== false && empty($r->repeatexcluded)) {
                $taken[(int)$r->courseid] = s($r->code) . ' ' . format_string($r->title);
            }
        }
    }
    if ($req && $taken) {
        echo html_writer::tag('h2', get_string('da_addsubst', $p), ['class' => 'sr-h2']);
        $f = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'subst', 'value' => 1]);
        $f .= html_writer::div(html_writer::label(get_string('da_required', $p), 'dareq') .
            html_writer::select($req, 'reqcourse', '', false, ['id' => 'dareq', 'class' => 'form-select']), 'sr-field');
        $f .= html_writer::div(html_writer::label(get_string('da_insteadof', $p), 'dasub') .
            html_writer::select($taken, 'subcourse', '', false, ['id' => 'dasub', 'class' => 'form-select']), 'sr-field');
        $f .= html_writer::div(
            html_writer::label(get_string('da_reason', $p), 'dasubnote') . html_writer::empty_tag(
                'input',
                ['type' => 'text', 'name' => 'note', 'id' => 'dasubnote', 'class' => 'form-control', 'required' => 'required']
            ),
            'sr-field sr-field-grow'
        );
        $f .= html_writer::div(html_writer::tag('button', get_string('da_approvesubst', $p), ['type' => 'submit',
            'class' => 'btn btn-outline-secondary']), 'sr-field');
        echo html_writer::tag('form', $f, ['method' => 'post', 'action' => $url->out_omit_querystring() . '?id=' . $id,
            'class' => 'sr-filter']);
    }
}
if (!$print) {
    echo html_writer::link(new moodle_url($url, ['print' => 1]), get_string('printversion', $p), ['class' => 'btn btn-secondary']);
}
echo $OUTPUT->footer();
