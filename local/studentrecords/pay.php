<?php

/**
 * A student pays (part of) their balance online. Step 1: the amount. Step 2: Moodle's payment window, with the
 * payment providers the school turned on (PayPal, Stripe ...). The payment is put on the account when the payment
 * provider confirms it.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\payments;
use local_studentrecords\local\students;

require_login(null, false);
$id = required_param('id', PARAM_INT);
$req = optional_param('req', 0, PARAM_INT);
$p = 'local_studentrecords';
$syscontext = context_system::instance();
$student = students::get($id);
if ((int)$student->userid !== (int)$USER->id || !has_capability('local/studentrecords:viewown', $syscontext)) {
    throw new moodle_exception('err_payownonly', $p);
}
$url = new moodle_url('/local/studentrecords/pay.php', ['id' => $id]);
$back = new moodle_url('/local/studentrecords/account.php', ['id' => $id]);
$PAGE->set_context($syscontext);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('payonline', $p));
$PAGE->set_heading(get_string('pluginname', $p));
if (!payments::online_ready()) {
    redirect($back, get_string('err_noonline', $p), null, \core\output\notification::NOTIFY_ERROR);
}
$balance = billing::balance($id);

if ($req) {
    $request = $DB->get_record(payments::REQ, ['id' => $req, 'studentid' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
    if ($request->status !== 'open' || !payments::amount_allowed((float)$request->amount, $balance)) {
        redirect($back, get_string('err_paidalready', $p), null, \core\output\notification::NOTIFY_INFO);
    }
    $PAGE->requires->js_call_amd('core_payment/gateways_modal', 'init');
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('payonline', $p));
    echo html_writer::tag('p', get_string('payconfirm', $p, billing::money((float)$request->amount)));
    $attributes = \core_payment\helper::gateways_modal_link_params(
        'local_studentrecords',
        payments::AREA,
        (int)$request->id,
        get_string('paydescription', $p, (object)['school' => \local_studentrecords\local\catalog::setting('schoolname'),
        'studentid' => $student->studentid])
    );
    $attributes['class'] = 'btn btn-primary me-2';
    $attributes['type'] = 'button';
    echo html_writer::tag('button', get_string('paynow', $p, billing::money((float)$request->amount)), $attributes);
    echo html_writer::link($back, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::tag('p', get_string('payprovidernote', $p), ['class' => 'small text-muted mt-3']);
    echo $OUTPUT->footer();
    return;
}

$form = new \local_studentrecords\form\payamount($url, ['balance' => $balance]);
$form->set_data(['id' => $id, 'amount' => $balance > 0 ? format_float($balance, 2, false) : '']);
if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    $reqid = payments::create_request($student, billing::parse_amount((string)$data->amount));
    redirect(new moodle_url($url, ['req' => $reqid]));
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('payonline', $p));
if ($balance <= 0.004) {
    echo $OUTPUT->notification(get_string('nothingtopay', $p), 'info');
    echo html_writer::link($back, get_string('back'));
} else {
    $form->display();
}
echo $OUTPUT->footer();
