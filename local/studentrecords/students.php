<?php

/**
 * Students' records: list (staff), or the students of one course (teachers).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\access;
use local_studentrecords\local\catalog;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$courseid = optional_param('courseid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$programid = optional_param('programid', 0, PARAM_INT);
$termid = optional_param('termid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$f1 = optional_param('f1', '', PARAM_ALPHANUM);
$p = 'local_studentrecords';
$status = in_array($status, catalog::STATUSES, true) ? $status : '';
$f1 = in_array($f1, ['0', '1'], true) && catalog::module_on('f1') ? $f1 : '';
$filters = array_filter(['search' => $search, 'programid' => $programid, 'termid' => $termid, 'status' => $status,
    'f1' => $f1], fn($v) => $v !== '' && $v !== 0);

if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/studentrecords:viewstudents', $context);
    $url = new moodle_url('/local/studentrecords/students.php', ['courseid' => $courseid] + $filters);
    $PAGE->set_url($url);
    $PAGE->set_title(get_string('studentrecords', $p));
    $PAGE->set_heading(format_string($course->fullname));
    $users = get_enrolled_users($context, '', 0, 'u.id', 'u.lastname, u.firstname', 0, 0, true);
    $ids = array_keys($users);
} else {
    require_login();
    if (!access::sees_all_students()) {
        throw new required_capability_exception(
            context_system::instance(),
            'local/studentrecords:viewstudents',
            'nopermissions',
            ''
        );
    }
    staffarea::setup('students', new moodle_url('/local/studentrecords/students.php', $filters));
    $url = $PAGE->url;
    $ids = null;
}

$where = '1 = 1';
$params = [];
if ($ids !== null) {
    if (!$ids) {
        $ids = [0];
    }
    [$in, $params] = $DB->get_in_or_equal($ids);
    $where = "s.userid $in";
}
foreach (['programid' => $programid, 'admittermid' => $termid] as $field => $value) {
    if ($value) {
        $where .= " AND s.$field = ?";
        $params[] = $value;
    }
}
if ($status !== '') {
    $where .= ' AND s.status = ?';
    $params[] = $status;
}
if ($f1 !== '') {
    $where .= ' AND s.isf1 = ?';
    $params[] = (int)$f1;
}
if ($search !== '') {
    $like = [];
    $text = '%' . $DB->sql_like_escape($search) . '%';
    foreach (['s.studentid', 's.legalfirstname', 's.legallastname'] as $f) {
        $like[] = $DB->sql_like($f, '?', false);
        $params[] = $text;
    }
    // A preferred name is directory information: staff who may not see it for a student cannot search on it.
    $like[] = (access::can_manage_students() ? '' : 's.ferpaoptout = 0 AND ') . $DB->sql_like('s.preferredname', '?', false);
    $params[] = $text;
    $where .= ' AND (' . implode(' OR ', $like) . ')';
}
$perpage = 50;
$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_studentrecords_student} s WHERE $where", $params);
$rows = $DB->get_records_sql("SELECT s.* FROM {local_studentrecords_student} s WHERE $where
                               ORDER BY s.legallastname, s.legalfirstname", $params, $page * $perpage, $perpage);

echo $courseid ? $OUTPUT->header() : staffarea::header();
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(),
    'class' => 'mb-3 d-flex flex-wrap align-items-end gap-2']);
if ($courseid) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
}
$filter = function (string $name, string $label, array $options, $value) {
    return html_writer::div(html_writer::label($label, 'sr' . $name, true, ['class' => 'd-block small']) .
        html_writer::select($options, $name, $value, false, ['id' => 'sr' . $name, 'class' => 'form-select w-auto']));
};
$statuses = ['' => get_string('all')];
foreach (catalog::STATUSES as $st) {
    $statuses[$st] = get_string('status_' . $st, $p);
}
echo html_writer::div(html_writer::label(get_string('search'), 'srsearch', true, ['class' => 'd-block small']) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'id' => 'srsearch', 'value' => $search,
    'class' => 'form-control w-auto']));
echo $filter('programid', get_string('program', $p), [0 => get_string('all')] + catalog::programs(false), $programid);
echo $filter('termid', get_string('admitterm', $p), [0 => get_string('all')] +
    array_map('format_string', catalog::terms()), $termid);
echo $filter('status', get_string('status', $p), $statuses, $status);
if (catalog::module_on('f1')) {
    echo $filter('f1', get_string('isf1', $p), ['' => get_string('all'), '1' => get_string('yes'), '0' => get_string('no')], $f1);
}
echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter', $p),
    'class' => 'btn btn-secondary']));
if ($filters) {
    echo html_writer::div(html_writer::link(
        new moodle_url('/local/studentrecords/students.php', $courseid ? ['courseid' => $courseid] : []),
        get_string('clearfilters', $p),
        ['class' => 'btn btn-link']
    ));
}
echo html_writer::end_tag('form');
echo html_writer::tag('p', get_string('studentsfound', $p, $total), ['class' => 'small text-muted']);
$programs = catalog::programs(false);
$table = new html_table();
$table->head = [get_string('studentid', $p), get_string('name'), get_string('program', $p), get_string('status', $p),
    get_string('loadtype', $p)];
// Missing application documents: shown to records staff only (not in a teacher's course list).
$missing = $courseid ? [] : \local_studentrecords\local\admissions::missing_counts($rows);
if (!$courseid) {
    $table->head[] = get_string('appchecklist', $p);
}
foreach ($rows as $s) {
    $table->data[] = [s($s->studentid), html_writer::link(new moodle_url(
        '/local/studentrecords/student.php',
        ['id' => $s->id] + ($courseid ? ['courseid' => $courseid] : [])
    ), s(students::display_name($s, access::hide_directory($s)))),
        $s->programid ? ($programs[$s->programid] ?? '') : '', get_string('status_' . $s->status, $p),
        $s->loadtype ? get_string('load_' . $s->loadtype, $p) : ''];
    if (!$courseid) {
        $n = $missing[$s->id] ?? 0;
        $badge = html_writer::span(get_string('nmissing', $p, $n), 'badge bg-warning text-dark');
        $table->data[count($table->data) - 1][] = $n ? $badge : '';
    }
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nostudents', $p), 'info');
echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
if (!$courseid && access::can_manage_students()) {
    echo html_writer::link(
        new moodle_url('/local/studentrecords/student.php', ['edit' => 1]),
        get_string('addstudent', $p),
        ['class' => 'btn btn-primary']
    );
}
echo $OUTPUT->footer();
