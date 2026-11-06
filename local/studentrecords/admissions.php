<?php

/**
 * Admissions reports: pipeline by status, program and admit term; missing documents; decisions. Each can be
 * downloaded (CSV and the other Moodle data formats).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\admissions;
use local_studentrecords\local\audit;
use local_studentrecords\local\catalog;
use local_studentrecords\local\history_view;
use local_studentrecords\local\reports;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$report = optional_param('report', 'applicants', PARAM_ALPHA);
$report = in_array($report, ['applicants', 'pipeline', 'missing', 'decisions']) ? $report : 'applicants';
$termid = optional_param('termid', 0, PARAM_INT);
$programid = optional_param('programid', 0, PARAM_INT);
$decision = optional_param('decision', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$p = 'local_studentrecords';
require_login(null, false);
$params = array_filter(['report' => $report, 'termid' => $termid, 'programid' => $programid, 'decision' => $decision]);
staffarea::setup('admissions', new moodle_url('/local/studentrecords/admissions.php', $params));
$url = $PAGE->url;

// Plain text, escaped where it is printed. Downloads use it as it is.
$programs = reports::program_names();
$terms = array_map([reports::class, 'plain'], catalog::terms());

if ($report === 'applicants') {
    // Applicants list (by state) with the checklist and decision buttons of one applicant.
    $show = optional_param('show', 'ready', PARAM_ALPHA);
    $chosen = optional_param('a', 0, PARAM_INT);
    $manage = \local_studentrecords\local\access::can_manage_students();
    $decide = optional_param('decide', '', PARAM_ALPHA);
    if ($decide !== '' && $manage && in_array($decide, ['admitted', 'waitlisted', 'denied'], true)) {
        require_sesskey();
        $who = students::get(required_param('a', PARAM_INT));
        $note = trim(optional_param('note', '', PARAM_TEXT));
        if ($decide === 'denied' && $note === '') {
            redirect(
                new moodle_url($url, ['show' => $show, 'a' => $who->id]),
                get_string('adm_notedeny', $p),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        admissions::record_decision($who, ['decision' => $decide, 'decisiondate' => time(), 'termid' => (int)$who->admittermid,
            'reason' => $note, 'conditions' => '']);
        redirect(
            new moodle_url($url, ['show' => $show]),
            get_string('adm_decided', $p, (object)['name' =>
            s(students::display_name($who)), 'decision' => get_string('decision_' . $decide, $p)]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $all = $DB->get_records_select('local_studentrecords_student', "status IN ('inquiry', 'applicant', 'waitlisted', 'admitted',
        'denied', 'deferred')" . ($termid ? ' AND admittermid = ?' : ''), $termid ? [$termid] : null, 'timecreated DESC, id DESC');
    $missing = admissions::missing_counts($all);
    $group = function ($s) use ($missing) {
        if ($s->status === 'inquiry') {
            return 'new';
        }
        if ($s->status === 'applicant') {
            return empty($missing[$s->id]) ? 'ready' : 'missing';
        }
        return $s->status === 'waitlisted' ? 'waitlist' : 'decided';
    };
    $groups = ['all' => [], 'new' => [], 'missing' => [], 'ready' => [], 'waitlist' => [], 'decided' => []];
    foreach ($all as $s) {
        $groups['all'][] = $s;
        $groups[$group($s)][] = $s;
    }
    $show = isset($groups[$show]) ? $show : 'ready';
    $list = $groups[$show];
    $PAGE->set_url(new moodle_url($url, ['show' => $show]));
    echo staffarea::header();
    $shell = \local_studentrecords\local\shell::class;
    if ($manage) {
        echo html_writer::div(html_writer::link(
            new moodle_url('/local/studentrecords/student.php', ['edit' => 1]),
            get_string('adm_addapplicant', $p),
            ['class' => 'btn btn-outline-secondary']
        ), 'sr-actions justify-content-end mt-0');
    }
    $seg = '';
    foreach ($groups as $key => $rows) {
        $attrs = ['class' => 'sr-seg-item' . ($key === $show ? ' active' : '')];
        if ($key === $show) {
            $attrs['aria-current'] = 'page';
        }
        $seg .= html_writer::link(new moodle_url($url, ['show' => $key]), get_string('adm_show_' . $key, $p, count($rows)), $attrs);
    }
    echo html_writer::tag('nav', $seg, ['class' => 'sr-seg', 'aria-label' => get_string('adm_filter', $p)]);
    $table = new html_table();
    $table->attributes['class'] = 'sr-table';
    $table->head = [get_string('adm_applicant', $p), get_string('program', $p), get_string('adm_documents', $p),
        get_string('status', $p)];
    $chosenrec = null;
    foreach ($list as $s) {
        $docs = admissions::checklist($s);
        $required = array_filter($docs, fn($d) => !empty($d->required));
        $have = count(array_filter($required, fn($d) => in_array($d->state, ['received', 'waived'], true)));
        $g = $group($s);
        $tone = ['ready' => 'good', 'missing' => 'warn', 'new' => 'info', 'waitlist' => 'info', 'decided' => 'neutral'][$g];
        $tagtext = $g === 'decided' ? get_string('status_' . $s->status, $p) : get_string('adm_state_' . $g, $p);
        if ($g === 'waitlist') {
            $pos = 0;
            $waits = $DB->get_records_select(
                'local_studentrecords_wait',
                "kind = 'program' AND programid = ? AND status = 'waiting'",
                [(int)$s->programid],
                'timecreated, id'
            );
            foreach (array_values($waits) as $i => $w) {
                $pos = (int)$w->studentid === (int)$s->id ? $i + 1 : $pos;
            }
            $tagtext .= $pos ? ' #' . $pos : '';
        }
        $name = html_writer::link(new moodle_url($url, ['show' => $show, 'a' => $s->id]), s(students::display_name($s))) .
            html_writer::div(get_string('adm_applied', $p, userdate((int)$s->timecreated, get_string(
                'strftimedateshortmonthabbr',
                'langconfig'
            ))), 'sr-msg-meta');
        $row = new html_table_row([$name, $s->programid ? s($programs[$s->programid] ?? '') : '-',
            get_string('adm_docsof', $p, (object)['have' => $have, 'total' => count($required)]), $shell::tag($tagtext, $tone)]);
        if (!$chosenrec && (!$chosen || (int)$s->id === $chosen)) {
            $chosenrec = $s;
            $row->attributes['class'] = 'sr-row-selected';
        }
        $table->data[] = $row;
    }
    $listhtml = $table->data ? html_writer::table($table) : $shell::empty_state(get_string('adm_none', $p));
    $panel = '';
    if ($chosenrec) {
        $c = $chosenrec;
        $panel .= html_writer::tag('h2', s(students::display_name($c)), ['class' => 'sr-card-title h3']);
        $bits = [];
        if ($c->programid) {
            $bits[] = s($programs[$c->programid] ?? '');
        }
        if ($c->admittermid) {
            $bits[] = get_string('adm_starts', $p, s($terms[$c->admittermid] ?? ''));
        }
        $panel .= html_writer::tag('p', implode(' · ', $bits), ['class' => 'sr-muted']);
        $panel .= html_writer::tag('h3', get_string('adm_checklist', $p), ['class' => 'h5 mt-3']);
        $items = '';
        foreach (admissions::checklist($c) as $d) {
            $ok = in_array($d->state, ['received', 'waived'], true);
            $items .= html_writer::tag('li', html_writer::span(
                $ok ? '✓' : '○',
                $ok ? 'sr-tone-good' : 'sr-muted',
                ['aria-hidden' => 'true']
            ) . ' ' . format_string($d->name) . ' ' . html_writer::span($ok && $d->statedate ?
                catalog::show_date((int)$d->statedate) : get_string('docstate_' . $d->state, $p), 'sr-msg-meta float-end'));
        }
        $panel .= html_writer::tag('ul', $items, ['class' => 'sr-checklist']);
        $panel .= html_writer::link(
            new moodle_url('/local/studentrecords/student.php', ['id' => $c->id, 'tab' => 'admissions']),
            get_string('adm_openfile', $p)
        );
        if ($manage && in_array($c->status, ['inquiry', 'applicant', 'waitlisted'], true)) {
            $form = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'a', 'value' => $c->id]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'show', 'value' => $show]);
            $form .= html_writer::label(get_string('adm_note', $p), 'admnote', true, ['class' => 'd-block small mt-3']);
            $form .= html_writer::tag('textarea', '', ['name' => 'note', 'id' => 'admnote', 'rows' => 3,
                'class' => 'form-control']);
            $buttons = html_writer::tag('button', get_string('adm_admit', $p), ['type' => 'submit', 'name' => 'decide',
                'value' => 'admitted', 'class' => 'btn btn-primary']);
            if ($c->status !== 'waitlisted') {
                $buttons .= html_writer::tag('button', get_string('adm_waitlist', $p), ['type' => 'submit', 'name' => 'decide',
                    'value' => 'waitlisted', 'class' => 'btn btn-outline-secondary']);
            }
            $buttons .= html_writer::tag('button', get_string('adm_deny', $p), ['type' => 'submit', 'name' => 'decide',
                'value' => 'denied', 'class' => 'btn btn-outline-danger']);
            $form .= html_writer::div($buttons, 'sr-actions mb-2');
            $form .= html_writer::tag('p', get_string('adm_admithelp', $p), ['class' => 'sr-muted small mb-0']);
            $panel .= html_writer::tag('form', $form, ['method' => 'post', 'action' => $url->out_omit_querystring()]);
        }
        $panel = html_writer::div($panel, 'sr-card');
    }
    echo html_writer::div(html_writer::div($listhtml, 'sr-col-main') . html_writer::div($panel, 'sr-col-side'), 'sr-columns');
    $tabs = [];
    foreach (['pipeline', 'missing', 'decisions'] as $r) {
        $tabs[] = [new moodle_url('/local/studentrecords/admissions.php', ['report' => $r]), get_string('report_' . $r, $p)];
    }
    echo html_writer::tag('h2', get_string('adm_morereports', $p), ['class' => 'sr-h2']) . $shell::quick_links($tabs);
    echo $OUTPUT->footer();
    die();
}
$where = '1 = 1';
$sqlparams = [];
foreach (['admittermid' => $termid, 'programid' => $programid] as $field => $value) {
    if ($value) {
        $where .= " AND s.$field = ?";
        $sqlparams[] = $value;
    }
}
$link = fn(array $filter, $text) => html_writer::link(new moodle_url('/local/studentrecords/students.php', $filter), $text);

// Build the report as headers + rows (plain text for downloads, HTML for the page).
$head = [];
$rows = [];
$html = [];
if ($report === 'pipeline') {
    $statuses = array_merge(admissions::PIPELINE, ['enrolled', 'active']);
    $head = array_merge(
        [get_string('program', $p)],
        array_map(fn($st) => get_string('status_' . $st, $p), $statuses),
        [get_string('total')]
    );
    $counts = [];
    $rs = $DB->get_recordset_sql("SELECT s.programid, s.status, COUNT(1) AS n FROM {local_studentrecords_student} s
                                   WHERE $where GROUP BY s.programid, s.status", $sqlparams);
    foreach ($rs as $r) {
        $counts[(int)$r->programid][$r->status] = (int)$r->n;
    }
    $rs->close();
    $totals = array_fill_keys($statuses, 0);
    foreach ($counts as $pid => $bystatus) {
        $name = $pid ? ($programs[$pid] ?? '#' . $pid) : get_string('noprogram', $p);
        $line = [$name];
        $htmlline = [s($name)];
        foreach ($statuses as $st) {
            $n = $bystatus[$st] ?? 0;
            $totals[$st] += $n;
            $line[] = $n;
            $htmlline[] = $n ? $link(array_filter(['programid' => $pid, 'status' => $st, 'termid' => $termid]), $n) : '0';
        }
        $line[] = $htmlline[] = array_sum(array_intersect_key($bystatus, array_flip($statuses)));
        $rows[] = $line;
        $html[] = $htmlline;
    }
    if ($rows) {
        $rows[] = array_merge([get_string('total')], array_values($totals), [array_sum($totals)]);
        $html[] = array_map(fn($v) => html_writer::tag('strong', s($v)), end($rows));
    }
} else if ($report === 'missing') {
    $head = [get_string('studentid', $p), get_string('name'), get_string('program', $p), get_string('admitterm', $p),
        get_string('status', $p), get_string('missingdocuments', $p)];
    [$in, $inparams] = $DB->get_in_or_equal(admissions::PIPELINE);
    $list = $DB->get_records_sql("SELECT s.* FROM {local_studentrecords_student} s WHERE $where AND s.status $in
                                  ORDER BY s.legallastname, s.legalfirstname", array_merge($sqlparams, $inparams));
    foreach ($list as $s) {
        $docs = admissions::checklist($s);
        $states = [];
        foreach ($docs as $d) {
            $states[$d->id] = $d->state;
        }
        $missing = admissions::missing($docs, $states);
        if (!$missing) {
            continue;
        }
        $names = implode('; ', array_map(fn($d) => reports::plain($d->name), $missing));
        $line = [$s->studentid, students::display_name($s), $s->programid ? ($programs[$s->programid] ?? '') : '',
            $s->admittermid ? ($terms[$s->admittermid] ?? '') : '', get_string('status_' . $s->status, $p), $names];
        $rows[] = $line;
        $htmlline = array_map('s', $line);
        $htmlline[1] = html_writer::link(new moodle_url('/local/studentrecords/student.php', ['id' => $s->id]), s($line[1]));
        $html[] = $htmlline;
    }
} else {
    $head = [get_string('decisiondate', $p), get_string('studentid', $p), get_string('name'), get_string('program', $p),
        get_string('admitterm', $p), get_string('decision', $p), get_string('decisionreason', $p),
        get_string('decisionconditions', $p), get_string('decidedby', $p)];
    $dwhere = $where;
    $dparams = $sqlparams;
    if (in_array($decision, admissions::DECISIONS, true)) {
        $dwhere .= ' AND d.decision = ?';
        $dparams[] = $decision;
    }
    $list = $DB->get_records_sql("SELECT d.*, s.studentid AS sid, s.legalfirstname, s.legalmiddlename, s.legallastname,
                                         s.preferredname, s.programid, s.id AS recordid
                                    FROM {local_studentrecords_admdec} d
                                    JOIN {local_studentrecords_student} s ON s.id = d.studentid
                                   WHERE $dwhere
                                ORDER BY d.decisiondate DESC, d.id DESC", $dparams);
    foreach ($list as $d) {
        $line = [catalog::show_date((int)$d->decisiondate), $d->sid, students::display_name($d),
            $d->programid ? ($programs[$d->programid] ?? '') : '', $d->termid ? ($terms[$d->termid] ?? '') : '',
            get_string('decision_' . $d->decision, $p), (string)$d->reason, (string)$d->conditions,
            history_view::user_name((int)$d->decidedby)];
        $rows[] = $line;
        $htmlline = array_map('s', $line);
        $htmlline[2] = html_writer::link(new moodle_url('/local/studentrecords/student.php', ['id' => $d->recordid]), s($line[2]));
        $html[] = $htmlline;
    }
}

if ($download !== '') {
    audit::log('export', 'admissions', null, null, $report . ' ' . $download . ' ' . count($rows));
    reports::download('admissions_' . $report . '_' . date('Ymd'), $download, $head, $rows);
    die();
}

echo staffarea::header();
$tabs = [];
foreach (['applicants', 'pipeline', 'missing', 'decisions'] as $r) {
    $tabs[] = new tabobject(
        $r,
        new moodle_url('/local/studentrecords/admissions.php', ['report' => $r]),
        get_string('report_' . $r, $p)
    );
}
echo $OUTPUT->tabtree($tabs, $report);
echo html_writer::tag('p', get_string('report_' . $report . '_intro', $p));
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(),
    'class' => 'mb-3 d-flex flex-wrap align-items-end gap-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'report', 'value' => $report]);
$select = function ($name, $label, $options, $value) {
    $attrs = ['id' => 'ad' . $name, 'class' => 'form-select w-auto'];
    return html_writer::div(html_writer::label($label, 'ad' . $name, true, ['class' => 'd-block small']) .
        html_writer::select($options, $name, $value, false, $attrs));
};
echo $select('termid', get_string('admitterm', $p), [0 => get_string('all')] + array_map('s', $terms), $termid);
echo $select('programid', get_string('program', $p), [0 => get_string('all')] + array_map('s', $programs), $programid);
if ($report === 'decisions') {
    $options = ['' => get_string('all')];
    foreach (admissions::DECISIONS as $d) {
        $options[$d] = get_string('decision_' . $d, $p);
    }
    echo $select('decision', get_string('decision', $p), $options, $decision);
}
echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter', $p),
    'class' => 'btn btn-secondary']));
echo html_writer::end_tag('form');
$table = new html_table();
$table->head = $head;
$table->data = $html;
echo $html ? html_writer::table($table) : $OUTPUT->notification(get_string('nothingtoreport', $p), 'info');
echo $OUTPUT->download_dataformat_selector(
    get_string('download'),
    $url->out_omit_querystring(),
    'download',
    array_diff_key($params, ['download' => 1])
);
echo $OUTPUT->footer();
