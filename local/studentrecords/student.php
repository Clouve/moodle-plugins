<?php

/**
 * One student record: view (every view is logged), edit (records staff), change history.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\access;
use local_studentrecords\local\audit;
use local_studentrecords\local\catalog;
use local_studentrecords\local\dates;
use local_studentrecords\local\history_view;
use local_studentrecords\local\records;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$id = optional_param('id', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_BOOL);
$courseid = optional_param('courseid', 0, PARAM_INT);
$p = 'local_studentrecords';
$syscontext = context_system::instance();

require_login($courseid ? get_course($courseid) : null, false);
if ($edit) {
    require_capability('local/studentrecords:managestudents', $syscontext);
}
if ($userid && !$id) {
    access::require_view_student($userid);
    $rec = students::get_by_user($userid);
    if (!$rec) {
        throw new moodle_exception('norecord', $p);
    }
    $id = (int)$rec->id;
}
$student = $id ? students::get($id) : null;
if ($student) {
    access::require_view_student((int)$student->userid);
}
$url = new moodle_url('/local/studentrecords/student.php', array_filter(['id' => $id, 'edit' => $edit ? 1 : 0,
    'courseid' => $courseid]));
$staff = !$courseid && staffarea::can_open('students');
if ($staff) {
    staffarea::start_page($url, 'students');
    $PAGE->navbar->add(get_string('studentrecords', $p), new moodle_url('/local/studentrecords/students.php'));
    $PAGE->navbar->add($student ? students::display_name($student, access::hide_directory($student)) :
        get_string('addstudent', $p), $url);
} else {
    $PAGE->set_url($url);
    if (!$courseid) {
        $PAGE->set_context($syscontext);
        $PAGE->set_pagelayout('standard');
    }
    $own = $student && (int)$student->userid === (int)$USER->id && !$courseid;
    $PAGE->set_heading(get_string($own ? 'nav_myacademicrecord' : 'studentrecord', $p));
    if ($own) {
        $PAGE->navbar->ignore_active();
        $PAGE->navbar->add(get_string('pluginname', $p), new moodle_url('/local/studentrecords/index.php'));
        $PAGE->navbar->add(get_string('nav_myacademicrecord', $p), $url);
    }
}
$own = !$staff && $student && (int)$student->userid === (int)$USER->id && !$courseid;
$hide = $student && access::hide_directory($student);
$PAGE->set_title(get_string($own ? 'nav_myacademicrecord' : 'studentrecord', $p));
$header = fn() => $staff ? staffarea::header() : $OUTPUT->header();

if ($edit) {
    $form = new \local_studentrecords\form\student($url, ['id' => $id]);
    $draftid = file_get_submitted_draft_itemid('documents');
    file_prepare_draft_area($draftid, $syscontext->id, $p, 'studentdocs', $id ?: null, ['maxfiles' => 20, 'subdirs' => 0]);
    if ($student) {
        $current = (array)$student;
        $current['documents'] = $draftid;
        foreach (['programid', 'admittermid', 'advisorid'] as $f) {
            $current[$f] = (int)$current[$f];
        }
        [$current['dob_day'], $current['dob_month'], $current['dob_year']] =
            dates::ymd_to_parts(catalog::time_to_date($student->dob ? (int)$student->dob : null));
        $form->set_data($current);
    } else {
        $form->set_data(['documents' => $draftid, 'status' => 'applicant']);
    }
    if ($form->is_cancelled()) {
        redirect($id ? new moodle_url('/local/studentrecords/student.php', ['id' => $id]) :
            new moodle_url('/local/studentrecords/students.php'));
    }
    if ($data = $form->get_data()) {
        $record = (array)$data;
        foreach (['programid', 'admittermid', 'advisorid'] as $f) {
            $record[$f] = empty($record[$f]) ? null : (int)$record[$f];
        }
        foreach (['awarddate', 'withdrawdate', 'lastattended'] as $f) {
            $record[$f] = empty($record[$f]) ? null : $record[$f];
        }
        // Date of birth: blank unless all of day, month and year are picked (checked by the form).
        $dob = dates::parts_to_ymd($data->dob_day, $data->dob_month, $data->dob_year);
        $record['dob'] = $dob ? catalog::date_to_time($dob) : null;
        unset($record['dob_day'], $record['dob_month'], $record['dob_year']);
        $record['studentid'] = trim($record['studentid']);
        $record['homecountry'] = $record['homecountry'] ?: null;
        $record['loadtype'] = $record['loadtype'] ?: null;
        foreach (['residency', 'citizenship', 'firstgen', 'highschool'] as $f) {
            $record[$f] = trim((string)($record[$f] ?? '')) === '' ? null : trim((string)$record[$f]);
        }
        $record['hsyear'] = ctype_digit((string)($record['hsyear'] ?? '')) ? (int)$record['hsyear'] : null;
        if (!$id) {
            unset($record['id']);
        }
        unset($record['documents'], $record['submitbutton']);
        $newid = students::save($record);
        file_save_draft_area_files(
            $data->documents,
            $syscontext->id,
            $p,
            'studentdocs',
            $newid,
            ['maxfiles' => 20, 'subdirs' => 0]
        );
        redirect(
            new moodle_url('/local/studentrecords/student.php', ['id' => $newid]),
            get_string('saved', $p),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $header();
    echo $OUTPUT->heading(get_string($id ? 'editstudent' : 'addstudent', $p));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

if (!$student) {
    throw new moodle_exception('norecord', $p);
}
audit::log('view', 'student', (int)$student->id, (int)$student->userid);

$manage = access::can_manage_students();
if ($staff) {
    // Staff view: header, tabs and one tab at a time.
    $file = \local_studentrecords\local\student_file::class;
    $sections = \local_studentrecords\local\student_sections::class;
    $tab = $file::pick_tab(optional_param('tab', 'profile', PARAM_ALPHA), $manage);
    $PAGE->set_url(new moodle_url('/local/studentrecords/student.php', ['id' => $student->id, 'tab' => $tab]));
    echo $header();
    echo $manage ? $sections::release_check($student) : '';
    echo $file::header($student, $manage);
    echo $file::tabs($student, $tab, $manage);
    if ($tab === 'profile') {
        echo $file::profile($student, $hide, $manage);
    } else if ($tab === 'admissions') {
        echo $sections::checklist($student, $manage) . $sections::decisions($student, $manage) .
            $sections::status_history($student) . $sections::education($student, $manage);
    } else if ($tab === 'registration') {
        echo $sections::academic($student, $manage);
    } else if ($tab === 'attendance') {
        echo $sections::attendance($student);
    } else if ($tab === 'holds') {
        $holds = $sections::holds($student, $manage);
        echo $holds . $sections::standing_history($student);
    } else if ($tab === 'graduation') {
        echo $sections::graduation($student, $manage);
        echo html_writer::div(html_writer::link(
            new moodle_url('/local/studentrecords/degreeaudit.php', ['id' => $student->id]),
            get_string('rec_seeaudit', $p),
            ['class' => 'btn btn-outline-secondary']
        ), 'sr-actions');
    } else if ($tab === 'privacy') {
        echo $sections::ferpa_section($student, $manage);
    } else if ($tab === 'documents') {
        // Only those who can open the documents see their names.
        $files = $manage ?
            get_file_storage()->get_area_files($syscontext->id, $p, 'studentdocs', $student->id, 'filename', false) : [];
        $links = [];
        foreach ($files as $f) {
            $links[] = html_writer::link(moodle_url::make_pluginfile_url(
                $syscontext->id,
                $p,
                'studentdocs',
                $student->id,
                $f->get_filepath(),
                $f->get_filename()
            ), s($f->get_filename()));
        }
        echo $links ? html_writer::alist($links) : \local_studentrecords\local\shell::empty_state(get_string('docs_none', $p));
        if ($manage) {
            echo html_writer::link(
                new moodle_url('/local/studentrecords/student.php', ['id' => $student->id, 'edit' => 1]),
                get_string('file_adddocs', $p),
                ['class' => 'btn btn-outline-secondary']
            );
        }
    } else if ($tab === 'history') {
        $table = new html_table();
        $table->attributes['class'] = 'sr-table';
        $table->head = [get_string('date'), get_string('changedby', $p), get_string('field', $p), get_string('oldvalue', $p),
            get_string('newvalue', $p), get_string('source', $p)];
        foreach (records::get_history(students::TABLE, (int)$student->id) as $h) {
            $table->data[] = history_view::row($h);
        }
        echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('none'));
    }
    echo $OUTPUT->footer();
    die();
}
$d = fn($t) => catalog::show_date($t ? (int)$t : null);
$programs = catalog::programs(false);
$terms = catalog::terms();
$advisors = catalog::faculty('');
$rows = [
    get_string('studentid', $p) => s($student->studentid),
    get_string('legalname', $p) => s(trim($student->legalfirstname . ' ' . $student->legalmiddlename . ' ' .
        $student->legallastname)),
    get_string('preferredname', $p) => s((string)$student->preferredname),
    get_string('dob', $p) => $d($student->dob),
    get_string('email') => s((string)$student->email),
    get_string('phone', $p) => s((string)$student->phone),
    get_string('usaddress', $p) => nl2br(s((string)$student->usaddress)),
    get_string('homeaddress', $p) => nl2br(s((string)$student->homeaddress)) .
        ($student->homecountry ? '<br>' . s(get_string_manager()->get_list_of_countries()[$student->homecountry] ?? '') : ''),
    get_string('program', $p) => $student->programid ? ($programs[$student->programid] ?? '') : '',
    get_string('admitterm', $p) => $student->admittermid ? format_string($terms[$student->admittermid] ?? '') : '',
    get_string('catalogyear', $p) => s((string)$student->catalogyear),
    get_string('status', $p) => get_string('status_' . $student->status, $p),
    get_string('withdrawdate', $p) => $d($student->withdrawdate),
    get_string('lastattended', $p) => $d($student->lastattended),
    get_string('loadtype', $p) => $student->loadtype ? get_string('load_' . $student->loadtype, $p) : '',
    get_string('advisor', $p) => $student->advisorid ? s($advisors[$student->advisorid] ?? '') : '',
    get_string('awardname', $p) => s((string)$student->awardname),
    get_string('awarddate', $p) => $d($student->awarddate),
    get_string('ferpaoptout', $p) => $student->ferpaoptout ? get_string('yes') : get_string('no'),
];
// Visa details are for records staff and the student, not for a student's teachers.
if (catalog::module_on('f1') && (access::sees_all_students() || $student->userid == $USER->id)) {
    $rows[get_string('isf1', $p)] = $student->isf1 ? get_string('yes') : get_string('no');
    $rows[get_string('visatype', $p)] = s((string)$student->visatype);
    $rows[get_string('sevisid', $p)] = s((string)$student->sevisid);
}
$directory = array_map(fn($f) => $f === 'email' ? get_string('email') : get_string($f, $p), students::DIRECTORY);

echo $header();
if ($own) {
    echo \local_studentrecords\local\shell::inline_menu();
    if (\core_component::get_component_directory('local_academictranscript') !== null) {
        echo html_writer::div(html_writer::link(
            new moodle_url('/local/academictranscript/index.php'),
            get_string('rec_transcriptlink', $p),
            ['class' => 'btn btn-outline-secondary']
        ), 'sr-actions justify-content-end');
    }
    echo \local_studentrecords\local\record_view::render($student);
    echo html_writer::start_tag('details', ['class' => 'sr-details mt-4']);
    echo html_writer::tag('summary', get_string('rec_mydetails', $p), ['class' => 'sr-h2']);
} else {
    echo $OUTPUT->heading(s(students::display_name($student, $hide)));
}
if ($student->ferpaoptout) {
    echo $OUTPUT->notification(get_string($hide ? 'ferpahidden' : 'ferpanotice', $p), 'warning');
}
$table = new html_table();
foreach ($rows as $label => $value) {
    if ($hide && in_array($label, $directory, true)) {
        $value = html_writer::span(get_string('withheld', $p), 'text-muted');
    }
    $table->data[] = [html_writer::tag('strong', $label), $value];
}
echo html_writer::table($table);

if ($manage || $student->userid == $USER->id) {
    $files = get_file_storage()->get_area_files($syscontext->id, $p, 'studentdocs', $student->id, 'filename', false);
    echo $OUTPUT->heading(get_string('documents', $p), 3);
    $links = [];
    foreach ($files as $f) {
        $links[] = html_writer::link(moodle_url::make_pluginfile_url(
            $syscontext->id,
            $p,
            'studentdocs',
            $student->id,
            $f->get_filepath(),
            $f->get_filename()
        ), s($f->get_filename()));
    }
    echo $links ? html_writer::alist($links) : html_writer::tag('p', get_string('none'));
}
// Admissions sections: records staff and the student; not teachers.
if (access::sees_all_students() || $student->userid == $USER->id) {
    echo \local_studentrecords\local\student_sections::render($student, $manage);
}
if ($own) {
    echo html_writer::end_tag('details');
}
if ($manage) {
    echo html_writer::link(
        new moodle_url('/local/studentrecords/student.php', ['id' => $student->id, 'edit' => 1]),
        get_string('editstudent', $p),
        ['class' => 'btn btn-primary mb-3']
    );
    echo $OUTPUT->heading(get_string('changehistory', $p), 3);
    $table = new html_table();
    $table->head = [get_string('date'), get_string('changedby', $p), get_string('field', $p), get_string('oldvalue', $p),
        get_string('newvalue', $p), get_string('source', $p)];
    foreach (records::get_history(students::TABLE, (int)$student->id) as $h) {
        $table->data[] = history_view::row($h);
    }
    echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('none'));
}
echo $OUTPUT->footer();
