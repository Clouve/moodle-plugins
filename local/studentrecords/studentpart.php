<?php

/**
 * Edit one admissions part of a student record: decision, checklist, prior education, emergency contact,
 * demographics (records staff).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\admissions;
use local_studentrecords\local\audit;
use local_studentrecords\local\records;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$id = required_param('id', PARAM_INT);
$part = required_param('part', PARAM_ALPHA);
$item = optional_param('item', 0, PARAM_INT);
$p = 'local_studentrecords';
$parts = ['decision' => 'recorddecision', 'checklist' => 'updatechecklist', 'educ' => 'prioreducation',
    'emerg' => 'emergencycontacts', 'demog' => 'demographics', 'hold' => 'placehold', 'release' => 'releasehold',
    'xfer' => 'transfercredit', 'gradapp' => 'applygraduation', 'relauth' => 'addrelease', 'revoke' => 'revoke',
    'ferpapaper' => 'recordpaperack'];
if (!isset($parts[$part])) {
    throw new moodle_exception('invalidparameter', 'debug');
}
require_login(null, false);
$syscontext = context_system::instance();
require_capability('local/studentrecords:managestudents', $syscontext);
if ($part === 'demog') {
    require_capability('local/studentrecords:viewdemographics', $syscontext);
}
$student = students::get($id);
$url = new moodle_url('/local/studentrecords/studentpart.php', array_filter(['id' => $id, 'part' => $part, 'item' => $item]));
$back = new moodle_url('/local/studentrecords/student.php', ['id' => $id]);
staffarea::start_page($url, 'students');
$PAGE->navbar->add(get_string('studentrecords', $p), new moodle_url('/local/studentrecords/students.php'));
$PAGE->navbar->add(students::display_name($student), $back);
$PAGE->navbar->add(get_string($parts[$part], $p), $url);
$userid = (int)$student->userid;
$params = ['id' => $id, 'part' => $part, 'item' => $item];

if ($part === 'decision') {
    $form = new \local_studentrecords\form\decision($url);
    $form->set_data($params + ['decisiondate' => time(), 'termid' => (int)$student->admittermid]);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        admissions::record_decision($student, (array)$data);
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
} else if ($part === 'checklist') {
    $docs = admissions::checklist($student);
    $form = new \local_studentrecords\form\checklist($url, ['docs' => $docs]);
    $current = $params;
    foreach ($docs as $d) {
        $current['state_' . $d->id] = $d->state;
        $current['date_' . $d->id] = $d->statedate ?: time();
        $current['note_' . $d->id] = $d->note;
    }
    $form->set_data($current);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        $items = [];
        foreach ($docs as $d) {
            $items[$d->id] = ['state' => $data->{'state_' . $d->id}, 'statedate' => (int)$data->{'date_' . $d->id},
                'note' => $data->{'note_' . $d->id}];
        }
        admissions::save_checklist($student, $items);
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
} else if ($part === 'relauth' || $part === 'ferpapaper') {
    $form = $part === 'relauth' ? new \local_studentrecords\form\relauth($url) : new \local_studentrecords\form\ferpapaper($url);
    $form->set_data($params + ['fromdate' => time(), 'acknowledged' => time()]);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        if ($part === 'relauth') {
            \local_studentrecords\local\ferpa::add_release($student, (array)$data);
        } else {
            \local_studentrecords\local\ferpa::acknowledge($student, 'paper', (int)$data->acknowledged);
        }
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
} else if ($part === 'revoke') {
    $rel = $DB->get_record('local_studentrecords_relauth', ['id' => $item, 'studentid' => $id], '*', MUST_EXIST);
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        \local_studentrecords\local\ferpa::revoke_release($item);
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->confirm(get_string('revokeconfirm', $p, s($rel->recipient)), new moodle_url($url, ['confirm' => 1,
        'sesskey' => sesskey()]), $back);
    echo $OUTPUT->footer();
    die();
} else if ($part === 'gradapp') {
    $form = new \local_studentrecords\form\gradapp($url);
    $form->set_data($params);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        try {
            \local_studentrecords\local\graduation::apply($student, (int)$data->termid, (string)$data->note);
            redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($back, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
} else if ($part === 'hold') {
    $form = new \local_studentrecords\form\hold($url);
    $form->set_data($params + ['startdate' => time()]);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        \local_studentrecords\local\academic::place_hold($student, (array)$data);
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
} else if ($part === 'release') {
    $hold = $DB->get_record('local_studentrecords_hold', ['id' => $item, 'studentid' => $id], '*', MUST_EXIST);
    $form = new \local_studentrecords\form\holdrelease($url, ['text' => get_string('holdtype_' . $hold->holdtype, $p) . ': ' .
        s((string)$hold->reason)]);
    $form->set_data($params);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        \local_studentrecords\local\academic::release_hold($item, $data->releasenote);
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
} else if ($part === 'xfer') {
    $form = new \local_studentrecords\form\xfer($url, ['item' => $item]);
    $row = $item ? $DB->get_record('local_studentrecords_xfer', ['id' => $item, 'studentid' => $id], '*', MUST_EXIST) : null;
    if ($row) {
        $row->credits = format_float($row->credits, -1);
        $row->equivcourseid = (int)$row->equivcourseid;
    }
    // The page's own id, part and item come first: a row has an id of its own, and the form posts it back as the student.
    $form->set_data($params + ($row ? (array)$row : []) + ['active' => 1]);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        $record = ['studentid' => $id, 'institution' => trim($data->institution), 'extcourse' => trim((string)$data->extcourse),
            'credits' => (float)$data->credits, 'grade' => trim((string)$data->grade) ?: null,
            'equivcourseid' => empty($data->equivcourseid) ? null : (int)$data->equivcourseid,
            'countsprogram' => (int)$data->countsprogram, 'ingpa' => (int)$data->ingpa,
            'awarded' => empty($data->awarded) ? null : (int)$data->awarded, 'note' => trim((string)$data->note),
            'active' => $row ? (int)$data->active : 1];
        if ($row) {
            $record['id'] = $row->id;
        }
        records::save('local_studentrecords_xfer', $record, 'form', $userid);
        audit::log($row ? 'update' : 'create', 'student', $id, $userid, 'transfer credit');
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
} else if ($part === 'educ' || $part === 'emerg') {
    $table = 'local_studentrecords_' . $part;
    $class = '\\local_studentrecords\\form\\' . $part;
    $form = new $class($url, ['item' => $item]);
    $row = $item ? $DB->get_record($table, ['id' => $item, 'studentid' => $id], '*', MUST_EXIST) : null;
    $form->set_data($params + ($row ? (array)$row : []) + ['active' => 1]);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        $record = array_diff_key((array)$data, ['part' => 1, 'item' => 1, 'submitbutton' => 1]);
        $record['studentid'] = $id;
        if ($part === 'educ') {
            $record['completed'] = empty($record['completed']) ? null : $record['completed'];
            $record['country'] = $record['country'] ?: null;
        }
        $record['active'] = $row ? (int)$data->active : 1;
        if ($row) {
            $record['id'] = $row->id;
        } else {
            unset($record['id']);
            if ($part === 'emerg') {
                $record['sortorder'] = $DB->count_records($table, ['studentid' => $id]) + 1;
            }
        }
        records::save($table, $record, 'form', $userid);
        audit::log($row ? 'update' : 'create', 'student', $id, $userid, $part);
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
} else {
    $form = new \local_studentrecords\form\demog($url);
    $row = $DB->get_record('local_studentrecords_demog', ['studentid' => $id]);
    $current = $params + ['sex' => $row->sex ?? '', 'hispanic' => $row->hispanic ?? ''];
    foreach (array_filter(explode(',', (string)($row->races ?? ''))) as $code) {
        $current['race_' . $code] = 1;
    }
    $form->set_data($current);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($data = $form->get_data()) {
        $races = array_values(array_filter(admissions::RACES, fn($r) => !empty($data->{'race_' . $r})));
        $record = ['studentid' => $id, 'sex' => $data->sex ?: null, 'hispanic' => $data->hispanic ?: null,
            'races' => $races ? implode(',', $races) : (!empty($data->race_notreported) ? 'notreported' : null)];
        if ($row) {
            $record['id'] = $row->id;
        }
        records::save('local_studentrecords_demog', $record, 'form', $userid);
        audit::log('update', 'student', $id, $userid, 'demographics');
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo staffarea::header();
echo $OUTPUT->heading(s(students::display_name($student)) . ': ' . get_string($parts[$part], $p));
if ($part === 'checklist' && empty($docs)) {
    echo $OUTPUT->notification(get_string('nochecklist', $p), 'info');
}
$form->display();
echo $OUTPUT->footer();
