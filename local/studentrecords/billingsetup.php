<?php

/**
 * Billing setup: billing on or off, the price list (tuition and fees), text on statements, and the Finance role.
 * Price list items are switched off, never removed; a changed price applies to new charges only.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\records;
use local_studentrecords\local\staffarea;

require_login(null, false);
$feeid = optional_param('fee', -1, PARAM_INT);
$planid = optional_param('plan', -1, PARAM_INT);
$ruleid = optional_param('rule', -1, PARAM_INT);
$createrole = optional_param('createrole', 0, PARAM_BOOL);
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/billingsetup.php');
staffarea::setup('billingsetup', $url);
$syscontext = context_system::instance();

if ($createrole) {
    require_sesskey();
    require_capability('moodle/role:manage', $syscontext);
    billing::create_finance_role();
    redirect($url, get_string('financerole_created', $p), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($feeid >= 0) {
    $fee = $feeid ? $DB->get_record(billing::FEES, ['id' => $feeid], '*', MUST_EXIST) : null;
    $form = new \local_studentrecords\form\fee(new moodle_url($url, ['fee' => $feeid]), ['edit' => (bool)$fee]);
    if ($fee) {
        $current = (array)$fee;
        $current['amount'] = format_float((float)$fee->amount, 2, false);
        $current['levelid'] = $fee->scope === 'level' ? (int)$fee->scopeid : 0;
        $current['programid'] = $fee->scope === 'program' ? (int)$fee->scopeid : 0;
        unset($current['id']);
        $form->set_data($current + ['fee' => $feeid]);
    } else {
        $form->set_data(['fee' => 0]);
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $record = (array)$data;
        $record['amount'] = billing::parse_amount((string)$data->amount);
        $record['scopeid'] = $data->scope === 'level' ? (int)$data->levelid : ($data->scope === 'program' ?
            (int)$data->programid : null);
        $record['id'] = $feeid ?: null;
        billing::save_fee($record);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->heading(get_string($fee ? 'editfee' : 'addfee', $p));
    $form->display();
    echo $OUTPUT->footer();
    return;
}

if ($ruleid >= 0) {
    $rule = $ruleid ? $DB->get_record(\local_studentrecords\local\refunds::RULES, ['id' => $ruleid], '*', MUST_EXIST) : null;
    $form = new \local_studentrecords\form\refrule(new moodle_url($url, ['rule' => $ruleid]), ['edit' => (bool)$rule]);
    if ($rule) {
        $current = (array)$rule;
        foreach (['value', 'tuitionpct', 'feespct'] as $f) {
            $current[$f] = format_float((float)$rule->$f, -1, false);
        }
        unset($current['id']);
        $form->set_data($current + ['rule' => $ruleid]);
    } else {
        $form->set_data(['rule' => 0, 'sortorder' => count(\local_studentrecords\local\refunds::rules()) + 1]);
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $record = (array)$data;
        $record['value'] = (float)$data->value;
        $record['tuitionpct'] = (float)$data->tuitionpct;
        $record['feespct'] = (float)$data->feespct;
        $record['id'] = $ruleid ?: null;
        \local_studentrecords\local\refunds::save_rule($record);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->heading(get_string($rule ? 'editrefundrule' : 'addrefundrule', $p));
    $form->display();
    echo $OUTPUT->footer();
    return;
}

if ($planid >= 0) {
    $plan = $planid ? $DB->get_record(\local_studentrecords\local\plans::PLANS, ['id' => $planid], '*', MUST_EXIST) : null;
    $form = new \local_studentrecords\form\plan(new moodle_url($url, ['plan' => $planid]), ['mode' => 'template',
        'edit' => (bool)$plan]);
    if ($plan) {
        $current = (array)$plan;
        foreach (['downvalue', 'planfee', 'latefee'] as $f) {
            $current[$f] = format_float((float)$plan->$f, 2, false);
        }
        $current['levelid'] = $plan->scope === 'level' ? (int)$plan->scopeid : 0;
        $current['programid'] = $plan->scope === 'program' ? (int)$plan->scopeid : 0;
        unset($current['id']);
        $form->set_data($current + ['plan' => $planid]);
    } else {
        $form->set_data(['plan' => 0]);
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $record = (array)$data;
        foreach (['downvalue', 'planfee', 'latefee'] as $f) {
            $record[$f] = billing::parse_amount((string)$data->$f);
        }
        $record['scopeid'] = $data->scope === 'level' ? (int)$data->levelid : ($data->scope === 'program' ?
            (int)$data->programid : null);
        $record['duedates'] = trim((string)$data->duedates) ?: null;
        $record['id'] = $planid ?: null;
        \local_studentrecords\local\plans::save_template($record);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->heading(get_string($plan ? 'editplan' : 'addplan', $p));
    $form->display();
    echo $OUTPUT->footer();
    return;
}

$settings = new \local_studentrecords\form\billingsettings($url);
$settings->set_data(['billing' => billing::enabled() ? 1 : 0, 'payinfo' => catalog::setting('payinfo'),
    'statementnote' => catalog::setting('statementnote'), 'billaccess' => \local_studentrecords\local\payments::access_rule(),
    'paymentaccount' => (int)catalog::setting('paymentaccount'),
    'reminderdays' => catalog::setting('reminderdays') !== '' ? catalog::setting('reminderdays') : 7,
    'refunddays' => \local_studentrecords\local\refunds::refund_days(),
    'halftime' => catalog::setting('halftime') !== '' ? catalog::setting('halftime') : 6,
    'gradlevels' => array_filter(explode(',', catalog::setting('gradlevels')))]);
if ($data = $settings->get_data()) {
    records::set_setting('billing', empty($data->billing) ? '0' : '1');
    records::set_setting('payinfo', trim((string)$data->payinfo));
    records::set_setting('statementnote', trim((string)$data->statementnote));
    records::set_setting('billaccess', (string)$data->billaccess);
    records::set_setting('paymentaccount', (string)(int)$data->paymentaccount);
    records::set_setting('reminderdays', (string)max(0, (int)$data->reminderdays));
    records::set_setting('refunddays', (string)max(0, (int)$data->refunddays));
    records::set_setting('halftime', (string)max(0, (float)$data->halftime));
    records::set_setting('gradlevels', implode(',', array_map('intval', (array)($data->gradlevels ?? []))));
    \local_studentrecords\local\audit::log('update', 'setup', null, null, 'billing settings');
    redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo staffarea::header();
echo html_writer::tag('p', get_string('billingsetup_intro', $p));
if (billing::enabled() && !\local_studentrecords\local\payments::online_ready()) {
    $accountslink = has_capability('moodle/payment:manageaccounts', $syscontext) ?
        ' ' . html_writer::link(new moodle_url('/payment/accounts.php'), get_string('paymentaccounts', 'payment')) : '';
    echo $OUTPUT->notification(get_string('noonlinepay', $p) . $accountslink, 'info');
}
$settings->display();

echo $OUTPUT->heading(get_string('pricelist', $p), 3);
$table = new html_table();
$table->head = [get_string('feename', $p), get_string('feekind', $p), get_string('feebasis', $p), get_string('amountusd', $p),
    get_string('feescope', $p), get_string('feerefundable', $p), get_string('feequalified_short', $p), get_string('active', $p),
    ''];
$table->colclasses = [3 => 'text-end'];
$yn = fn($v) => $v ? get_string('yes') : get_string('no');
foreach (billing::fees() as $f) {
    $table->data[] = [format_string($f->name) . ($f->note ? html_writer::div(s($f->note), 'small text-muted') : ''),
        get_string('feekind_' . $f->kind, $p), get_string('feebasis_' . $f->basis, $p), billing::money((float)$f->amount),
        billing::scope_text($f), $yn($f->refundable), $yn($f->qualified), $yn($f->active),
        html_writer::link(new moodle_url($url, ['fee' => $f->id]), get_string('edit'))];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('nofees', $p));
echo html_writer::div(html_writer::link(
    new moodle_url($url, ['fee' => 0]),
    get_string('addfee', $p),
    ['class' => 'btn btn-secondary']
), 'mb-4');

echo $OUTPUT->heading(get_string('paymentplans', $p), 3);
echo html_writer::tag('p', get_string('paymentplans_intro', $p));
$table = new html_table();
$table->head = [get_string('planname', $p), get_string('planwho', $p), get_string('downvalue', $p), get_string('numpay', $p),
    get_string('duemode', $p), get_string('planfee', $p), get_string('latefee', $p), get_string('gracedays', $p),
    get_string('active', $p), ''];
foreach (\local_studentrecords\local\plans::templates() as $t) {
    $down = $t->downtype === 'percent' ? format_float((float)$t->downvalue, -1) . '%' : billing::money((float)$t->downvalue);
    $due = $t->duemode === 'fixed' ? s((string)$t->duedates) : get_string('duesummary', $p, (object)[
        'frequency' => get_string('frequency_' . $t->frequency, $p), 'days' => (int)$t->firstdue]);
    $table->data[] = [format_string($t->name) . ($t->note ? html_writer::div(s($t->note), 'small text-muted') : ''),
        billing::scope_text($t), $down, (int)$t->numpay, $due, billing::money((float)$t->planfee),
        billing::money((float)$t->latefee), (int)$t->gracedays, $yn($t->active),
        html_writer::link(new moodle_url($url, ['plan' => $t->id]), get_string('edit'))];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('noplans', $p));
echo html_writer::div(html_writer::link(
    new moodle_url($url, ['plan' => 0]),
    get_string('addplan', $p),
    ['class' => 'btn btn-secondary']
), 'mb-4');

echo $OUTPUT->heading(get_string('refundrules', $p), 3);
echo html_writer::tag('p', get_string('refundrules_intro', $p));
$table = new html_table();
$table->head = [get_string('ruleorder', $p), get_string('rulename', $p), get_string('ruletype', $p), get_string('tuitionpct', $p),
    get_string('feespct', $p), get_string('allfees', $p), get_string('active', $p), ''];
foreach (\local_studentrecords\local\refunds::rules() as $r) {
    $table->data[] = [(int)$r->sortorder, format_string($r->name) . ($r->note ? html_writer::div(s($r->note), 'small text-muted') :
        ''), get_string('rulesummary_' . $r->ruletype, $p, format_float((float)$r->value, -1)),
        format_float((float)$r->tuitionpct, -1) . '%', format_float((float)$r->feespct, -1) . '%', $yn($r->allfees),
        $yn($r->active), html_writer::link(new moodle_url($url, ['rule' => $r->id]), get_string('edit'))];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('norefundrules', $p));
echo html_writer::div(html_writer::link(
    new moodle_url($url, ['rule' => 0]),
    get_string('addrefundrule', $p),
    ['class' => 'btn btn-secondary']
), 'mb-4');

echo $OUTPUT->heading(get_string('financerole', $p), 3);
$roleid = $DB->get_field('role', 'id', ['shortname' => billing::FINANCEROLE]);
if ($roleid) {
    echo html_writer::tag('p', get_string('financerole_exists', $p));
    if (has_capability('moodle/role:assign', $syscontext)) {
        echo html_writer::link(
            new moodle_url('/admin/roles/assign.php', ['contextid' => $syscontext->id, 'roleid' => $roleid]),
            get_string('financerole_assign', $p)
        );
    }
} else {
    echo html_writer::tag('p', get_string('financerole_intro', $p));
    if (has_capability('moodle/role:manage', $syscontext)) {
        echo html_writer::link(
            new moodle_url($url, ['createrole' => 1, 'sesskey' => sesskey()]),
            get_string('financerole_create', $p),
            ['class' => 'btn btn-secondary']
        );
    }
}
echo $OUTPUT->footer();
