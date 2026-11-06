<?php

/**
 * Reports: enrollment by term, credit hours, completion rate, graduation rate by cohort; each downloadable.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\audit;
use local_studentrecords\local\catalog;
use local_studentrecords\local\reports;
use local_studentrecords\local\staffarea;

$report = optional_param('report', 'enrollment', PARAM_ALPHA);
$report = in_array($report, ['enrollment', 'hours', 'completion', 'graduation', 'attendance', 'attendunder'], true) ? $report :
    'enrollment';
$termid = optional_param('termid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$p = 'local_studentrecords';
require_login(null, false);
$params = array_filter(['report' => $report, 'termid' => $termid]);
staffarea::setup('reports', new moodle_url('/local/studentrecords/reports.php', $params));
$url = $PAGE->url;
$programs = reports::program_names();
$prog = fn($id) => $id ? ($programs[$id] ?? '#' . $id) : get_string('noprogram', $p);
$num = fn($v) => $v === null ? '-' : format_float($v, -1);
$pct = fn($v) => $v === null ? '-' : format_float($v, 1) . '%';

if ($report === 'enrollment' || $report === 'hours') {
    $data = reports::enrollment($termid);
    if ($report === 'enrollment') {
        $head = [get_string('term', $p), get_string('program', $p), get_string('headcount', $p), get_string('load_fulltime', $p),
            get_string('load_parttime', $p), get_string('loadunknown', $p)];
        $rows = array_map(fn($r) => [$r['term'], $prog($r['programid']), $r['headcount'], $r['fulltime'], $r['parttime'],
            $r['unknown']], $data);
    } else {
        $head = [get_string('term', $p), get_string('program', $p), get_string('headcount', $p), get_string('hoursattempted', $p)];
        $rows = array_map(fn($r) => [$r['term'], $prog($r['programid']), $r['headcount'], $num($r['hours'])], $data);
    }
} else if ($report === 'completion') {
    $head = [get_string('term', $p), get_string('program', $p), get_string('coursesattempted', $p), get_string('audit_met', $p),
        get_string('audit_notpassed', $p), get_string('regstatus_withdrawn', $p), get_string('audit_inprogress', $p),
        get_string('completionrate', $p)];
    $rows = array_map(fn($r) => [$r['term'], $prog($r['programid']), $r['attempted'], $r['passed'], $r['notpassed'],
        $r['withdrawn'], $r['inprogress'], $pct($r['rate'])], reports::completion($termid));
} else if ($report === 'attendance' || $report === 'attendunder') {
    $att = \local_studentrecords\local\attendance::report($termid);
    if ($report === 'attendance') {
        $head = [get_string('term', $p), get_string('hist_course', $p), get_string('headcount', $p), get_string('meetings', $p),
            get_string('attendrate', $p)];
        $rows = array_map(fn($r) => [$r['term'], $r['course'], $r['students'], $r['meetings'], $pct($r['rate'])], $att['offers']);
    } else {
        $head = [get_string('student', $p), get_string('studentid', $p), get_string('hist_course', $p), get_string('term', $p),
            get_string('attendrate', $p), get_string('lastattended', $p)];
        $rows = array_map(
            fn($r) => [$r['student'], $r['studentno'], $r['course'], $r['term'], $pct($r['rate']), $r['lda']],
            $att['under']
        );
    }
} else {
    $head = [get_string('cohort', $p), get_string('program', $p), get_string('cohortsize', $p), get_string('status_graduated', $p),
        get_string('continuing', $p), get_string('status_withdrawn', $p), get_string('other', $p),
        get_string('graduationrate', $p)];
    $rows = array_map(fn($r) => [$r['cohort'] !== '' ? $r['cohort'] : get_string('none'), $prog($r['programid']), $r['students'],
        $r['graduated'], $r['continuing'], $r['withdrawn'], $r['other'], $pct($r['rate'])], reports::graduation());
}

if ($download !== '') {
    audit::log('export', 'report', null, null, $report . ' ' . $download . ' ' . count($rows));
    reports::download('studentrecords_' . $report . '_' . date('Ymd'), $download, $head, $rows);
    die();
}

echo staffarea::header();
$tabs = [];
foreach (['enrollment', 'hours', 'completion', 'graduation', 'attendance', 'attendunder'] as $r) {
    $taburl = new moodle_url('/local/studentrecords/reports.php', ['report' => $r]);
    $tabs[] = new tabobject($r, $taburl, get_string('report_' . $r, $p));
}
echo $OUTPUT->tabtree($tabs, $report);
echo html_writer::tag('p', get_string('report_' . $report . '_intro', $p));
if ($report !== 'graduation') {
    echo $OUTPUT->single_select(new moodle_url($url, ['report' => $report]), 'termid', [0 => get_string('all')] +
        array_map('format_string', catalog::terms()), $termid, null, null, ['label' => get_string('term', $p)]);
}
$table = new html_table();
$table->head = $head;
$table->data = array_map(fn($row) => array_map(fn($v) => s((string)$v), $row), $rows);
echo $rows ? html_writer::table($table) : $OUTPUT->notification(get_string('nothingtoreport', $p), 'info');
echo $OUTPUT->download_dataformat_selector(get_string('download'), $url->out_omit_querystring(), 'download', $params);
echo $OUTPUT->footer();
