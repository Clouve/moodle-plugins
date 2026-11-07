<?php

/**
 * A student account: every charge, credit and payment with the running balance. Finance staff add charges and
 * credits and reverse lines (nothing is changed or removed; an aid line also needs the manage aid permission); the student
 * sees their own account.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\aid;
use local_studentrecords\local\audit;
use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\payments;
use local_studentrecords\local\plans;
use local_studentrecords\local\refunds;
use local_studentrecords\local\shell;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$id = optional_param('id', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$item = optional_param('item', 0, PARAM_INT);
$p = 'local_studentrecords';
$syscontext = context_system::instance();

require_login(null, false);
if ($userid && !$id) {
    $rec = students::get_by_user($userid);
    if (!$rec) {
        throw new moodle_exception('norecord', $p);
    }
    $id = (int)$rec->id;
}
$student = students::get($id);
if (!billing::can_view($student)) {
    throw new required_capability_exception($syscontext, 'local/studentrecords:viewbilling', 'nopermissions', '');
}
$staff = has_capability('local/studentrecords:viewbilling', $syscontext);
$manage = has_capability('local/studentrecords:managebilling', $syscontext);
$manageaid = has_capability('local/studentrecords:manageaid', $syscontext);
$url = new moodle_url('/local/studentrecords/account.php', ['id' => $id]);
$back = $url;
if ($staff) {
    staffarea::start_page($url, 'billing');
    $PAGE->navbar->add(get_string('billing', $p), new moodle_url('/local/studentrecords/billing.php'));
    $PAGE->navbar->add(students::display_name($student), $url);
} else {
    $PAGE->set_context($syscontext);
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('standard');
    $PAGE->set_heading(get_string('nav_myaccount', $p));
    $PAGE->navbar->ignore_active();
    $PAGE->navbar->add(get_string('pluginname', $p), new moodle_url('/local/studentrecords/index.php'));
    $PAGE->navbar->add(get_string('nav_myaccount', $p), $url);
}
$PAGE->set_title(get_string('studentaccount', $p));
$header = fn() => $staff ? staffarea::header() : $OUTPUT->header();
$userid = (int)$student->userid;

if (
    in_array(
        $action,
        ['charge', 'credit', 'reverse', 'payment', 'access', 'plan', 'cancelplan', 'payout'],
        true
    )
) {
    require_capability('local/studentrecords:managebilling', $syscontext);
    if ($action === 'plan' && plans::has_active($id)) {
        // Say so before staff fill in a form that can't be saved. The account page shows the plan and how to cancel it.
        redirect($back, get_string('err_planexists', $p), null, \core\output\notification::NOTIFY_ERROR);
    }
    $formurl = new moodle_url($url, ['action' => $action, 'item' => $item]);
    if ($action === 'reverse') {
        $line = $DB->get_record(billing::TXN, ['id' => $item, 'studentid' => $id], '*', MUST_EXIST);
        if (billing::is_aid_line($line)) {
            require_capability('local/studentrecords:manageaid', $syscontext);
        }
        $form = new \local_studentrecords\form\reverse($formurl, ['text' => s($line->description) . ' ' .
            billing::money((float)$line->amount)]);
        $form->set_data(['id' => $id, 'action' => $action, 'item' => $item]);
    } else if ($action === 'plan') {
        $templates = array_map(fn($t) => format_string($t->name), plans::templates_for($student));
        $form = new \local_studentrecords\form\plan($formurl, ['mode' => 'student', 'templates' => $templates]);
        $form->set_data(['id' => $id, 'action' => $action, 'planid' => $templates ? array_key_first($templates) : 0]);
    } else if ($action === 'cancelplan') {
        $plan = $DB->get_record(plans::STUPLANS, ['id' => $item, 'studentid' => $id, 'status' => 'active'], '*', MUST_EXIST);
        $form = new \local_studentrecords\form\cancelplan($formurl, ['text' => s($plan->name)]);
        $form->set_data(['id' => $id, 'action' => $action, 'item' => $item]);
    } else if ($action === 'payout') {
        $form = new \local_studentrecords\form\payout($formurl, ['owed' => max(0, -billing::balance($id))]);
        $form->set_data(['id' => $id, 'action' => $action, 'txndate' => time()]);
    } else if ($action === 'payment') {
        $form = new \local_studentrecords\form\payment($formurl);
        $form->set_data(['id' => $id, 'action' => $action, 'txndate' => time()]);
    } else if ($action === 'access') {
        $pending = payments::pending($id)[$item] ?? null;
        if (!$pending) {
            redirect($back);
        }
        $form = new \local_studentrecords\form\grantaccess($formurl, ['text' => s($pending->code . ' ' . $pending->section) . ', ' .
            format_string($pending->termname)]);
        $form->set_data(['id' => $id, 'action' => $action, 'item' => $item]);
    } else {
        $form = new \local_studentrecords\form\txn($formurl, ['mode' => $action]);
        $form->set_data(['id' => $id, 'action' => $action, 'txndate' => time()]);
    }
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        try {
            if ($action === 'reverse') {
                billing::reverse($item, (string)$data->reason);
            } else if ($action === 'payment') {
                payments::record($student, billing::parse_amount((string)$data->amount), $data->method, [
                    'txndate' => (int)$data->txndate, 'reference' => trim((string)$data->reference) ?: null,
                    'note' => trim((string)$data->note) ?: null, 'termid' => $data->termid ?: null]);
            } else if ($action === 'access') {
                payments::grant($item, trim((string)$data->reason));
            } else if ($action === 'payout') {
                refunds::payout($student, billing::parse_amount((string)$data->amount), $data->method, [
                    'txndate' => (int)$data->txndate, 'reference' => trim((string)$data->reference) ?: null,
                    'note' => trim((string)$data->note) ?: null]);
            } else if ($action === 'cancelplan') {
                plans::cancel($item, trim((string)$data->reason));
            } else if ($action === 'plan') {
                $template = $data->planid ? $DB->get_record(plans::PLANS, ['id' => $data->planid], '*', MUST_EXIST) : null;
                $terms = $template ? (array)$template : (array)$data;
                foreach (['downvalue', 'planfee', 'latefee'] as $f) {
                    $terms[$f] = $template ? (float)$template->$f : billing::parse_amount((string)$data->$f);
                }
                $planid = plans::create(
                    $student,
                    (int)$data->termid,
                    $template ? (int)$template->id : null,
                    $terms,
                    trim((string)$data->note)
                );
                if (!empty($data->sendagreement)) {
                    // The plan agreement: a summary message with the first due date and a link to the full schedule.
                    plans::send_agreement($student, $planid);
                }
            } else if ($action === 'charge') {
                $fee = $data->feeid ? $DB->get_record(billing::FEES, ['id' => $data->feeid], '*', MUST_EXIST) : null;
                $amount = trim((string)$data->amount) === '' ? (float)$fee->amount : billing::parse_amount((string)$data->amount);
                billing::add_line(['studentid' => $id, 'txntype' => 'charge', 'amount' => $amount,
                    'description' => trim((string)$data->description) !== '' ? trim($data->description) : $fee->name,
                    'termid' => $data->termid ?: null, 'feeid' => $fee->id ?? null, 'txndate' => (int)$data->txndate,
                    'qualified' => $fee ? (int)$fee->qualified : (int)$data->qualified,
                    'refundable' => $fee ? (int)$fee->refundable : (int)$data->refundable, 'note' => trim($data->note)]);
            } else {
                billing::add_line(['studentid' => $id, 'txntype' => 'credit',
                    'amount' => -billing::parse_amount((string)$data->amount), 'method' => $data->method,
                    'description' => trim((string)$data->description) !== '' ? trim($data->description) :
                        get_string('creditkind_' . $data->method, $p),
                    'termid' => $data->termid ?: null, 'txndate' => (int)$data->txndate, 'note' => trim($data->note)]);
            }
            redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($back, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
    echo $header();
    echo $OUTPUT->heading(s(students::display_name($student)) . ': ' . get_string(['charge' => 'addcharge',
        'credit' => 'addcredit', 'reverse' => 'reverse', 'payment' => 'recordpayment', 'access' => 'giveaccess',
        'plan' => 'giveplan', 'cancelplan' => 'cancelplan', 'payout' => 'recordpayout'][$action], $p));
    $form->display();
    if ($action === 'plan') {
        // Schedule preview of each plan template for the current term (amounts for staff only).
        $current = \local_studentrecords\local\cards::current_term(time());
        foreach ($current ? plans::templates_for($student) : [] as $t) {
            try {
                $pv = plans::preview($student, (int)$current->id, (array)$t);
            } catch (moodle_exception $e) {
                continue;
            }
            $pt = new html_table();
            $pt->attributes['class'] = 'sr-table';
            $pt->caption = get_string('plan_preview', $p, (object)['plan' => format_string($t->name),
                'term' => format_string($current->name), 'total' => billing::money($pv['total'])]);
            $pt->head = [get_string('duedate', $p), get_string('planpayment', $p), get_string('amountusd', $p)];
            foreach ($pv['rows'] as $r) {
                $pt->data[] = [catalog::show_date(catalog::date_to_time($r['due'])), $r['seq'] ?
                    get_string('planpaymentno', $p, $r['seq']) : get_string('downpayment', $p), billing::money($r['amount'])];
            }
            echo html_writer::div(html_writer::table($pt), 'sr-card mt-3');
        }
    }
    echo $OUTPUT->footer();
    return;
}

audit::log('view', 'account', (int)$student->id, $userid);
$termid = optional_param('termid', 0, PARAM_INT);
$terms = catalog::terms();
$balance = billing::balance($id);
echo $header();
if ($staff) {
    $planned = (bool)array_filter(plans::for_student($id), fn($pl) => $pl->status === 'active');
    echo html_writer::tag('h2', get_string('acct_staffheading', $p, s(students::display_name($student))), [
        'class' => 'sr-file-name']);
    echo html_writer::tag(
        'p',
        get_string('studentid', $p) . ' ' . s($student->studentid) . ' · ' . get_string('acct_balance', $p) .
        ' ' . html_writer::tag('strong', billing::money($balance)) . ($planned ? ' · ' . get_string('acct_onplan', $p) : ''),
        ['class' => 'sr-muted']
    );
}
$buttons = [];
if ($manage) {
    $actions = ['payment' => 'recordpayment', 'charge' => 'addcharge', 'credit' => 'addcredit', 'plan' => 'giveplan'];
    if ($balance < -0.004) {
        $actions['payout'] = 'recordpayout';
    }
    foreach ($actions as $a => $label) {
        $buttons[] = html_writer::link(
            new moodle_url($url, ['action' => $a]),
            get_string($label, $p),
            ['class' => 'btn ' . ($a === 'payment' ? 'btn-primary' : 'btn-outline-secondary') . ' me-2']
        );
    }
}
$buttons[] = html_writer::link(new moodle_url('/local/studentrecords/statement.php', array_filter(['id' => $id,
    'termid' => $termid])), get_string('statementpdf', $p), ['class' => 'btn btn-outline-secondary me-2']);
echo html_writer::div(implode('', $buttons), 'sr-actions');
if (!billing::enabled()) {
    echo $OUTPUT->notification(get_string('billingoff', $p), 'info');
}
if (optional_param('paid', 0, PARAM_BOOL)) {
    echo $OUTPUT->notification(get_string('paythanks', $p), 'success');
}
// Tiles: balance, next plan payment, paid this term.
$next = plans::next_due($id);
$current = \local_studentrecords\local\cards::current_term(time());
$paidterm = $current ? -(float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0) FROM {" . billing::TXN . "}
    WHERE studentid = ? AND txntype = 'payment' AND termid = ?", [$id, $current->id]) : 0.0;
$tiles = [
    shell::tile(get_string($balance < -0.004 ? 'acct_credit' : 'acct_balance', $p), billing::money(abs($balance))),
    $next ? shell::tile(
        get_string('home_nextpayment', $p),
        billing::money((float)$next->amount - (float)$next->paid),
        get_string('acct_due', $p, catalog::show_date((int)$next->duedate))
    ) :
        shell::tile(get_string('home_nextpayment', $p), get_string('home_none', $p), get_string('home_noplan', $p)),
];
if ($current) {
    $tiles[] = shell::tile(get_string('acct_paidterm', $p, $current->name), billing::money($paidterm));
}
$tilehtml = shell::tiles($tiles);
$payhtml = '';
if ((int)$student->userid === (int)$USER->id && $balance > 0.004 && payments::online_ready()) {
    $payhtml = html_writer::tag('h2', get_string('payonline', $p), ['class' => 'sr-card-title']) .
        html_writer::tag('p', get_string('acct_payintro', $p, (object)['balance' => billing::money($balance),
        'next' => $next ? billing::money((float)$next->amount - (float)$next->paid) : '-'])) .
        html_writer::link(
            new moodle_url('/local/studentrecords/pay.php', ['id' => $id]),
            get_string('payonline', $p),
            ['class' => 'btn btn-primary w-100']
        );
}
echo $payhtml !== '' ? html_writer::div(html_writer::div($tilehtml, 'sr-col-main') .
    html_writer::div(html_writer::div($payhtml, 'sr-card'), 'sr-col-side'), 'sr-columns') : $tilehtml;
$payinfo = catalog::setting('payinfo');
if ($balance > 0.004 && $payinfo !== '') {
    echo html_writer::div(nl2br(s($payinfo)), 'alert alert-secondary');
}
$pending = payments::pending($id);
if ($pending) {
    $items = [];
    foreach ($pending as $r) {
        $text = s($r->code . ' ' . $r->section) . ', ' . format_string($r->termname);
        if ($manage) {
            $text .= ' ' . html_writer::link(
                new moodle_url($url, ['action' => 'access', 'item' => $r->id]),
                get_string('giveaccess', $p)
            );
        }
        $items[] = $text;
    }
    echo $OUTPUT->notification(get_string('accesswaiting', $p, get_string('access_' . payments::access_rule(), $p)) .
        html_writer::alist($items), 'warning');
}

$select = new single_select(
    $url,
    'termid',
    [0 => get_string('allterms', $p)] + array_map('format_string', $terms),
    $termid,
    null
);
echo html_writer::tag('h2', get_string('acct_lines', $p), ['class' => 'sr-h2']);
$select->set_label(get_string('term', $p));
echo html_writer::div($OUTPUT->render($select), 'mb-2');

$table = new html_table();
$table->head = [get_string('date'), get_string('description'), get_string('term', $p), get_string('linetype', $p),
    get_string('receiptno', $p), get_string('amountusd', $p), get_string('runningbalance', $p)];
if ($staff) {
    $table->head[] = get_string('addedby', $p);
    $table->head[] = '';
}
$table->colclasses = [5 => 'text-end', 6 => 'text-end'];
foreach (billing::lines($id, $termid) as $l) {
    $type = get_string('linetype_' . $l->txntype, $p) . ($l->method && $l->txntype === 'credit' ?
        ' (' . get_string('creditkind_' . $l->method, $p) . ')' : '');
    $desc = s($l->description) . ($l->reversedby ? ' ' . html_writer::span(get_string('reversed', $p), 'badge bg-secondary') : '');
    if ($staff && $l->note) {
        $desc .= html_writer::div(s($l->note), 'small text-muted');
    }
    $receipt = $l->receipt ? html_writer::link(
        new moodle_url('/local/studentrecords/receipt.php', ['id' => $l->id]),
        s($l->receipt)
    ) : '';
    if ($l->txntype === 'payment' && $l->method) {
        $type .= ' (' . get_string('paymethod_' . $l->method, $p) . ')';
    }
    $row = [catalog::show_date((int)$l->txndate), $desc, $l->termid ? format_string($terms[$l->termid] ?? '') : '',
        $type, $receipt, billing::money((float)$l->amount), billing::money((float)$l->running)];
    if ($staff) {
        $row[] = $l->userid ? s(fullname(core_user::get_user($l->userid))) : '';
        $reversible = $manage && !$l->reversedby && !$l->reverses && ($manageaid || !billing::is_aid_line($l));
        $reverseurl = new moodle_url($url, ['action' => 'reverse', 'item' => $l->id]);
        $row[] = $reversible ? html_writer::link($reverseurl, get_string('reverse', $p)) : '';
    }
    $table->data[] = $row;
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('nolines', $p));

if (aid::enabled()) {
    // Financial aid is worked on by the Financial Aid role on the Federal aid page; here it is shown only.
    echo $OUTPUT->heading(get_string('financialaid', $p), 3);
    $atable = new html_table();
    $atable->head = [get_string('awardyear', $p), get_string('term', $p), get_string('fund', $p), get_string('status', $p),
        get_string('amountusd', $p), get_string('aiddisbursed', $p)];
    $atable->colclasses = [4 => 'text-end', 5 => 'text-end'];
    foreach (aid::awards($id) as $a) {
        $atable->data[] = [s($a->awardyear), $a->termid ? format_string($terms[$a->termid] ?? '') : '',
            get_string('fund_' . $a->fund, $p), get_string('aidstatus_' . $a->status, $p), billing::money((float)$a->amount),
            billing::money((float)$a->disbursed)];
    }
    echo $atable->data ? html_writer::table($atable) : html_writer::tag('p', get_string('noaid', $p));
    if (has_capability('local/studentrecords:viewaid', $syscontext)) {
        echo html_writer::div(html_writer::link(
            new moodle_url('/local/studentrecords/aid.php', ['report' => 'students', 'id' => $id]),
            get_string('openinaid', $p)
        ), 'mb-3');
    }
}

$studentrefunds = refunds::listing('all', $id);
if ($studentrefunds) {
    echo $OUTPUT->heading(get_string('refunds', $p), 3);
    $rtable = new html_table();
    $rtable->head = [get_string('date'), get_string('hist_course', $p), get_string('refundreason', $p),
        get_string('refundrule', $p), get_string('amountusd', $p), get_string('status', $p)];
    $rtable->colclasses = [4 => 'text-end'];
    foreach ($studentrefunds as $r) {
        $state = get_string('refundstatus_' . $r->status, $p);
        if ($staff) {
            $state = html_writer::link(new moodle_url('/local/studentrecords/refunds.php', ['id' => $r->id]), $state);
        }
        $rtable->data[] = [catalog::show_date((int)$r->eventdate), s((string)$r->code),
            get_string('refundreason_' . $r->reason, $p), $r->rulename !== null ? s($r->rulename) : get_string('norefundrule', $p),
            billing::money((float)$r->amount), $state];
    }
    echo html_writer::table($rtable);
}

$studentplans = plans::for_student($id);
if ($studentplans) {
    echo $OUTPUT->heading(get_string('paymentplans', $p), 3);
}
foreach ($studentplans as $plan) {
    // A completed plan with an unpaid payment had a payment reversed and could not reopen.
    $unpaid = array_filter($plan->insts, fn($inst) => $inst->status !== 'paid');
    $title = s($plan->name) . ' - ' . ($plan->termid ? format_string($terms[$plan->termid] ?? '') . ' - ' : '') .
        get_string('planstatus_' . ($plan->status === 'completed' && $unpaid ? 'reversed' : $plan->status), $p);
    if ($manage && $plan->status === 'active') {
        $title .= ' ' . html_writer::link(
            new moodle_url($url, ['action' => 'cancelplan', 'item' => $plan->id]),
            get_string('cancelplan', $p),
            ['class' => 'small']
        );
    }
    echo html_writer::tag('h3', $title, ['class' => 'sr-card-title mt-3']);
    $cells = '';
    $today = catalog::time_to_date(time());
    foreach ($plan->insts as $inst) {
        $days = (int)floor((catalog::date_to_time(catalog::time_to_date((int)$inst->duedate)) - catalog::date_to_time($today)) /
            DAYSECS);
        if ($inst->status === 'paid') {
            $tag = shell::tag(get_string('inststatus_paid', $p), 'good');
        } else if ($inst->status === 'late') {
            $tag = shell::tag(get_string('inststatus_late', $p), 'bad');
        } else if ($days >= 0 && $days <= 14) {
            $tag = shell::tag(get_string('acct_duein', $p, $days), 'warn');
        } else {
            $tag = shell::tag(get_string('acct_upcoming', $p), 'neutral');
        }
        $cells .= html_writer::tag('li', html_writer::div(catalog::show_date((int)$inst->duedate), 'sr-msg-meta') .
            html_writer::div(billing::money((float)$inst->amount), 'fw-bold') . $tag, ['class' => 'sr-plan-step']);
    }
    echo html_writer::tag('ol', $cells, ['class' => 'sr-plan', 'aria-label' => $plan->name]);
    if (!$staff) {
        continue;
    }
    $ptable = new html_table();
    $ptable->caption = get_string('acct_plandetail', $p);
    $ptable->head = [get_string('planpayment', $p), get_string('duedate', $p), get_string('amountusd', $p),
        get_string('planpaid', $p), get_string('status', $p)];
    $ptable->colclasses = [2 => 'text-end', 3 => 'text-end'];
    foreach ($plan->insts as $inst) {
        $state = get_string('inststatus_' . $inst->status, $p);
        if ($inst->status === 'late') {
            $state = html_writer::span($state, 'badge bg-danger');
        }
        $ptable->data[] = [$inst->seq ? get_string('planpaymentno', $p, (int)$inst->seq) : get_string('downpayment', $p),
            catalog::show_date((int)$inst->duedate), billing::money((float)$inst->amount), billing::money((float)$inst->paid),
            $state];
    }
    echo html_writer::table($ptable);
    if ($staff && $plan->note) {
        echo html_writer::div(nl2br(s($plan->note)), 'small text-muted mb-3');
    }
}
if ($termid) {
    echo html_writer::tag('p', get_string('termfilternote', $p), ['class' => 'small text-muted']);
}
echo $OUTPUT->footer();
