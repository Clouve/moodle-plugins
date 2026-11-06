<?php

/**
 * My documents: the student's own files on their record and the state of their required documents.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\admissions;
use local_studentrecords\local\shell;
use local_studentrecords\local\students;

require_login(null, false);
$p = 'local_studentrecords';
$syscontext = context_system::instance();
require_capability('local/studentrecords:viewown', $syscontext);
$student = students::get_by_user((int)$USER->id);
if (!$student) {
    throw new moodle_exception('norecord', $p);
}
$url = new moodle_url('/local/studentrecords/mydocuments.php');
$PAGE->set_context($syscontext);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('nav_mydocuments', $p) . ' - ' . get_string('pluginname', $p));
$PAGE->set_heading(get_string('nav_mydocuments', $p));
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(get_string('pluginname', $p), new moodle_url('/local/studentrecords/index.php'));
$PAGE->navbar->add(get_string('nav_mydocuments', $p), $url);

echo $OUTPUT->header();
echo shell::inline_menu();
$files = get_file_storage()->get_area_files($syscontext->id, $p, 'studentdocs', $student->id, 'filename', false);
echo html_writer::tag('h2', get_string('docs_files', $p), ['class' => 'sr-h2']);
if ($files) {
    $table = new html_table();
    $table->attributes['class'] = 'sr-table';
    $table->head = [get_string('docs_name', $p), get_string('date')];
    foreach ($files as $f) {
        $table->data[] = [html_writer::link(moodle_url::make_pluginfile_url(
            $syscontext->id,
            $p,
            'studentdocs',
            $student->id,
            $f->get_filepath(),
            $f->get_filename()
        ), s($f->get_filename())), userdate(
            $f->get_timemodified(),
            get_string('strftimedate', 'langconfig')
        )];
    }
    echo html_writer::table($table);
} else {
    echo shell::empty_state(get_string('docs_none', $p));
}
$checklist = admissions::checklist($student);
if ($checklist) {
    echo html_writer::tag('h2', get_string('docs_required', $p), ['class' => 'sr-h2']);
    $table = new html_table();
    $table->attributes['class'] = 'sr-table';
    $table->head = [get_string('docs_name', $p), get_string('status', $p)];
    foreach ($checklist as $item) {
        $tone = in_array($item->state, ['received', 'waived'], true) ? 'good' : 'warn';
        $table->data[] = [format_string($item->name), shell::tag(get_string('docstate_' . $item->state, $p), $tone)];
    }
    echo html_writer::table($table);
}
echo html_writer::tag('p', get_string('docs_help', $p), ['class' => 'sr-muted']);
echo $OUTPUT->footer();
