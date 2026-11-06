<?php

/**
 * Audit log: who viewed, changed, imported or exported which record.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\audit;

$action = optional_param('logaction', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('log');
require_capability('local/studentrecords:viewlog', context_system::instance());
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/log.php', ['logaction' => $action]);
$PAGE->set_url($url);

$params = [];
$where = '1 = 1';
if ($action !== '' && in_array($action, audit::ACTIONS)) {
    $where = 'action = ?';
    $params[] = $action;
}
$perpage = 100;
$total = $DB->count_records_select('local_studentrecords_log', $where, $params);
$rows = $DB->get_records_select(
    'local_studentrecords_log',
    $where,
    $params,
    'timecreated DESC, id DESC',
    '*',
    $page * $perpage,
    $perpage
);

echo \local_studentrecords\local\staffarea::header();
$options = ['' => get_string('all')];
foreach (audit::ACTIONS as $a) {
    $options[$a] = get_string('action_' . $a, $p);
}
echo $OUTPUT->single_select(
    new moodle_url('/local/studentrecords/log.php'),
    'logaction',
    $options,
    $action,
    null,
    null,
    ['label' => get_string('action', $p)]
);
$names = [];
$name = function ($uid) use (&$names) {
    if (!$uid) {
        return '-';
    }
    if (!isset($names[$uid])) {
        $u = core_user::get_user($uid);
        $names[$uid] = $u ? fullname($u) : '?';
    }
    return $names[$uid];
};
$table = new html_table();
$table->head = [get_string('date'), get_string('who', $p), get_string('action', $p), get_string('record', $p),
    get_string('about', $p), get_string('info', $p), get_string('ipaddress', $p)];
foreach ($rows as $r) {
    $record = get_string('obj_' . $r->objecttype, $p) . ($r->objectid ? ' #' . $r->objectid : '');
    if ($r->objecttype === 'student' && $r->objectid) {
        $record = html_writer::link(new moodle_url('/local/studentrecords/student.php', ['id' => $r->objectid]), $record);
    } else if ($r->objecttype === 'faculty' && $r->objectid) {
        $record = html_writer::link(new moodle_url('/local/studentrecords/facultyrecord.php', ['id' => $r->objectid]), $record);
    }
    $table->data[] = [userdate($r->timecreated), s($name($r->userid)), get_string('action_' . $r->action, $p), $record,
        s($name($r->relateduserid)), s((string)$r->info), s((string)$r->ip)];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nolog', $p), 'info');
echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
echo $OUTPUT->footer();
