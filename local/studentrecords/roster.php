<?php

/**
 * Faculty roster report: every teacher with degrees and the courses they are qualified to teach, for a state
 * agency or accreditor review. Downloadable.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\audit;
use local_studentrecords\local\faculty;

$download = optional_param('download', '', PARAM_ALPHA);
$all = optional_param('all', 0, PARAM_BOOL);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('roster');
require_capability('local/studentrecords:viewfaculty', context_system::instance());
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/roster.php', ['all' => $all]);
$PAGE->set_url($url);

$manage = has_capability('local/studentrecords:managefaculty', context_system::instance());
$bulk = $manage ? new \local_studentrecords\form\bulkqualify($url) : null;
if ($bulk && ($data = $bulk->get_data())) {
    $courseids = array_map('intval', (array)$data->courseids);
    $n = 0;
    foreach (array_map('intval', (array)$data->facultyids) as $fid) {
        $n += $data->bulkaction === 'remove' ? faculty::remove_qualifications($fid, $courseids) :
            faculty::add_qualifications($fid, $courseids);
    }
    redirect(
        $url,
        get_string($data->bulkaction === 'remove' ? 'qualifiedremoved' : 'qualifiedadded', $p, $n),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$rows = faculty::roster(!$all);
$columns = ['name' => get_string('name'), 'department' => get_string('fac_department', $p),
    'jobtitle' => get_string('fac_jobtitle', $p), 'employment' => get_string('employment', $p),
    'credentials' => get_string('credentials', $p), 'courses' => get_string('qualifiedcourses', $p)];
if ($download !== '') {
    audit::log('export', 'roster', null, null, $download . ' ' . count($rows));
    $data = array_map(fn($r) => array_values(array_intersect_key($r, $columns)), $rows);
    \local_studentrecords\local\reports::download('faculty_roster_' . date('Ymd'), $download, array_values($columns), $data);
    die();
}

audit::log('view', 'roster', null, null, (string)count($rows));
echo \local_studentrecords\local\staffarea::header();
echo html_writer::tag('p', get_string('roster_intro', $p));
echo html_writer::link(
    new moodle_url('/local/studentrecords/roster.php', ['all' => $all ? 0 : 1]),
    get_string($all ? 'roster_activeonly' : 'roster_all', $p)
);
$table = new html_table();
$table->head = array_values($columns);
foreach ($rows as $r) {
    $line = [];
    foreach (array_keys($columns) as $k) {
        $line[] = $k === 'name' ? html_writer::link(
            new moodle_url('/local/studentrecords/facultyrecord.php', ['id' => $r['id']]),
            s($r[$k])
        ) : s($r[$k]);
    }
    $table->data[] = $line;
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nofaculty', $p), 'info');
if ($bulk) {
    $bulk->display();
}
echo $OUTPUT->download_dataformat_selector(get_string('download'), $url->out_omit_querystring(), 'download', ['all' => $all]);
echo $OUTPUT->footer();
