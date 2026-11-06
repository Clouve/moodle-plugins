<?php

/**
 * Admins: activity log (views, downloads, issues, verifications, postings, changes).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_academictranscript_log');
\local_academictranscript\local\access::require_setup();
$studentid = optional_param('studentid', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$url = new moodle_url('/local/academictranscript/log.php', ['studentid' => $studentid]);
$PAGE->set_url($url);
$perpage = 100;

$params = [];
$where = '';
if ($studentid) {
    $where = 'WHERE studentid = :studentid';
    $params['studentid'] = $studentid;
}
$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_academictranscript_log} $where", $params);
$entries = $DB->get_records_sql(
    "SELECT * FROM {local_academictranscript_log} $where ORDER BY timecreated DESC, id DESC",
    $params,
    $page * $perpage,
    $perpage
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('activitylog', 'local_academictranscript'));
if ($studentid && ($s = core_user::get_user($studentid))) {
    echo html_writer::tag('p', get_string('logfor', 'local_academictranscript', fullname($s)) . ' ' .
        html_writer::link(new moodle_url('/local/academictranscript/log.php'), get_string('showall', 'local_academictranscript')));
}
if (!$entries) {
    echo $OUTPUT->notification(get_string('nologentries', 'local_academictranscript'), 'info');
} else {
    $names = [];
    $name = function (int $id) use (&$names) {
        if (!$id) {
            return get_string('notloggedin', 'local_academictranscript');
        }
        if (!isset($names[$id])) {
            $u = core_user::get_user($id);
            $names[$id] = $u ? fullname($u) : (string)$id;
        }
        return $names[$id];
    };
    $table = new html_table();
    $table->head = [get_string('time'), get_string('logaction', 'local_academictranscript'),
        get_string('logwho', 'local_academictranscript'),
        get_string('studentname', 'local_academictranscript'), get_string('ip_address'), get_string('details')];
    foreach ($entries as $e) {
        $student = $e->studentid ? html_writer::link(new moodle_url(
            '/local/academictranscript/log.php',
            ['studentid' => $e->studentid]
        ), s($name((int)$e->studentid))) : '';
        $table->data[] = [userdate($e->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            get_string('logaction_' . $e->action, 'local_academictranscript'), s($name((int)$e->userid)), $student, s($e->ip),
            s($e->info)];
    }
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
}
echo $OUTPUT->footer();
