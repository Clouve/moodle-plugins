<?php

/**
 * Log of issued Official transcripts (admins) and re-download of an issued transcript.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\access;
use local_academictranscript\local\logger;
use local_academictranscript\local\pdf\builder;
use local_academictranscript\local\transcript;

$id = optional_param('id', 0, PARAM_INT);

if ($id) {
    // Re-download exactly what was issued (frozen snapshot).
    require_login(null, false);
    $issue = $DB->get_record('local_academictranscript_issue', ['id' => $id], '*', MUST_EXIST);
    if (
        !access::can_issue($issue->userid)
            && !has_capability('local/academictranscript:viewissued', context_system::instance())
    ) {
        throw new required_capability_exception(
            context_system::instance(),
            'local/academictranscript:viewissued',
            'nopermissions',
            ''
        );
    }
    $data = transcript::stored((string)$issue->snapshot);
    if ($data === null) {
        throw new moodle_exception(
            'issuedoldlayout',
            'local_academictranscript',
            new moodle_url('/local/academictranscript/index.php', ['userid' => $issue->userid])
        );
    }
    $pdf = builder::render($data, $issue);
    logger::log('redownload', (int)$issue->userid, (int)$issue->id);
    send_file($pdf, builder::filename($data, true), 0, 0, true, true, 'application/pdf');
    die();
}

admin_externalpage_setup('local_academictranscript_issued');
\local_academictranscript\local\access::require_setup();
$PAGE->set_url(new moodle_url('/local/academictranscript/issued.php'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('issuedlog', 'local_academictranscript'));

$issues = $DB->get_records(
    'local_academictranscript_issue',
    null,
    'timecreated DESC',
    'id, userid, verifycode, issuedby, timecreated, recipient, transcriptno'
);
if (!$issues) {
    echo $OUTPUT->notification(get_string('noissued', 'local_academictranscript'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('dateissuedcol', 'local_academictranscript'), get_string('transcriptno', 'local_academictranscript'),
        get_string('studentname', 'local_academictranscript'), get_string('issuedto', 'local_academictranscript'),
        get_string('issuedby', 'local_academictranscript'), get_string('verifycode', 'local_academictranscript'), ''];
    foreach ($issues as $issue) {
        $student = core_user::get_user($issue->userid);
        $table->data[] = [
            userdate($issue->timecreated),
            s((string)$issue->transcriptno),
            $student ? html_writer::link(
                new moodle_url('/local/academictranscript/index.php', ['userid' => $issue->userid]),
                fullname($student)
            ) : $issue->userid,
            s((string)$issue->recipient),
            fullname(core_user::get_user($issue->issuedby)),
            transcript::format_code($issue->verifycode),
            html_writer::link(new moodle_url('/local/academictranscript/issued.php', ['id' => $issue->id]), get_string('download')),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
