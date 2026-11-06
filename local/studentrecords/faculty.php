<?php

/**
 * Faculty and staff records: list.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;

$type = optional_param('type', 'faculty', PARAM_ALPHA);
$type = in_array($type, ['faculty', 'staff']) ? $type : 'faculty';
require_login(null, false);
\local_studentrecords\local\staffarea::setup('faculty');
require_capability('local/studentrecords:viewfaculty', context_system::instance());
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/faculty.php', ['type' => $type]);
$PAGE->set_url($url);

echo \local_studentrecords\local\staffarea::header();
$tabs = [];
foreach (['faculty', 'staff'] as $t) {
    $tabs[] = new tabobject($t, new moodle_url('/local/studentrecords/faculty.php', ['type' => $t]), get_string('type_' . $t, $p));
}
echo $OUTPUT->tabtree($tabs, $type);
$table = new html_table();
$table->head = [get_string('name'), get_string('fac_jobtitle', $p), get_string('fac_department', $p),
    get_string('employment', $p), get_string('moodleaccount', $p), get_string('active', $p)];
foreach ($DB->get_records('local_studentrecords_faculty', ['stafftype' => $type], 'lastname, firstname') as $f) {
    $table->data[] = [html_writer::link(
        new moodle_url('/local/studentrecords/facultyrecord.php', ['id' => $f->id]),
        s(catalog::faculty_name($f))
    ), s((string)$f->jobtitle), s((string)$f->department),
        $f->employment ? get_string('load_' . $f->employment, $p) : '', $f->userid ? get_string('yes') : get_string('no'),
        $f->active ? get_string('yes') : get_string('no')];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nofaculty', $p), 'info');
if (has_capability('local/studentrecords:managefaculty', context_system::instance())) {
    echo html_writer::link(
        new moodle_url('/local/studentrecords/facultyrecord.php', ['edit' => 1]),
        get_string('addfaculty', $p),
        ['class' => 'btn btn-primary me-2']
    );
}
echo html_writer::link(
    new moodle_url('/local/studentrecords/roster.php'),
    get_string('roster', $p),
    ['class' => 'btn btn-secondary']
);
echo $OUTPUT->footer();
