<?php

/**
 * Outside reports (records staff): saved exports for a state agency, an accreditor or others (setup staff change them),
 * headcount and completions counts by IPEDS race/ethnicity and sex, and the National Student Clearinghouse enrollment file
 * (a starting file: check it against the Clearinghouse's current specification). Every download is logged.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\audit;
use local_studentrecords\local\catalog;
use local_studentrecords\local\outside;
use local_studentrecords\local\plans;
use local_studentrecords\local\reports;
use local_studentrecords\local\staffarea;

require_login(null, false);
$tab = optional_param('tab', 'exports', PARAM_ALPHA);
$tab = in_array($tab, ['exports', 'counts', 'nsc'], true) ? $tab : 'exports';
$exportid = optional_param('export', -1, PARAM_INT);
$run = optional_param('run', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$p = 'local_studentrecords';
$base = new moodle_url('/local/studentrecords/outsidereports.php');
staffarea::setup('outsidereports', new moodle_url($base, ['tab' => $tab]));
$syscontext = context_system::instance();
$configure = has_capability('local/studentrecords:configure', $syscontext);
$today = catalog::time_to_date(time());
$from = optional_param('from', (string)((int)substr($today, 0, 4) - 1) . '-07-01', PARAM_TEXT);
$to = optional_param('to', substr($today, 0, 4) . '-06-30', PARAM_TEXT);
$from = plans::valid_ymd($from) ? $from : (string)((int)substr($today, 0, 4) - 1) . '-07-01';
$to = plans::valid_ymd($to) ? $to : substr($today, 0, 4) . '-06-30';
$termid = optional_param('termid', 0, PARAM_INT);
$terms = catalog::terms();
$tabs = fn() => [new tabobject('exports', new moodle_url($base, ['tab' => 'exports']), get_string('savedexports', $p)),
    new tabobject('counts', new moodle_url($base, ['tab' => 'counts']), get_string('counts', $p)),
    new tabobject('nsc', new moodle_url($base, ['tab' => 'nsc']), get_string('clearinghouse', $p))];
$send = function (string $name, array $head, array $rows, string $info) use ($download) {
    audit::log('export', 'outside', null, null, $info . ' ' . $download . ' ' . count($rows));
    reports::download(clean_filename($name . '_' . date('Ymd')), $download, $head, $rows);
    die();
};

if (optional_param('starters', 0, PARAM_BOOL) && $configure) {
    require_sesskey();
    $n = outside::add_starters();
    redirect($base, get_string('startersadded', $p, $n), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($exportid >= 0) {
    require_capability('local/studentrecords:configure', $syscontext);
    $export = $exportid ? $DB->get_record(outside::EXPORTS, ['id' => $exportid], '*', MUST_EXIST) : null;
    $form = new \local_studentrecords\form\exportdef(new moodle_url($base, ['export' => $exportid]));
    if ($export) {
        $current = (array)$export;
        $current['fields'] = array_filter(explode(',', (string)$export->fields));
        $current['termid'] = (int)$export->termid;
        unset($current['id']);
        $form->set_data($current + ['export' => $exportid]);
    } else {
        $form->set_data(['export' => 0, 'purpose' => 'state', 'population' => 'term']);
    }
    if ($form->is_cancelled()) {
        redirect($base);
    }
    if ($data = $form->get_data()) {
        $record = (array)$data;
        $record['id'] = $exportid ?: null;
        outside::save_export($record);
        redirect($base, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->heading(get_string($export ? 'editexport' : 'addexport', $p));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

if ($run) {
    $export = $DB->get_record(outside::EXPORTS, ['id' => $run], '*', MUST_EXIST);
    if (outside::has_demographics($export)) {
        require_capability('local/studentrecords:viewdemographics', $syscontext);
    }
    $result = outside::run($export);
    if ($download !== '') {
        $send('outside_' . $export->name, $result['head'], $result['rows'], 'export ' . $export->id);
    }
    echo staffarea::header();
    echo $OUTPUT->heading(format_string($export->name));
    $note = $export->note ? ' ' . s($export->note) : '';
    echo html_writer::tag('p', get_string('exportrows', $p, count($result['rows'])) . $note);
    $table = new html_table();
    $table->head = array_map('s', $result['head']);
    $table->data = array_map(fn($row) => array_map(fn($v) => s((string)$v), $row), array_slice($result['rows'], 0, 50));
    echo $result['rows'] ? html_writer::table($table) : $OUTPUT->notification(get_string('nothingtoreport', $p), 'info');
    echo $OUTPUT->download_dataformat_selector(get_string('download'), $base->out_omit_querystring(), 'download', ['run' => $run]);
    echo html_writer::link($base, get_string('back'));
    echo $OUTPUT->footer();
    die();
}

if ($tab === 'counts') {
    [$fy, $fm, $fd] = array_map('intval', explode('-', $from));
    [$ty, $tm, $td] = array_map('intval', explode('-', $to));
    $fromt = make_timestamp($fy, $fm, $fd, 0, 0, 0, catalog::timezone());
    $tot = make_timestamp($ty, $tm, $td, 0, 0, 0, catalog::timezone());
    $kind = optional_param('kind', 'headcount', PARAM_ALPHA) === 'completions' ? 'completions' : 'headcount';
    $grid = $kind === 'headcount' ? outside::headcount($fromt, $tot) : outside::completions($fromt, $tot);
    $sexes = ['female', 'male', 'another', 'notreported'];
    $head = [get_string('level', $p), get_string('ipedscategory', $p)];
    foreach ($sexes as $sx) {
        $head[] = get_string('sex_' . $sx, $p);
    }
    $head[] = get_string('total');
    $rows = [];
    foreach (($kind === 'headcount' ? $grid : $grid['grid']) as $level => $cats) {
        foreach ($cats as $cat => $bysex) {
            $row = [$level !== '' ? $level : '-', get_string('ipeds_' . $cat, $p)];
            foreach ($sexes as $sx) {
                $row[] = $bysex[$sx] ?? 0;
            }
            $row[] = array_sum($bysex);
            $rows[] = $row;
        }
    }
    if ($kind === 'completions') {
        $phead = [get_string('programcode', $p), get_string('programname', $p), get_string('xf_cipcode', $p),
            get_string('xf_credential', $p), get_string('count', $p)];
        $prows = array_map(fn($r) => [$r['code'], $r['name'], $r['cip'], $r['credential'], $r['count']], $grid['programs']);
    }
    if ($download !== '') {
        if ($kind === 'completions' && optional_param('part', '', PARAM_ALPHA) === 'programs') {
            $send('completions_programs', $phead, $prows, 'completions programs ' . $from . ' ' . $to);
        }
        $send($kind, $head, $rows, $kind . ' ' . $from . ' ' . $to);
    }
    echo staffarea::header();
    echo $OUTPUT->tabtree($tabs(), 'counts');
    echo html_writer::tag('p', get_string('counts_intro', $p));
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $base->out_omit_querystring(), 'class' => 'row g-2 mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'counts']);
    $opts = '';
    foreach (['headcount', 'completions'] as $k) {
        $selected = $k === $kind ? ['selected' => 1] : [];
        $opts .= html_writer::tag('option', get_string('count_' . $k, $p), ['value' => $k] + $selected);
    }
    echo html_writer::div(html_writer::tag('select', $opts, ['name' => 'kind', 'class' => 'form-select',
        'aria-label' => get_string('report', 'moodle')]), 'col-auto');
    foreach (['from' => $from, 'to' => $to] as $name => $value) {
        echo html_writer::div(html_writer::label(get_string('date_' . $name, $p), 'id_' . $name, true, ['class' => 'me-1']) .
            html_writer::empty_tag('input', ['type' => 'date', 'name' => $name, 'id' => 'id_' . $name, 'value' => $value,
            'class' => 'form-control d-inline-block w-auto']), 'col-auto');
    }
    echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('show'),
        'class' => 'btn btn-secondary']), 'col-auto');
    echo html_writer::end_tag('form');
    $params = ['tab' => 'counts', 'kind' => $kind, 'from' => $from, 'to' => $to];
    if ($kind === 'completions') {
        $t = new html_table();
        $t->head = $phead;
        $t->data = array_map(fn($r) => array_map(fn($v) => s((string)$v), $r), $prows);
        echo $prows ? html_writer::table($t) : '';
        echo $OUTPUT->download_dataformat_selector(
            get_string('download'),
            $base->out_omit_querystring(),
            'download',
            $params + ['part' => 'programs']
        );
    }
    $t = new html_table();
    $t->head = $head;
    $t->data = array_map(fn($r) => array_map(fn($v) => s((string)$v), $r), $rows);
    echo $rows ? html_writer::table($t) : $OUTPUT->notification(get_string('nothingtoreport', $p), 'info');
    echo $OUTPUT->download_dataformat_selector(get_string('download'), $base->out_omit_querystring(), 'download', $params);
    echo $OUTPUT->footer();
    die();
}

if ($tab === 'nsc') {
    $rows = $termid ? outside::nsc_rows($termid) : [];
    if ($download !== '' && $termid) {
        $send('clearinghouse_' . ($terms[$termid] ?? $termid), outside::nsc_head(), $rows, 'clearinghouse term ' . $termid);
    }
    echo staffarea::header();
    echo $OUTPUT->tabtree($tabs(), 'nsc');
    echo $OUTPUT->notification(get_string('nsc_notice', $p), 'warning');
    echo $OUTPUT->single_select(
        new moodle_url($base, ['tab' => 'nsc']),
        'termid',
        array_map('format_string', $terms),
        $termid,
        ['' => 'choosedots'],
        null,
        ['label' => get_string('term', $p)]
    );
    if ($termid) {
        $problems = outside::nsc_problems($rows);
        if ($problems) {
            $items = [];
            foreach ($problems as $i => $keys) {
                $items[] = s(trim($rows[$i][1] . ' ' . $rows[$i][3]) . ' (' . $rows[$i][10] . ')') . ': ' .
                    implode(', ', array_map(fn($k) => get_string('nsc_' . $k, $p), $keys));
            }
            echo html_writer::div(html_writer::tag('strong', get_string('nsc_needfix', $p, count($problems))) .
                html_writer::alist($items), 'sr-note sr-note-bad');
        } else if ($rows) {
            echo html_writer::div(get_string('nsc_allgood', $p), 'sr-note sr-note-good');
        }
        $t = new html_table();
        $t->head = outside::nsc_head();
        $t->data = array_map(fn($r) => array_map(fn($v) => s((string)$v), $r), $rows);
        echo $rows ? html_writer::table($t) : $OUTPUT->notification(get_string('nothingtoreport', $p), 'info');
        echo $OUTPUT->download_dataformat_selector(
            get_string('download'),
            $base->out_omit_querystring(),
            'download',
            ['tab' => 'nsc', 'termid' => $termid]
        );
    }
    echo $OUTPUT->footer();
    die();
}

echo staffarea::header();
echo $OUTPUT->tabtree($tabs(), 'exports');
echo html_writer::tag('p', get_string('exports_intro', $p));
$shell = \local_studentrecords\local\shell::class;
$viewdemographics = has_capability('local/studentrecords:viewdemographics', $syscontext);
$cardshtml = '';
foreach (outside::exports() as $e) {
    $body = html_writer::div(get_string('purpose_' . $e->purpose, $p), 'sr-card-kicker');
    $body .= html_writer::tag('h3', format_string($e->name), ['class' => 'sr-card-title']);
    $body .= html_writer::tag('p', get_string('population_' . $e->population, $p) . ($e->termid ? ': ' .
        format_string($terms[$e->termid] ?? '') : '') . ' · ' . get_string(
            'rep_fields',
            $p,
            count(array_filter(explode(',', (string)$e->fields)))
        ), ['class' => 'sr-muted']);
    $mayrun = !outside::has_demographics($e) || $viewdemographics;
    $links = $mayrun ? html_writer::link(new moodle_url($base, ['run' => $e->id]), get_string('rep_run', $p), [
        'class' => 'btn btn-primary',
    ]) : '';
    if (!$mayrun) {
        $body .= html_writer::div(get_string(
            'rep_needsdemographics',
            $p,
            get_string('studentrecords:viewdemographics', $p)
        ), 'sr-note sr-note-warn small');
    }
    if ($configure) {
        $links .= ' ' . html_writer::link(
            new moodle_url($base, ['export' => $e->id]),
            get_string('settings'),
            ['class' => 'btn btn-link']
        );
    }
    $cardshtml .= html_writer::div($body . html_writer::div($links, 'sr-card-links'), 'sr-card');
}
$body = html_writer::div(get_string('rep_nsckicker', $p), 'sr-card-kicker') .
    html_writer::tag('h3', get_string('rep_nsctitle', $p), ['class' => 'sr-card-title']) .
    html_writer::tag('p', get_string('rep_nscdesc', $p), ['class' => 'sr-muted']) .
    html_writer::div(html_writer::link(
        new moodle_url($base, ['tab' => 'nsc']),
        get_string('rep_run', $p),
        ['class' => 'btn btn-primary']
    ), 'sr-card-links');
$cardshtml .= html_writer::div($body, 'sr-card');
echo html_writer::div($cardshtml, 'sr-cards');
if ($configure) {
    echo html_writer::div(html_writer::link(
        new moodle_url($base, ['export' => 0]),
        get_string('addexport', $p),
        ['class' => 'btn btn-outline-secondary']
    ) . ' ' . html_writer::link(new moodle_url($base, ['starters' => 1,
        'sesskey' => sesskey()]), get_string('addstarters', $p), ['class' => 'btn btn-outline-secondary']), 'sr-actions');
}
// Recent downloads (from the audit log).
echo html_writer::tag('h2', get_string('rep_recent', $p), ['class' => 'sr-h2']);
$recent = $DB->get_records_select(
    'local_studentrecords_log',
    "action = 'export' AND objecttype = 'outside'",
    null,
    'timecreated DESC',
    '*',
    0,
    10
);
if ($recent) {
    $t = new html_table();
    $t->attributes['class'] = 'sr-table';
    $t->head = [get_string('rep_what', $p), get_string('date'), get_string('rep_by', $p)];
    foreach ($recent as $r) {
        $t->data[] = [s((string)$r->info), userdate((int)$r->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            s(\local_studentrecords\local\history_view::user_name((int)$r->userid))];
    }
    echo html_writer::table($t);
} else {
    echo $shell::empty_state(get_string('rep_norecent', $p));
}
echo $OUTPUT->footer();
