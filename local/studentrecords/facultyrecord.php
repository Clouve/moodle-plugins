<?php

/**
 * One faculty or staff record: view, edit, credentials, qualified courses, advisees, change history.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\access;
use local_studentrecords\local\audit;
use local_studentrecords\local\catalog;
use local_studentrecords\local\faculty;
use local_studentrecords\local\history_view;
use local_studentrecords\local\records;
use local_studentrecords\local\students;

$id = optional_param('id', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_BOOL);
$cred = optional_param('cred', -1, PARAM_INT);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('faculty');
$syscontext = context_system::instance();
require_capability('local/studentrecords:viewfaculty', $syscontext);
$manage = has_capability('local/studentrecords:managefaculty', $syscontext);
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/facultyrecord.php', ['id' => $id]);
$PAGE->set_url($url);
$person = $id ? faculty::get($id) : null;

if ($edit) {
    require_capability('local/studentrecords:managefaculty', $syscontext);
    $usermenu = [];
    if ($person && $person->userid && ($u = core_user::get_user($person->userid))) {
        $usermenu[$u->id] = fullname($u);
    }
    $form = new \local_studentrecords\form\faculty(new moodle_url($url, ['edit' => 1]), ['usermenu' => $usermenu]);
    if ($person) {
        $form->set_data($person);
    }
    if ($form->is_cancelled()) {
        redirect($id ? $url : new moodle_url('/local/studentrecords/faculty.php'));
    }
    if ($data = $form->get_data()) {
        $data->userid = empty($data->userid) ? null : (int)$data->userid;
        $data->employment = $data->employment ?: null;
        if (!$id) {
            unset($data->id);
        }
        $newid = faculty::save($data);
        redirect(
            new moodle_url('/local/studentrecords/facultyrecord.php', ['id' => $newid]),
            get_string('saved', $p),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(get_string($id ? 'editfaculty' : 'addfaculty', $p));
    $form->display();
    echo $OUTPUT->footer();
    die();
}
if (!$person) {
    throw new moodle_exception('norecord', $p);
}

if ($cred >= 0) {
    require_capability('local/studentrecords:managefaculty', $syscontext);
    $form = new \local_studentrecords\form\credential(new moodle_url($url, ['cred' => $cred]));
    $current = $cred ?
        (array)$DB->get_record('local_studentrecords_faccred', ['id' => $cred, 'facultyid' => $id], '*', MUST_EXIST) :
        ['facultyid' => $id];
    $draftid = file_get_submitted_draft_itemid('files');
    file_prepare_draft_area($draftid, $syscontext->id, $p, 'credential', $cred ?: null, ['maxfiles' => 5, 'subdirs' => 0]);
    $current['files'] = $draftid;
    $form->set_data($current);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $record = (array)$data;
        $record['facultyid'] = $id;
        $record['yearawarded'] = $record['yearawarded'] === '' ? null : (int)$record['yearawarded'];
        $record['expires'] = empty($record['expires']) ? null : $record['expires'];
        if ($cred) {
            $record['id'] = $cred;
        } else {
            unset($record['id']);
        }
        $credid = faculty::save_credential($record);
        file_save_draft_area_files($data->files, $syscontext->id, $p, 'credential', $credid, ['maxfiles' => 5, 'subdirs' => 0]);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(get_string('credential', $p) . ': ' . s(catalog::faculty_name($person)));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

$qualify = new \local_studentrecords\form\qualify($url);
$qualify->set_data(['id' => $id]);
if ($manage && ($data = $qualify->get_data())) {
    $n = faculty::add_qualifications($id, array_map('intval', (array)$data->courseids));
    redirect($url, get_string('qualifiedadded', $p, $n), null, \core\output\notification::NOTIFY_SUCCESS);
}
$courses = faculty::qualified_courses($id);
$unqualify = new \local_studentrecords\form\unqualify($url, ['courses' => $courses]);
$unqualify->set_data(['id' => $id]);
if ($manage && ($data = $unqualify->get_data())) {
    $n = faculty::remove_qualifications($id, array_map('intval', (array)$data->removeids));
    redirect($url, get_string('qualifiedremoved', $p, $n), null, \core\output\notification::NOTIFY_SUCCESS);
}

audit::log('view', 'faculty', (int)$person->id, $person->userid ? (int)$person->userid : null);
echo \local_studentrecords\local\staffarea::header();
echo $OUTPUT->heading(s(catalog::faculty_name($person)));
$account = '';
if ($person->userid && ($u = core_user::get_user($person->userid))) {
    $account = html_writer::link(new moodle_url('/user/profile.php', ['id' => $u->id]), s(fullname($u)));
}
$rows = [
    get_string('stafftype', $p) => get_string('type_' . $person->stafftype, $p),
    get_string('fac_jobtitle', $p) => s((string)$person->jobtitle),
    get_string('fac_department', $p) => s((string)$person->department),
    get_string('email') => s((string)$person->email),
    get_string('phone', $p) => s((string)$person->phone),
    get_string('employment', $p) => $person->employment ? get_string('load_' . $person->employment, $p) : '',
    get_string('officehours', $p) => s((string)$person->officehours),
    get_string('active', $p) => $person->active ? get_string('yes') : get_string('no'),
    get_string('nolms', $p) => $person->nolms ? get_string('yes') : get_string('no'),
    get_string('moodleaccount', $p) => $account ?: get_string('none'),
];
$table = new html_table();
foreach ($rows as $k => $v) {
    $table->data[] = [html_writer::tag('strong', $k), $v];
}
echo html_writer::table($table);
if ($manage) {
    echo html_writer::link(new moodle_url($url, ['edit' => 1]), get_string('editfaculty', $p), ['class' => 'btn btn-primary mb-3']);
}

echo $OUTPUT->heading(get_string('credentials', $p), 3);
$table = new html_table();
$table->head = [get_string('credtype', $p), get_string('cred_title', $p), get_string('cred_subject', $p),
    get_string('cred_institution', $p), get_string('cred_yearawarded', $p), get_string('expires', $p),
    get_string('cred_verified', $p), get_string('cred_files', $p), ''];
foreach (faculty::credentials($id) as $c) {
    $files = [];
    foreach (get_file_storage()->get_area_files($syscontext->id, $p, 'credential', $c->id, 'filename', false) as $f) {
        $files[] = html_writer::link(moodle_url::make_pluginfile_url(
            $syscontext->id,
            $p,
            'credential',
            $c->id,
            $f->get_filepath(),
            $f->get_filename()
        ), s($f->get_filename()));
    }
    $expired = $c->expires && $c->expires < time();
    $table->data[] = [get_string('credtype_' . $c->credtype, $p), s($c->title), s((string)$c->subject),
        s((string)$c->institution), $c->yearawarded, $c->expires ? html_writer::span(
            catalog::show_date((int)$c->expires),
            $expired ? 'text-danger' : ''
        ) : '',
        $c->verified ? get_string('yes') : get_string('no'), implode('<br>', $files),
        $manage ? html_writer::link(new moodle_url($url, ['cred' => $c->id]), get_string('edit')) : ''];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('none'));
if ($manage) {
    echo html_writer::link(
        new moodle_url($url, ['cred' => 0]),
        get_string('addcredential', $p),
        ['class' => 'btn btn-secondary mb-3']
    );
}

echo $OUTPUT->heading(get_string('qualifiedcourses', $p), 3);
echo $courses ? html_writer::alist($courses) : html_writer::tag('p', get_string('none'));
if ($manage) {
    $qualify->display();
    if ($courses) {
        $unqualify->display();
    }
}
$removed = faculty::removed_qualifications($id);
if ($removed) {
    $items = [];
    foreach ($removed as $r) {
        $by = $r->removedby ? core_user::get_user($r->removedby) : null;
        $items[] = s($r->code) . ' ' . format_string($r->title) . ': ' . get_string('removedonby', $p, (object)[
            'date' => catalog::show_date((int)$r->timeremoved), 'who' => $by ? s(fullname($by)) : '-']);
    }
    echo html_writer::tag('details', html_writer::tag('summary', get_string('removedqualified', $p, count($removed))) .
        html_writer::alist($items), ['class' => 'mb-3']);
}

// Advisees are students, so only those who may see every student get this list.
if (access::sees_all_students()) {
    echo $OUTPUT->heading(get_string('advisees', $p), 3);
    $advisees = faculty::advisees($id);
    echo $advisees ? html_writer::alist(array_map(fn($s) => html_writer::link(new moodle_url(
        '/local/studentrecords/student.php',
        ['id' => $s->id]
    ), s(students::display_name($s, access::hide_directory($s)))), $advisees)) : html_writer::tag('p', get_string('none'));
}

if ($manage) {
    echo $OUTPUT->heading(get_string('changehistory', $p), 3);
    $table = new html_table();
    $table->head = [get_string('date'), get_string('changedby', $p), get_string('field', $p), get_string('oldvalue', $p),
        get_string('newvalue', $p), get_string('source', $p)];
    foreach (records::get_history(faculty::TABLE, $id) as $h) {
        $table->data[] = history_view::row($h);
    }
    echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('none'));
}
echo $OUTPUT->footer();
