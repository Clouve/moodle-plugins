<?php

/**
 * Receipt (PDF) for a payment, for Finance staff or the student; logged.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\students;

require_login(null, false);
$id = required_param('id', PARAM_INT);
$line = $DB->get_record(billing::TXN, ['id' => $id, 'txntype' => 'payment'], '*', MUST_EXIST);
if (!$line->receipt) {
    throw new moodle_exception('invalidrecord', 'error');
}
$student = students::get((int)$line->studentid);
if (!billing::can_view($student)) {
    throw new required_capability_exception(context_system::instance(), 'local/studentrecords:viewbilling', 'nopermissions', '');
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/studentrecords/receipt.php', ['id' => $id]));
\local_studentrecords\local\audit::log('export', 'receipt', $id, (int)$student->userid, $line->receipt);
send_file(
    \local_studentrecords\local\payments::receipt_pdf($line),
    clean_filename($line->receipt . '.pdf'),
    0,
    0,
    true,
    true,
    'application/pdf'
);
