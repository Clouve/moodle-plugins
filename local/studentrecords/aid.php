<?php

/**
 * Federal aid module (Financial Aid role; only when the school turned the module on): students and their aid awards and
 * disbursements, aid recipients by award year,
 * disbursements, enrollment status of aid recipients, withdrawals with the percent of the term completed (helper data for
 * the school's own Return of Title IV funds calculation), and a 90/10 worksheet. Downloads are logged.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\aid;
use local_studentrecords\local\aidhelp;
use local_studentrecords\local\audit;
use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\plans;
use local_studentrecords\local\staffarea;

require_login(null, false);
$reports = ['students', 'run', 'returns', 'verification', 'recipients', 'disbursements', 'enrollment', 'withdrawals',
    'ninetyten'];
$report = optional_param('report', 'students', PARAM_ALPHA);
$report = in_array($report, $reports, true) ? $report : 'students';
$download = optional_param('download', '', PARAM_ALPHA);
$p = 'local_studentrecords';
$today = catalog::time_to_date(time());
$y = (int)substr($today, 0, 4);
$defaultyear = ((int)substr($today, 5, 2) >= 7 ? $y : $y - 1);
$awardyear = optional_param('awardyear', $defaultyear . '-' . substr((string)($defaultyear + 1), 2), PARAM_TEXT);
$awardyear = preg_match('/^\d{4}-\d{2}$/', $awardyear) ? $awardyear : $defaultyear . '-' . substr((string)($defaultyear + 1), 2);
$from = optional_param('from', $defaultyear . '-07-01', PARAM_TEXT);
$to = optional_param('to', ($defaultyear + 1) . '-06-30', PARAM_TEXT);
$from = plans::valid_ymd($from) ? $from : $defaultyear . '-07-01';
$to = plans::valid_ymd($to) ? $to : ($defaultyear + 1) . '-06-30';
$termid = optional_param('termid', 0, PARAM_INT);
$params = ['report' => $report, 'awardyear' => $awardyear, 'from' => $from, 'to' => $to, 'termid' => $termid];
staffarea::setup('aid', new moodle_url('/local/studentrecords/aid.php', $params));
$url = $PAGE->url;
$money = fn($v) => billing::money((float)$v);
$fund = fn($f) => get_string('fund_' . $f, $p);
$syscontext = context_system::instance();
$manageaid = has_capability('local/studentrecords:manageaid', $syscontext);
$tabs = function () use ($reports, $p) {
    $out = [];
    foreach ($reports as $r) {
        $taburl = new moodle_url('/local/studentrecords/aid.php', ['report' => $r]);
        $out[] = new tabobject($r, $taburl, get_string('aidreport_' . $r, $p));
    }
    return $out;
};

if (optional_param('createrole', 0, PARAM_BOOL)) {
    require_sesskey();
    require_capability('moodle/role:manage', $syscontext);
    aid::create_role();
    redirect(
        new moodle_url('/local/studentrecords/aid.php'),
        get_string('finaidrole_created', $p),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($report === 'students') {
    $studentid = optional_param('id', 0, PARAM_INT);
    $action = optional_param('action', '', PARAM_ALPHA);
    $item = optional_param('item', 0, PARAM_INT);
    if ($studentid) {
        $student = \local_studentrecords\local\students::get($studentid);
        $here = new moodle_url('/local/studentrecords/aid.php', ['report' => 'students', 'id' => $studentid]);
        if (in_array($action, ['aidaward', 'disburse'], true)) {
            require_capability('local/studentrecords:manageaid', $syscontext);
            $award = $item ? $DB->get_record(aid::AWARDS, ['id' => $item, 'studentid' => $studentid], '*', MUST_EXIST) : null;
            $left = $award ? (float)$award->amount - (float)(aid::awards($studentid)[$award->id]->disbursed ?? 0) : 0.0;
            $form = new \local_studentrecords\form\aidaward(
                new moodle_url($here, ['action' => $action, 'item' => $item]),
                ['mode' => $action === 'disburse' ? 'disburse' : 'award', 'left' => $left]
            );
            $current = ['id' => $studentid, 'action' => $action, 'item' => $item, 'txndate' => time()];
            if ($award && $action === 'aidaward') {
                $current += ['awardyear' => $award->awardyear, 'termid' => (int)$award->termid, 'fund' => $award->fund,
                    'status' => $award->status, 'note' => $award->note,
                    'amount' => format_float((float)$award->amount, 2, false)];
            }
            $form->set_data($current);
            if ($form->is_cancelled()) {
                redirect($here);
            }
            if ($data = $form->get_data()) {
                try {
                    if ($action === 'aidaward') {
                        aid::save_award($student, ['id' => $item ?: null, 'awardyear' => $data->awardyear,
                            'termid' => $data->termid, 'fund' => $data->fund, 'status' => $data->status, 'note' => $data->note,
                            'amount' => billing::parse_amount((string)$data->amount)]);
                    } else {
                        aid::disburse($item, billing::parse_amount((string)$data->amount), (int)$data->txndate);
                    }
                    redirect($here, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
                } catch (moodle_exception $e) {
                    redirect($here, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
                }
            }
            echo staffarea::header();
            echo $OUTPUT->heading(s(\local_studentrecords\local\students::display_name($student)) . ': ' .
                get_string($action === 'disburse' ? 'aiddisburse' : 'aidaward', $p));
            $form->display();
            echo $OUTPUT->footer();
            return;
        }
        $shell = \local_studentrecords\local\shell::class;
        $year = aidhelp::year($studentid, $awardyear);
        if ($action === 'aidyear' && $manageaid && optional_param('saveyear', 0, PARAM_BOOL)) {
            require_sesskey();
            $num = function (string $name) {
                $v = trim(optional_param($name, '', PARAM_RAW_TRIMMED));
                $amount = $v === '' ? null : billing::parse_amount(ltrim($v, '-'));
                // The Student Aid Index can be below zero.
                return $amount !== null && $name === 'sai' && str_starts_with($v, '-') ? -$amount : $amount;
            };
            aidhelp::save_year($student, ['awardyear' => $awardyear, 'coa' => $num('coa'), 'sai' => $num('sai'),
                'dependency' => optional_param('dependency', 'dependent', PARAM_ALPHA),
                'gradelevel' => optional_param('gradelevel', 1, PARAM_INT),
                'verification' => optional_param('verification', 'none', PARAM_ALPHA),
                'counseling' => optional_param('counseling', 0, PARAM_BOOL), 'note' => optional_param('note', '', PARAM_TEXT)]);
            redirect(
                new moodle_url($here, ['awardyear' => $awardyear]),
                get_string('saved', $p),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        if ($action === 'letter' && $manageaid) {
            require_sesskey();
            \local_studentrecords\local\messages::student('aidletter', $student, ['term' => $awardyear]);
            audit::log('update', 'aid', $studentid, (int)$student->userid, 'award letter ' . $awardyear);
            redirect(
                new moodle_url($here, ['awardyear' => $awardyear]),
                get_string('aid_lettersent', $p),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        audit::log('view', 'aid', $studentid, (int)$student->userid);
        $PAGE->set_heading(get_string('aid_awardstitle', $p, (object)['name' => \local_studentrecords\local\students::display_name(
            $student
        ), 'year' => $awardyear]));
        echo staffarea::header();
        $terms = catalog::terms();
        $awards = array_filter(aid::awards($studentid), fn($a) => $a->awardyear === $awardyear);
        $program = $student->programid ? (catalog::programs(false)[$student->programid] ?? '') : '';
        $vtone = ['none' => 'neutral', 'selected' => 'warn', 'complete' => 'good'][$year->verification];
        echo html_writer::tag('p', s($student->studentid) . ($program !== '' ? ' · ' . $program : '') . ' · ' .
            get_string('aid_level_' . (int)$year->gradelevel, $p) . ', ' . get_string('aid_dep_' . $year->dependency, $p) . ' ' .
            $shell::tag(get_string('aid_verif_' . $year->verification, $p), $vtone) . ' ' .
            $shell::tag(
                get_string($year->counseling ? 'aid_counsel_done' : 'aid_counsel_notdone', $p),
                $year->counseling ? 'good' : 'warn'
            ), ['class' => 'sr-lead']);
        if ($manageaid) {
            echo html_writer::div(
                html_writer::link(
                    new moodle_url($here, ['action' => 'aidaward']),
                    get_string('aid_addaward', $p),
                    ['class' => 'btn btn-outline-secondary']
                ) . ' ' . html_writer::tag('form', html_writer::empty_tag(
                    'input',
                    ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]
                ) . html_writer::empty_tag('input', ['type' => 'hidden',
                'name' => 'action', 'value' => 'letter']) . html_writer::empty_tag('input', ['type' => 'hidden',
                    'name' => 'awardyear',
                'value' => $awardyear]) . html_writer::tag('button', get_string('aid_sendletter', $p), ['type' => 'submit',
                'class' => 'btn btn-outline-secondary']), ['method' => 'post', 'action' => $here->out_omit_querystring()
                    . '?report=students&id=' .
                $studentid, 'class' => 'd-inline']) . ' ' . html_writer::link(new moodle_url(
                    '/local/studentrecords/aidletter.php',
                    ['id' => $studentid, 'year' => $awardyear]
                ), get_string('aid_viewletter', $p), ['class' => 'btn btn-link']),
                'sr-actions justify-content-end mt-0'
            );
        }
        $offered = 0.0;
        $loans = [];
        foreach ($awards as $a) {
            if (in_array($a->status, ['offered', 'accepted'], true)) {
                $offered += (float)$a->amount;
                if (in_array($a->fund, aid::LOANS, true)) {
                    $loans[$a->fund] = ($loans[$a->fund] ?? 0) + (float)$a->amount;
                }
            }
        }
        $need = ($year->coa !== null) ? aidhelp::need((float)$year->coa, (float)$year->sai) : null;
        echo $shell::tiles([
            $shell::tile(get_string('aid_coa', $p), $year->coa !== null ? $money($year->coa) : '-'),
            $shell::tile(get_string('aid_sai', $p), $year->sai !== null ? format_float((float)$year->sai, 0) : '-'),
            $shell::tile(get_string('aid_need', $p), $need !== null ? $money($need) : '-'),
            $shell::tile(
                get_string('aid_needleft', $p),
                $need !== null ? $money(max(0, $need - $offered)) : '-',
                $need !== null &&
                $offered > $need + 0.004 ? get_string('aid_overneed', $p, $money($offered - $need)) : '',
                null,
                '',
                $need !== null && $offered > $need + 0.004 ? 'bad' : ''
            ),
        ]);
        $loanterms = [];
        foreach ($awards as $a) {
            if ($a->termid && in_array($a->status, ['offered', 'accepted'], true) && in_array($a->fund, aid::LOANS, true)) {
                $loanterms[(int)$a->termid] = (int)$a->termid;
            }
        }
        $graduate = aidhelp::is_graduate($student);
        $percent = aidhelp::enrolled_percent($student, $awardyear, $loanterms);
        $check = $graduate ? null : aidhelp::loan_check($loans, $year->dependency, (int)$year->gradelevel, $percent);
        if ($graduate && $loans) {
            echo html_writer::div(get_string('aid_loangraduate', $p), 'sr-note sr-note-warn');
        } else if ($loans && $percent < 100) {
            [$limit, $sublimit] = aidhelp::limits($year->dependency, (int)$year->gradelevel, $percent);
            echo html_writer::div(get_string('aid_loanreduced', $p, (object)['percent' => $percent, 'limit' => $money($limit),
                'sublimit' => $money($sublimit)]), 'sr-note sr-note-warn');
        }
        if ($check) {
            $over = (object)['limit' => $money($check['limit']), 'total' => $money($check['total']),
                'sublimit' => $money($check['sublimit']), 'sub' => $money($check['sub']),
                'lower' => $money($check['lowerunsubto'])];
            $overkey = $check['fund'] === 'dlsub' ? 'aid_loansubover' : 'aid_loanover';
            echo html_writer::div(get_string($overkey, $p, $over), 'sr-note sr-note-bad', ['role' => 'alert']);
        }
        $table = new html_table();
        $table->attributes['class'] = 'sr-table';
        $table->head = [get_string('fund', $p), get_string('term', $p), get_string('amountusd', $p), get_string('aiddisbursed', $p),
            get_string('aid_decision', $p), ''];
        $table->colclasses = [2 => 'text-end', 3 => 'text-end'];
        $tones = ['offered' => 'neutral', 'accepted' => 'good', 'declined' => 'bad', 'cancelled' => 'neutral'];
        foreach ($awards as $a) {
            $links = [];
            if ($manageaid) {
                $links[] = html_writer::link(new moodle_url($here, ['action' => 'aidaward', 'item' => $a->id]), get_string('edit'));
                if ($a->status === 'accepted' && aid::credit_kind($a->fund) !== null && (float)$a->disbursed < (float)$a->amount) {
                    $links[] = html_writer::link(
                        new moodle_url($here, ['action' => 'disburse', 'item' => $a->id]),
                        get_string('aiddisburse', $p)
                    );
                }
            }
            $row = new html_table_row([$fund($a->fund), $a->termid ? format_string($terms[$a->termid] ?? '') : '-',
                $money($a->amount), $money($a->disbursed), $shell::tag(
                    get_string('aidstatus_' . $a->status, $p),
                    $tones[$a->status] ?? 'neutral'
                ), implode(' | ', $links)]);
            if ($check && $a->fund === $check['fund']) {
                $row->attributes['class'] = 'sr-row-bad';
            }
            $table->data[] = $row;
        }
        if ($table->data) {
            $table->data[] = [html_writer::tag('strong', get_string('aid_totaloffered', $p)), '', html_writer::tag(
                'strong',
                $money($offered)
            ), '', '', ''];
        }
        echo $table->data ? html_writer::table($table) : $shell::empty_state(get_string('noaid', $p));
        // Aid year details form.
        $f = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'aidyear']) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'saveyear', 'value' => 1]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'awardyear', 'value' => $awardyear]);
        $txt = fn($name, $value) => html_writer::div(html_writer::label(get_string('aid_' . $name, $p), 'ay' . $name) .
            html_writer::empty_tag('input', ['type' => 'text', 'inputmode' => 'decimal', 'name' => $name, 'id' => 'ay' . $name,
            'class' => 'form-control', 'value' => $value === null ? '' : format_float((float)$value, 2, false)]), 'sr-field');
        $sel = fn($name, $options, $value) => html_writer::div(html_writer::label(get_string('aid_' . $name, $p), 'ay' . $name) .
            html_writer::select($options, $name, $value, false, ['id' => 'ay' . $name, 'class' => 'form-select']), 'sr-field');
        $f .= $txt('coa', $year->coa) . $txt('sai', $year->sai);
        $f .= $sel('dependency', ['dependent' => get_string('aid_dep_dependent', $p), 'independent' =>
            get_string('aid_dep_independent', $p)], $year->dependency);
        $f .= $sel('gradelevel', [1 => get_string('aid_level_1', $p), 2 => get_string('aid_level_2', $p), 3 =>
            get_string('aid_level_3', $p)], (int)$year->gradelevel);
        $f .= $sel('verification', ['none' => get_string('aid_verif_none', $p), 'selected' => get_string('aid_verif_selected', $p),
            'complete' => get_string('aid_verif_complete', $p)], $year->verification);
        $f .= html_writer::div(
            html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'counseling', 'value' => 1,
            'id' => 'aycounseling', 'class' => 'form-check-input'] + ($year->counseling ? ['checked' => 'checked'] : [])) . ' ' .
            html_writer::label(get_string('aid_counseling', $p), 'aycounseling', false, ['class' => 'form-check-label']),
            'sr-field form-check'
        );
        if ($manageaid) {
            $f .= html_writer::div(html_writer::tag('button', get_string('savechanges'), ['type' => 'submit',
                'class' => 'btn btn-primary']), 'sr-field');
        }
        echo html_writer::start_tag('details', ['class' => 'sr-details mt-3'] + ($year->id ? [] : ['open' => 'open']));
        echo html_writer::tag('summary', get_string('aid_yeardetails', $p, $awardyear), ['class' => 'sr-h2']);
        echo html_writer::tag('form', $f, ['method' => 'post', 'action' => $here->out_omit_querystring() . '?report=students&id=' .
            $studentid, 'class' => 'sr-filter']);
        echo html_writer::tag('p', get_string('aid_helpernote', $p), ['class' => 'sr-muted small']);
        echo html_writer::end_tag('details');
        echo html_writer::div(html_writer::link(new moodle_url('/local/studentrecords/aid.php'), get_string('back')));
        echo $OUTPUT->footer();
        return;
    }
    $search = trim(optional_param('q', '', PARAM_TEXT));
    echo staffarea::header();
    echo $OUTPUT->tabtree($tabs(), 'students');
    echo html_writer::tag('p', get_string('aidreport_students_intro', $p));
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'row g-2 mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'report', 'value' => 'students']);
    echo html_writer::div(html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => $search,
        'class' => 'form-control', 'placeholder' => get_string('searchnameid', $p),
        'aria-label' => get_string('searchnameid', $p)]), 'col-auto');
    echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'),
        'class' => 'btn btn-secondary']), 'col-auto');
    echo html_writer::end_tag('form');
    $table = new html_table();
    $table->head = [get_string('student', $p), get_string('studentid', $p), get_string('status', $p), get_string('aidawards', $p)];
    foreach (aid::students($search) as $s) {
        $studenturl = new moodle_url('/local/studentrecords/aid.php', ['report' => 'students', 'id' => $s->id]);
        $name = s(\local_studentrecords\local\students::display_name($s));
        $table->data[] = [html_writer::link($studenturl, $name), s($s->studentid), get_string('status_' . $s->status, $p),
            (int)$s->awards];
    }
    echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('nothingfound', $p));
    $roleid = $DB->get_field('role', 'id', ['shortname' => aid::ROLE]);
    echo $OUTPUT->heading(get_string('finaidrole', $p), 3);
    if ($roleid) {
        echo html_writer::tag('p', get_string('finaidrole_exists', $p));
        if (has_capability('moodle/role:assign', $syscontext)) {
            echo html_writer::link(new moodle_url('/admin/roles/assign.php', ['contextid' => $syscontext->id,
                'roleid' => $roleid]), get_string('finaidrole_assign', $p));
        }
    } else {
        echo html_writer::tag('p', get_string('finaidrole_intro', $p));
        if (has_capability('moodle/role:manage', $syscontext)) {
            echo html_writer::link(
                new moodle_url('/local/studentrecords/aid.php', ['createrole' => 1, 'sesskey' => sesskey()]),
                get_string('finaidrole_create', $p),
                ['class' => 'btn btn-secondary']
            );
        }
    }
    echo $OUTPUT->footer();
    return;
}
$shell = \local_studentrecords\local\shell::class;
$termselect = function () use ($termid, $report, $url, $p) {
    $options = html_writer::tag('option', get_string('choosedots'), ['value' => 0]);
    foreach (catalog::terms() as $tid => $name) {
        $options .= html_writer::tag(
            'option',
            format_string($name),
            ['value' => $tid] + ($tid == $termid ? ['selected' => 1] : [])
        );
    }
    return html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'report', 'value' => $report]) .
        html_writer::div(html_writer::label(get_string('term', $p), 'aidterm') . html_writer::tag(
            'select',
            $options,
            ['name' => 'termid', 'id' => 'aidterm', 'class' => 'form-select']
        ), 'sr-field') . html_writer::div(html_writer::tag(
            'button',
            get_string('show'),
            ['type' => 'submit', 'class' => 'btn btn-outline-secondary']
        ), 'sr-field'), ['method' => 'get',
        'action' => $url->out_omit_querystring(), 'class' => 'sr-filter']);
};
if ($report === 'run') {
    if ($termid && $manageaid && optional_param('send', 0, PARAM_BOOL)) {
        require_sesskey();
        $r = aidhelp::send_ready($termid);
        redirect(new moodle_url($url, ['termid' => $termid]), get_string('aid_runsent', $p, (object)['n' => $r['count'],
            'total' => $money($r['total'])]), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->tabtree($tabs(), $report);
    echo $termselect();
    if ($termid) {
        $rows = aidhelp::run($termid);
        $ready = array_filter($rows, fn($r) => !$r['reasons'] && !$r['payroll']);
        $held = array_filter($rows, fn($r) => (bool)$r['reasons']);
        $paid = (float)$DB->get_field_sql("SELECT COALESCE(SUM(-t.amount), 0) FROM {" . billing::TXN . "} t JOIN {" . aid::AWARDS .
            "} a ON a.id = t.aidid WHERE a.termid = ?", [$termid]);
        echo $shell::tiles([
            $shell::tile(get_string('aid_ready', $p), count($ready) . ' · ' . $money(array_sum(array_column($ready, 'left')))),
            $shell::tile(
                get_string('aid_held', $p),
                count($held) . ' · ' . $money(array_sum(array_column($held, 'left'))),
                '',
                null,
                '',
                $held ? 'warn' : ''
            ),
            $shell::tile(get_string('aid_paidterm', $p), $money($paid)),
        ]);
        if ($ready && $manageaid) {
            echo html_writer::tag(
                'form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'send', 'value' => 1]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'termid', 'value' => $termid]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'report', 'value' => 'run']) .
                html_writer::tag('button', get_string('aid_sendready', $p, (object)['n' => count($ready),
                'total' => $money(array_sum(array_column($ready, 'left')))]), ['type' => 'submit', 'class' => 'btn btn-primary']),
                ['method' => 'post', 'action' => $url->out_omit_querystring(), 'class' => 'sr-actions']
            );
        }
        $t = new html_table();
        $t->attributes['class'] = 'sr-table';
        $t->head = [get_string('student', $p), get_string('fund', $p), get_string('amountusd', $p), get_string('status', $p), ''];
        $t->colclasses = [2 => 'text-end'];
        foreach ($rows as $r) {
            $sid = (int)$r['student']->id;
            if ($r['payroll']) {
                $status = $shell::tag(get_string('aid_payroll', $p), 'neutral');
                $link = '';
            } else if (!$r['reasons']) {
                $status = $shell::tag(get_string('aid_readytag', $p), 'good');
                $link = '';
            } else {
                $status = implode(' ', array_map(fn($k) => $shell::tag(get_string('aid_' . $k, $p), $k === 'stopped_withdrawn' ?
                    'bad' : 'warn'), $r['reasons']));
                $link = in_array('stopped_withdrawn', $r['reasons'], true) ? html_writer::link(new moodle_url(
                    $url,
                    ['report' => 'returns', 'termid' => $termid, 'id' => $sid]
                ), get_string('nav_returnofaid', $p)) :
                    html_writer::link(new moodle_url($url, ['report' => 'students', 'id' => $sid]), get_string('home_review', $p));
            }
            $t->data[] = [html_writer::link(
                new moodle_url($url, ['report' => 'students', 'id' => $sid]),
                s(\local_studentrecords\local\students::display_name($r['student']))
            ), $fund($r['award']->fund),
                $money($r['left']), $status, $link];
        }
        echo $t->data ? html_writer::table($t) : $shell::empty_state(get_string('aid_runempty', $p));
        echo html_writer::tag('p', get_string('aid_runnote', $p), ['class' => 'sr-muted']);
    }
    echo $OUTPUT->footer();
    return;
}
if ($report === 'returns') {
    $sid = optional_param('id', 0, PARAM_INT);
    if ($sid && $termid) {
        $student = \local_studentrecords\local\students::get($sid);
        $typed = [];
        foreach (aidhelp::WORKSHEETFIELDS as $name) {
            $typed[$name] = optional_param($name, '', PARAM_RAW_TRIMMED);
        }
        $details = aidhelp::worksheet_details($typed);
        if ($manageaid && optional_param('sendreturn', 0, PARAM_BOOL)) {
            require_sesskey();
            try {
                aidhelp::send_return($student, $termid, $details, required_param('expected', PARAM_INT));
                redirect(
                    new moodle_url($url, ['termid' => $termid]),
                    get_string('aid_returnsent', $p),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            } catch (moodle_exception $e) {
                redirect(
                    new moodle_url($url, ['termid' => $termid, 'id' => $sid] + array_filter($typed, 'strlen')),
                    $e->getMessage(),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
        }
        $w = aidhelp::worksheet($student, $termid, $details);
        $name = \local_studentrecords\local\students::display_name($student);
        $PAGE->set_heading(get_string('aid_returntitle', $p, $name));
        echo staffarea::header();
        if (!$w) {
            echo $shell::empty_state(get_string('aid_noaidpaid', $p));
            echo $OUTPUT->footer();
            return;
        }
        $termname = format_string(catalog::terms()[$termid] ?? '');
        echo html_writer::tag('p', $w['needsdate'] ? get_string('aid_returnnodate', $p, (object)['term' => $termname,
            'days' => $w['daysterm']]) : get_string('aid_returnmeta', $p, (object)['date' => catalog::show_date($w['withdrew']),
            'term' => $termname, 'days' => $w['daysterm']]), ['class' => 'sr-lead']);
        if (!$w['needsdate']) {
            echo html_writer::div(get_string('aid_returnhelper', $p, catalog::show_date($w['duedate'])), 'sr-note sr-note-warn');
        }

        // What the worksheet can't know by itself. The figures below follow these details.
        $amount = fn(float $v) => format_float($v, 2, false);
        $field = function (string $name, string $value, string $hint = '', string $type = 'text') use ($p) {
            $id = 'ws' . $name;
            $input = ['type' => $type, 'name' => $name, 'id' => $id, 'class' => 'form-control', 'value' => $value];
            if ($type === 'text') {
                $input['inputmode'] = 'decimal';
            }
            if ($hint !== '') {
                $input['aria-describedby'] = $id . 'hint';
            }
            return html_writer::div(
                html_writer::label(get_string('aid_ws_' . $name, $p), $id) . html_writer::empty_tag('input', $input) .
                ($hint !== '' ? html_writer::div($hint, 'sr-field-hint', ['id' => $id . 'hint']) : ''),
                'sr-field sr-field-fixed'
            );
        };
        $notpaid = fn(float $v) => $v > 0 ? get_string('aid_ws_notpaid', $p, $money($v)) : '';
        $hidden = fn(string $name, $value) => html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name,
            'value' => $value]);
        $f = $hidden('report', 'returns') . $hidden('termid', $termid) . $hidden('id', $sid);
        $f .= $field('withdrew', $w['withdrewday'], '', 'date');
        $f .= $field('determined', $w['determinedday'], '', 'date');
        $f .= $field('charges', $amount($w['charges']), get_string('aid_ws_chargeshint', $p, $money($w['chargesfound'])) .
            ($w['chargeslater'] > 0 ? ' ' . get_string('aid_ws_chargeslater', $p, $money($w['chargeslater'])) : ''));
        $f .= $field('couldgrants', $amount((float)($details['couldgrants'] ?? 0)), $notpaid($w['notpaid']['grants']));
        $f .= $field('couldloans', $amount((float)($details['couldloans'] ?? 0)), $notpaid($w['notpaid']['loans']));
        if ($w['clock']) {
            $f .= $field('hoursdone', isset($details['hoursdone']) ? $amount($details['hoursdone']) : '');
            $f .= $field('hoursperiod', isset($details['hoursperiod']) ? $amount($details['hoursperiod']) : '');
        } else {
            $f .= $field('breakdays', (string)$w['breakdays'], get_string('aid_ws_breakhint', $p));
            $f .= $field('breaksbefore', (string)$w['breaksbefore']);
        }
        $f .= html_writer::div(html_writer::tag('button', get_string('aid_ws_update', $p), ['type' => 'submit',
            'class' => 'btn btn-secondary']), 'sr-field w-100');
        echo html_writer::start_tag('details', ['class' => 'sr-details mb-3', 'open' => 'open']);
        echo html_writer::tag('summary', get_string('aid_ws_title', $p), ['class' => 'sr-h2']);
        echo html_writer::tag('form', $f, ['method' => 'get', 'action' => $url->out_omit_querystring(),
            'class' => 'sr-filter sr-filter-top']);
        echo html_writer::end_tag('details');
        $unused = array_merge($w['ignored'], array_keys(array_diff_key(array_filter($typed, 'strlen'), $details)));
        if ($unused) {
            echo html_writer::div(get_string('aid_ws_ignored', $p, implode(', ', array_map(
                fn($name) => get_string('aid_ws_' . $name, $p),
                array_unique($unused)
            ))), 'sr-note sr-note-bad', ['role' => 'alert']);
        }
        if ($w['needsdate']) {
            echo html_writer::div(get_string('aid_ws_needdate', $p), 'sr-note sr-note-bad', ['role' => 'alert']);
            echo $OUTPUT->footer();
            return;
        }
        if ($w['needshours']) {
            echo html_writer::div(get_string('aid_ws_needhours', $p), 'sr-note sr-note-bad', ['role' => 'alert']);
        }
        if ($w['paidlater'] > 0) {
            echo html_writer::div(get_string('aid_ws_paidlater', $p, $money($w['paidlater'])), 'sr-note sr-note-warn');
        }
        if ($w['noterm'] > 0) {
            echo html_writer::div(get_string('aid_ws_noterm', $p, $money($w['noterm'])), 'sr-note sr-note-warn');
        }

        $paidtext = implode(' · ', array_map(fn($f, $v) => $fund($f) . ' ' . $money($v), array_keys($w['funds']), $w['funds']));
        $count = (object)['done' => format_float($w['done'], -1), 'days' => format_float($w['period'], -1)];
        $steptwo = $w['clock'] && !$w['needshours'] ? 'aid_r2hours' : ($w['breakdays'] ? 'aid_r2breaks' : 'aid_r2');
        $steps = [[get_string('aid_r1', $p, $paidtext), $money($w['paid'])]];
        if ($w['couldhave'] > 0) {
            $steps[] = [get_string('aid_r1could', $p), $money($w['couldhave'])];
        }
        $steps[] = [get_string($steptwo, $p, $count), format_float(
            $w['pct'],
            1
        ) . '%' . ($w['earnedpct'] >= 100 && $w['pct'] < 100 ? ' (' . get_string('aid_over60', $p) . ')' : '')];
        $steps[] = [get_string('aid_r3', $p, format_float($w['earnedpct'], 1)), $money($w['earned'])];
        if ($w['pwd'] > 0) {
            $steps[] = [get_string('aid_r4pwd', $p), html_writer::tag('strong', $money($w['pwd']))];
        } else {
            $steps[] = [get_string('aid_r4', $p), $money($w['unearned'])];
            $steps[] = [get_string('aid_r5', $p), $money($w['charges'])];
            $steps[] = [get_string('aid_r6', $p, format_float(100 - $w['earnedpct'], 1)), html_writer::tag(
                'strong',
                $money($w['schoolshare'])
            )];
            $steps[] = [get_string('aid_r7', $p), $money($w['studentshare'])];
            if ($w['studentshare'] > 0) {
                $steps[] = [get_string('aid_r7grants', $p), $money($w['grantowed'])];
            }
        }
        $items = '';
        foreach ($steps as [$label, $value]) {
            $items .= html_writer::tag('li', html_writer::span($label) . html_writer::span($value));
        }
        $left = html_writer::tag('ul', $items, ['class' => 'sr-costlines']);
        if ($w['pwd'] > 0) {
            $right = html_writer::tag('h2', get_string('aid_pwdtitle', $p, $money($w['pwd'])), ['class' => 'sr-card-title h3']) .
                html_writer::tag('p', get_string('aid_pwdnote', $p), ['class' => 'small mb-0']);
        } else {
            $right = html_writer::tag(
                'h2',
                get_string('aid_schoolreturns', $p, $money($w['schoolshare'])),
                ['class' => 'sr-card-title h3']
            );
            $ritems = '';
            foreach ($w['returns'] as $f => $v) {
                $ritems .= html_writer::tag('li', html_writer::span($fund($f)) . html_writer::tag('strong', $money($v)));
            }
            $right .= html_writer::tag('ul', $ritems, ['class' => 'sr-costlines border-0 pt-0']);
            if ($w['studentshare'] > 0) {
                $right .= html_writer::tag('p', $w['grantowed'] > 0 ?
                    get_string('aid_studentsharenote', $p, (object)['share' => $money($w['studentshare']),
                        'grants' => $money($w['grantowed'])]) :
                    get_string('aid_studentsharenone', $p, $money($w['studentshare'])), ['class' => 'small']);
            }
        }
        if ($manageaid && $w['schoolshare'] > 0 && !$w['needshours']) {
            $send = $hidden('sesskey', sesskey()) . $hidden('sendreturn', 1) . $hidden('report', 'returns') .
                $hidden('termid', $termid) . $hidden('id', $sid) . $hidden('expected', billing::cents($w['schoolshare']));
            foreach (array_filter($typed, 'strlen') as $typedname => $value) {
                $send .= $hidden($typedname, $value);
            }
            $right .= html_writer::tag(
                'form',
                $send . html_writer::tag('button', get_string('aid_approvesend', $p), ['type' => 'submit',
                    'class' => 'btn btn-primary w-100']),
                ['method' => 'post', 'action' => $url->out_omit_querystring()]
            );
            $right .= html_writer::tag('p', get_string('aid_approvesendnote', $p), ['class' => 'sr-muted small mt-2 mb-0']);
        }
        echo html_writer::div(html_writer::div($left, 'sr-col-main') . html_writer::div(
            html_writer::div($right, 'sr-card'),
            'sr-col-side'
        ), 'sr-columns');
        echo $OUTPUT->footer();
        return;
    }
    echo staffarea::header();
    echo $OUTPUT->tabtree($tabs(), $report);
    echo html_writer::tag('p', get_string('aidreport_returns_intro', $p));
    echo $termselect();
    if ($termid) {
        $t = new html_table();
        $t->attributes['class'] = 'sr-table';
        $t->head = [get_string('student', $p), get_string('withdrawdate', $p), get_string('aidpercent', $p),
            get_string('aiddisbursed', $p), ''];
        foreach (aid::withdrawals($termid) as $r) {
            $t->data[] = [s($r['student']), s($r['withdrawn']), $r['percent'] === null ? '' : format_float($r['percent'], 1) . '%',
                $money($r['disbursed']), html_writer::link(
                    new moodle_url($url, ['termid' => $termid, 'id' => $r['sid']]),
                    get_string('aid_openhelper', $p)
                )];
        }
        echo $t->data ? html_writer::table($t) : $shell::empty_state(get_string('nothingtoreport', $p));
    }
    echo $OUTPUT->footer();
    return;
}
if ($report === 'verification') {
    echo staffarea::header();
    echo $OUTPUT->tabtree($tabs(), $report);
    echo html_writer::tag('p', get_string('aidreport_verification_intro', $p, $awardyear));
    $t = new html_table();
    $t->attributes['class'] = 'sr-table';
    $t->head = [get_string('student', $p), get_string('studentid', $p), get_string('aid_verification', $p),
        get_string('aid_counseling', $p)];
    foreach (
        $DB->get_records_select(
            aidhelp::YEARS,
            "awardyear = ? AND (verification = 'selected' OR counseling = 0)",
            [$awardyear]
        ) as $y
    ) {
        $s2 = \local_studentrecords\local\students::get((int)$y->studentid);
        $t->data[] = [html_writer::link(
            new moodle_url($url, ['report' => 'students', 'id' => $s2->id]),
            s(\local_studentrecords\local\students::display_name($s2))
        ), s($s2->studentid),
            $shell::tag(get_string('aid_verif_' . $y->verification, $p), $y->verification === 'selected' ? 'warn' : 'neutral'),
            $shell::tag(get_string($y->counseling ? 'aid_counsel_done' : 'aid_counsel_notdone', $p), $y->counseling ? 'good' :
            'warn')];
    }
    echo $t->data ? html_writer::table($t) : $shell::empty_state(get_string('aid_noverif', $p));
    echo $OUTPUT->footer();
    return;
}
[$fy, $fm, $fd] = array_map('intval', explode('-', $from));
[$ty, $tm, $td] = array_map('intval', explode('-', $to));
$fromt = make_timestamp($fy, $fm, $fd, 0, 0, 0, catalog::timezone());
$tot = make_timestamp($ty, $tm, $td + 1, 0, 0, 0, catalog::timezone());

if ($report === 'recipients') {
    $head = [get_string('student', $p), get_string('studentid', $p), get_string('fund', $p), get_string('status', $p),
        get_string('amountusd', $p), get_string('aiddisbursed', $p)];
    $rows = array_map(fn($r) => [$r['student'], $r['studentno'], $fund($r['fund']), get_string('aidstatus_' . $r['status'], $p),
        $money($r['amount']), $money($r['disbursed'])], aid::recipients($awardyear));
} else if ($report === 'disbursements') {
    $head = [get_string('date'), get_string('student', $p), get_string('studentid', $p), get_string('fund', $p),
        get_string('amountusd', $p)];
    $rows = array_map(
        fn($r) => [$r['date'], $r['student'], $r['studentno'], $fund($r['fund']), $money($r['amount'])],
        aid::disbursements($fromt, $tot)
    );
} else if ($report === 'enrollment') {
    $head = [get_string('student', $p), get_string('studentid', $p), get_string('nsc_status', $p), get_string('nsc_hours', $p)];
    $rows = $termid ? array_map(
        fn($r) => [$r['student'], $r['studentno'], $r['status'], format_float($r['hours'], -1)],
        aid::enrollment($termid)
    ) : [];
} else if ($report === 'withdrawals') {
    $head = [get_string('student', $p), get_string('studentid', $p), get_string('withdrawdate', $p), get_string('lastattended', $p),
        get_string('nsc_termstart', $p), get_string('nsc_termend', $p), get_string('aidpercent', $p), get_string('aidover60', $p),
        get_string('aiddisbursed', $p)];
    $rows = $termid ? array_map(fn($r) => [$r['student'], $r['studentno'], $r['withdrawn'], $r['lastattended'], $r['start'],
        $r['end'], $r['percent'] === null ? '' : format_float($r['percent'], 1) . '%', $r['over60'] ? get_string('yes') :
        get_string('no'), $money($r['disbursed'])], aid::withdrawals($termid)) : [];
} else {
    $w = aid::ninety_ten_worksheet($fromt, $tot);
    $head = [get_string('item', $p), get_string('amountusd', $p)];
    $rows = [[get_string('aid9010_federal', $p), $money($w['federal'])], [get_string('aid9010_other', $p), $money($w['other'])],
        [get_string('aid9010_all', $p), $money($w['all'])], [get_string('aid9010_percent', $p), $w['percent'] === null ? '-' :
        format_float($w['percent'], 2) . '%']];
}

if ($download !== '') {
    audit::log('export', 'aid', null, null, $report . ' ' . $download . ' ' . count($rows));
    \local_studentrecords\local\reports::download('studentrecords_aid_' . $report . '_' . date('Ymd'), $download, $head, $rows);
    die();
}

echo staffarea::header();
echo $OUTPUT->tabtree($tabs(), $report);
echo html_writer::tag('p', get_string('aidreport_' . $report . '_intro', $p));
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'row g-2 mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'report', 'value' => $report]);
$input = fn($name, $value, $type) => html_writer::div(html_writer::label(
    get_string('aidfilter_' . $name, $p),
    'id_' . $name,
    true,
    ['class' => 'me-1']
) . html_writer::empty_tag('input', ['type' => $type, 'name' => $name, 'id' => 'id_' . $name,
    'value' => $value, 'class' => 'form-control d-inline-block w-auto']), 'col-auto');
if ($report === 'recipients') {
    echo $input('awardyear', $awardyear, 'text');
} else if ($report === 'disbursements' || $report === 'ninetyten') {
    echo $input('from', $from, 'date') . $input('to', $to, 'date');
} else {
    $options = html_writer::tag('option', get_string('choosedots'), ['value' => 0]);
    foreach (catalog::terms() as $tid => $name) {
        $selected = $tid == $termid ? ['selected' => 1] : [];
        $options .= html_writer::tag('option', format_string($name), ['value' => $tid] + $selected);
    }
    echo html_writer::div(html_writer::tag('select', $options, ['name' => 'termid', 'class' => 'form-select',
        'aria-label' => get_string('term', $p)]), 'col-auto');
}
echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('show'),
    'class' => 'btn btn-secondary']), 'col-auto');
echo html_writer::end_tag('form');
$table = new html_table();
$table->head = $head;
$table->data = array_map(fn($row) => array_map(fn($v) => s((string)$v), $row), $rows);
echo $rows ? html_writer::table($table) : $OUTPUT->notification(get_string('nothingtoreport', $p), 'info');
echo $OUTPUT->download_dataformat_selector(get_string('download'), $url->out_omit_querystring(), 'download', $params);
echo $OUTPUT->footer();
