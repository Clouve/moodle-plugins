<?php

/**
 * Graduation: applications by state; review (with the degree audit), confer, cancel.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\degreeaudit;
use local_studentrecords\local\graduation;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

$appid = optional_param('app', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$state = optional_param('state', 'applied', PARAM_ALPHA);
$state = in_array($state, array_merge(graduation::STATES, ['all']), true) ? $state : 'applied';
$p = 'local_studentrecords';
require_login(null, false);
staffarea::setup('graduation', new moodle_url('/local/studentrecords/graduation.php', array_filter(['state' => $state])));
$url = new moodle_url('/local/studentrecords/graduation.php');

if ($appid && in_array($action, ['review', 'confer', 'cancel'], true)) {
    $app = $DB->get_record(graduation::TABLE, ['id' => $appid], '*', MUST_EXIST);
    $student = students::get((int)$app->studentid);
    $programs = catalog::programs(false);
    $text = s(students::display_name($student) . ' (' . $student->studentid . '), ') . ($programs[$app->programid] ?? '');
    $audit = $action === 'review' ? degreeaudit::run($student) : null;
    if ($audit) {
        $text .= html_writer::div(get_string($audit['complete'] ? 'audit_complete' : 'audit_incomplete', $p) . ' ' .
            html_writer::link(
                new moodle_url('/local/studentrecords/degreeaudit.php', ['id' => $student->id]),
                get_string('degreeaudit', $p)
            ), $audit['complete'] ? 'text-success' : 'text-danger');
    }
    $form = new \local_studentrecords\form\gradreview(
        new moodle_url($url, ['app' => $appid, 'action' => $action]),
        ['action' => $action, 'text' => $text]
    );
    $credential = (string)$DB->get_field('local_studentrecords_program', 'credential', ['id' => $app->programid]);
    $gpa = \local_studentrecords\local\registration::history($student)['cumulative']['gpa'];
    $form->set_data(['app' => $appid, 'action' => $action, 'conferred' => time(), 'credential' => $credential,
        'honors' => graduation::honors_for(graduation::parse_honors(catalog::setting('honors')), $gpa)]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    $error = '';
    if ($data = $form->get_data()) {
        try {
            if ($action === 'review') {
                graduation::review($appid, $data->decision === 'approve', (string)$data->note);
            } else if ($action === 'confer') {
                graduation::confer($appid, (int)$data->conferred, $data->credential, (string)$data->honors);
            } else {
                graduation::cancel($appid, $data->note);
            }
            redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
    }
    echo staffarea::header();
    echo $OUTPUT->heading(get_string('gradaction_' . $action, $p));
    if ($error !== '') {
        echo $OUTPUT->notification(s($error), 'error');
    }
    $form->display();
    echo $OUTPUT->footer();
    die();
}

echo staffarea::header();
echo html_writer::tag('p', get_string('graduation_intro', $p));
$tabs = [];
foreach (array_merge(graduation::STATES, ['all']) as $s) {
    $tabs[] = new tabobject($s, new moodle_url($url, ['state' => $s]), get_string('gradstate_' . $s, $p));
}
echo $OUTPUT->tabtree($tabs, $state);
$where = $state === 'all' ? '' : 'WHERE g.state = ?';
$rows = $DB->get_records_sql("SELECT g.*, s.studentid AS sid, s.legalfirstname, s.legalmiddlename, s.legallastname,
                                     s.preferredname
                                FROM {" . graduation::TABLE . "} g
                                JOIN {" . students::TABLE . "} s ON s.id = g.studentid
                                $where
                            ORDER BY g.timeapplied DESC", $state === 'all' ? [] : [$state]);
$programs = catalog::programs(false);
$terms = array_map('format_string', catalog::terms());
$table = new html_table();
$table->head = [get_string('date'), get_string('studentid', $p), get_string('name'), get_string('program', $p),
    get_string('gradterm', $p), get_string('gradstate', $p), get_string('audit_result', $p), get_string('award', $p), ''];
foreach ($rows as $r) {
    $actions = [];
    $link = function ($a) use ($url, $r, $p) {
        return html_writer::link(new moodle_url($url, ['app' => $r->id, 'action' => $a]), get_string('gradaction_' . $a, $p));
    };
    if ($r->state === 'applied') {
        $actions[] = $link('review');
    }
    if ($r->state === 'approved') {
        $actions[] = $link('confer');
    }
    if (in_array($r->state, ['applied', 'approved'], true)) {
        $actions[] = $link('cancel');
    }
    $table->data[] = [catalog::show_date((int)$r->timeapplied), s($r->sid), html_writer::link(new moodle_url(
        '/local/studentrecords/student.php',
        ['id' => $r->studentid]
    ), s(students::display_name($r))), $programs[$r->programid] ?? '', $r->termid ? ($terms[$r->termid] ?? '') : '',
        get_string('gradstate_' . $r->state, $p), $r->auditresult ? get_string('audit_' . $r->auditresult, $p) : '',
        $r->conferred ? s(trim($r->credential . ($r->honors ? ', ' . $r->honors : ''))) . ' (' .
        catalog::show_date((int)$r->conferred) . ')' : '', implode(' | ', $actions)];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nogradapps', $p), 'info');
echo $OUTPUT->footer();
