<?php

/**
 * Money reports (Finance staff): who owes money (by age), payments by day, refunds, late plan payments, and the 1098-T
 * data of a calendar year as a spreadsheet for the school's tax software or tax service (no taxpayer ID is stored;
 * nothing is sent to the IRS). Downloads are logged.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\audit;
use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\moneyreports;
use local_studentrecords\local\plans;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

require_login(null, false);
$reports = ['owed', 'payments', 'refunds', 'late', 'tax1098'];
$report = optional_param('report', 'owed', PARAM_ALPHANUM);
$report = in_array($report, $reports, true) ? $report : 'owed';
$today = catalog::time_to_date(time());
$from = optional_param('from', substr($today, 0, 8) . '01', PARAM_TEXT);
$to = optional_param('to', $today, PARAM_TEXT);
$from = plans::valid_ymd($from) ? $from : substr($today, 0, 8) . '01';
$to = plans::valid_ymd($to) ? $to : $today;
$thisyear = (int)substr($today, 0, 4);
$year = optional_param('year', (int)substr($today, 5, 2) < 4 ? $thisyear - 1 : $thisyear, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$p = 'local_studentrecords';
$params = ['report' => $report] + (in_array($report, ['payments', 'refunds'], true) ? ['from' => $from, 'to' => $to] : []) +
    ($report === 'tax1098' ? ['year' => $year] : []);
staffarea::setup('billingreports', new moodle_url('/local/studentrecords/billingreports.php', $params));
$url = $PAGE->url;
$money = fn($v) => billing::money((float)$v);
$yn = fn($v) => $v ? get_string('yes') : get_string('no');

if ($report === 'owed') {
    $head = [get_string('student', $p), get_string('studentid', $p), get_string('age_d30', $p), get_string('age_d60', $p),
        get_string('age_d90', $p), get_string('age_older', $p), get_string('balance', $p)];
    $rows = array_map(fn($r) => [$r['name'], $r['number'], $money($r['d30']), $money($r['d60']), $money($r['d90']),
        $money($r['older']), $money($r['total'])], moneyreports::owed());
} else if ($report === 'payments') {
    $head = [get_string('date'), get_string('paymethod', $p), get_string('count', $p), get_string('amountusd', $p)];
    $data = moneyreports::payments($from, $to);
    $rows = array_map(
        fn($r) => [$r['day'], get_string('paymethod_' . $r['method'], $p), $r['count'], $money($r['total'])],
        $data
    );
    if ($rows) {
        $rows[] = [get_string('total'), '', array_sum(array_column($data, 'count')), $money(array_sum(array_column(
            $data,
            'total'
        )))];
    }
} else if ($report === 'refunds') {
    $head = [get_string('refunddate', $p), get_string('student', $p), get_string('studentid', $p),
        get_string('hist_course', $p), get_string('refundrule', $p), get_string('amountusd', $p), get_string('status', $p),
        get_string('paybackby', $p)];
    $rows = array_map(
        fn($r) => [catalog::time_to_date((int)$r->eventdate), students::display_name($r), $r->studentno,
        (string)$r->code, $r->rulename ?? get_string('norefundrule', $p), $money($r->amount),
        get_string('refundstatus_' . $r->status, $p), $r->duedate ? catalog::time_to_date((int)$r->duedate) : ''],
        moneyreports::refunds($from, $to)
    );
} else if ($report === 'late') {
    $head = [get_string('student', $p), get_string('studentid', $p), get_string('paymentplan', $p), get_string('planpayment', $p),
        get_string('duedate', $p), get_string('amountleft', $p), get_string('latefeeadded', $p), get_string('holdplaced', $p)];
    $rows = array_map(fn($i) => [students::display_name($i), $i->studentno, $i->planname, $i->seq ?
        get_string('planpaymentno', $p, (int)$i->seq) : get_string('downpayment', $p), catalog::time_to_date((int)$i->duedate),
        $money((float)$i->amount - (float)$i->paid), $yn($i->latefeeid), $yn($i->holdid)], moneyreports::late());
} else {
    $head = [get_string('studentid', $p), get_string('tax_first', $p), get_string('tax_middle', $p), get_string('tax_last', $p),
        get_string('tax_tin', $p), get_string('tax_address', $p), get_string('email'), get_string('tax_box1', $p),
        get_string('tax_box4', $p), get_string('tax_box5', $p), get_string('tax_box6', $p), get_string('tax_box7', $p),
        get_string('tax_box8', $p), get_string('tax_box9', $p), get_string('tax_billed', $p), get_string('tax_paid', $p),
        get_string('tax_paidback', $p)];
    $num = fn($v) => number_format((float)$v, 2, '.', '');
    $rows = array_map(fn($r) => [$r['number'], $r['first'], $r['middle'], $r['last'], '', $r['address'], $r['email'],
        $num($r['box1']), $num($r['box4']), $num($r['box5']), $num($r['box6']), $r['box7'] ? 'X' : '', $r['box8'] ? 'X' : '',
        $r['box9'] ? 'X' : '', $num($r['billed']), $num($r['paid']), $num($r['paidback'])], moneyreports::tax1098($year));
}

if ($download !== '') {
    audit::log('export', 'report', null, null, $report . ' ' . $download . ' ' . count($rows) . ($report === 'tax1098' ?
        ' year ' . $year : ''));
    $name = 'studentrecords_' . $report . ($report === 'tax1098' ? '_' . $year : '') . '_' . date('Ymd');
    \local_studentrecords\local\reports::download($name, $download, $head, $rows);
    die();
}

echo staffarea::header();
$tabs = [];
foreach ($reports as $r) {
    $tabs[] = new tabobject(
        $r,
        new moodle_url('/local/studentrecords/billingreports.php', ['report' => $r]),
        get_string('mreport_' . $r, $p)
    );
}
echo $OUTPUT->tabtree($tabs, $report);
echo html_writer::tag('p', get_string('mreport_' . $report . '_intro', $p));
if (in_array($report, ['payments', 'refunds'], true)) {
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'row g-2 mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'report', 'value' => $report]);
    foreach (['from' => $from, 'to' => $to] as $name => $value) {
        echo html_writer::div(html_writer::label(get_string('date_' . $name, $p), 'id_' . $name, true, ['class' => 'me-1']) .
            html_writer::empty_tag('input', ['type' => 'date', 'name' => $name, 'id' => 'id_' . $name, 'value' => $value,
            'class' => 'form-control d-inline-block w-auto']), 'col-auto');
    }
    echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('show'),
        'class' => 'btn btn-secondary']), 'col-auto');
    echo html_writer::end_tag('form');
}
if ($report === 'tax1098') {
    $years = [];
    for ($y = $thisyear; $y >= $thisyear - 6; $y--) {
        $years[$y] = $y;
    }
    echo $OUTPUT->single_select(
        new moodle_url($url, ['report' => 'tax1098']),
        'year',
        $years,
        $year,
        null,
        null,
        ['label' => get_string('taxyear', $p)]
    );
    echo $OUTPUT->notification(get_string('tax_notice', $p), 'warning');
    $shell = \local_studentrecords\local\shell::class;
    echo $shell::tiles([
        $shell::tile(get_string('tax_students', $p), (string)count($rows)),
        $shell::tile(get_string('tax_box1total', $p), billing::money(array_sum(array_map(fn($r) => (float)$r[7], $rows)))),
        $shell::tile(get_string('tax_box5total', $p), billing::money(array_sum(array_map(fn($r) => (float)$r[9], $rows)))),
        $shell::tile(get_string('tax_tin', $p), get_string('file_ssnnotstored', $p), get_string('tax_tinnote', $p)),
    ]);
}
$table = new html_table();
$table->attributes['class'] = 'sr-table';
$table->head = $head;
$table->data = array_map(fn($row) => array_map(fn($v) => s((string)$v), $row), $rows);
if ($report === 'tax1098') {
    foreach ($table->data as $i => $row) {
        $table->data[$i][4] = html_writer::span(get_string('file_ssnnotstored', $p), 'sr-muted');
    }
}
echo $rows ? html_writer::table($table) : $OUTPUT->notification(get_string('nothingtoreport', $p), 'info');
echo $OUTPUT->download_dataformat_selector(get_string('download'), $url->out_omit_querystring(), 'download', $params);
echo $OUTPUT->footer();
