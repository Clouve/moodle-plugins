<?php

/**
 * Account statement (PDF) for Finance staff or the student; logged.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\students;

$id = required_param('id', PARAM_INT);
$termid = optional_param('termid', 0, PARAM_INT);
require_login(null, false);
$student = students::get($id);
if (!billing::can_view($student)) {
    throw new required_capability_exception(context_system::instance(), 'local/studentrecords:viewbilling', 'nopermissions', '');
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/studentrecords/statement.php', ['id' => $id]));
\local_studentrecords\local\audit::log('export', 'statement', $id, (int)$student->userid, $termid ? 'term ' . $termid : '');
$pdf = billing::statement_pdf($student, $termid);
$filename = clean_filename('statement-' . $student->studentid . '-' . date('Ymd') . '.pdf');
send_file($pdf, $filename, 0, 0, true, true, 'application/pdf');
