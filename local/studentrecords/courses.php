<?php

/**
 * Course catalog: list with search, add and edit.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\grading;
use local_studentrecords\local\records;

$edit = optional_param('edit', -1, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('courses');
require_capability('local/studentrecords:configure', context_system::instance());
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/courses.php', ['search' => $search]);
$PAGE->set_url($url);
$num = fn($v) => $v === null || $v === '' ? '' : format_float($v, -1);

if ($edit >= 0) {
    $form = new \local_studentrecords\form\course(new moodle_url($url, ['edit' => $edit]));
    if ($edit) {
        $rec = $DB->get_record('local_studentrecords_course', ['id' => $edit], '*', MUST_EXIST);
        $rec->hours = $rec->hours === null ? '' : format_float($rec->hours, -1, false);
        $form->set_data($rec);
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $data->code = grading::normalise_code($data->code);
        $data->hours = $data->hours === '' ? null : (float)$data->hours;
        $data->levelid = $data->levelid ?: null;
        if (!$edit) {
            unset($data->id);
        }
        $id = records::save('local_studentrecords_course', $data);
        \local_studentrecords\local\audit::log($edit ? 'update' : 'create', 'course', $id);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(get_string($edit ? 'editcourse' : 'addcourse', $p));
    $form->display();
    if ($edit) {
        $sql = "SELECT pc.id, p.code, pc.groupname, pc.requirement
                  FROM {local_studentrecords_progcrs} pc
                  JOIN {local_studentrecords_program} p ON p.id = pc.programid
                 WHERE pc.courseid = ? ORDER BY p.code";
        $used = $DB->get_records_sql($sql, [$edit]);
        echo $OUTPUT->heading(get_string('usedinprograms', $p), 4);
        echo $used ? html_writer::alist(array_map(fn($u) => s($u->code . ' › ' . $u->groupname) . ' (' .
            get_string('req_' . $u->requirement, $p) . ')', $used)) : html_writer::tag('p', get_string('none'));
        $prereqs = $DB->get_records_sql("SELECT c.id, c.code FROM {local_studentrecords_prereq} r
                                           JOIN {local_studentrecords_course} c ON c.id = r.reqcourseid
                                          WHERE r.courseid = ? ORDER BY c.code", [$edit]);
        echo $OUTPUT->heading(get_string('prereqlinked', $p), 4);
        echo html_writer::tag('p', $prereqs ? s(implode(', ', array_map(fn($c) => $c->code, $prereqs))) : get_string('none'));
    }
    echo $OUTPUT->footer();
    die();
}

echo \local_studentrecords\local\staffarea::header();
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'mb-3']);
echo html_writer::label(get_string('search'), 'srsearch', true, ['class' => 'me-2']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'id' => 'srsearch', 'value' => $search,
    'class' => 'form-control d-inline-block w-auto me-2']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
$where = '1 = 1';
$params = [];
if ($search !== '') {
    $where = $DB->sql_like('code', '?', false) . ' OR ' . $DB->sql_like('title', '?', false);
    $params = ['%' . $DB->sql_like_escape($search) . '%', '%' . $DB->sql_like_escape($search) . '%'];
}
$perpage = 50;
$total = $DB->count_records_select('local_studentrecords_course', $where, $params);
$levels = catalog::levels();
$table = new html_table();
$table->head = [get_string('coursecode', $p), get_string('coursetitle', $p), get_string('hours', $p),
    get_string('unittype', $p), get_string('level', $p), get_string('prereqtext', $p), get_string('active', $p)];
foreach ($DB->get_records_select('local_studentrecords_course', $where, $params, 'code', '*', $page * $perpage, $perpage) as $c) {
    $table->data[] = [html_writer::link(new moodle_url($url, ['edit' => $c->id]), s($c->code)), format_string($c->title),
        $num($c->hours), get_string('unit_' . $c->unittype, $p), $c->levelid ? format_string($levels[$c->levelid] ?? '') : '',
        s((string)$c->prereqtext), $c->active ? get_string('yes') : get_string('no')];
}
echo html_writer::tag('p', get_string('countcourses', $p, $total));
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nocourses', $p), 'info');
echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
echo html_writer::link(new moodle_url($url, ['edit' => 0]), get_string('addcourse', $p), ['class' => 'btn btn-primary']);
echo $OUTPUT->footer();
