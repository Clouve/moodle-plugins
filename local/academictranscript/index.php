<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * A student's transcript: view, Unofficial download, Official issue (staff).
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\access;
use local_academictranscript\local\html_view;
use local_academictranscript\local\logger;
use local_academictranscript\local\pdf\builder;
use local_academictranscript\local\transcript;

require_login(null, false);
if (isguestuser()) {
    throw new moodle_exception('noguest');
}

$userid = optional_param('userid', $USER->id, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$user = core_user::get_user($userid, '*', MUST_EXIST);

if (!access::can_view($userid)) {
    throw new required_capability_exception(
        context_system::instance(),
        'local/academictranscript:issueofficial',
        'nopermissions',
        ''
    );
}
$canissue = access::can_issue($userid);
$url = new moodle_url('/local/academictranscript/index.php', ['userid' => $userid]);

if ($action === 'unofficial') {
    $data = transcript::build($userid);
    $pdf = builder::render($data, null);
    logger::log('unofficial', $userid);
    send_file($pdf, builder::filename($data, false), 0, 0, true, true, 'application/pdf');
    die();
}
if ($action === 'issue') {
    require_sesskey();
    if (!$canissue) {
        throw new required_capability_exception(
            context_system::instance(),
            'local/academictranscript:issueofficial',
            'nopermissions',
            ''
        );
    }
    $recipient = trim(required_param('recipient', PARAM_TEXT));
    if ($recipient === '') {
        redirect($url, get_string('recipientrequired', 'local_academictranscript'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $issue = transcript::issue($userid, $recipient);
    redirect(
        new moodle_url($url, ['issued' => $issue->id]),
        get_string('issuedok', 'local_academictranscript', ['no' => $issue->transcriptno,
            'code' => transcript::format_code($issue->verifycode)]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_pagelayout('report');
$title = get_string('studenttranscript', 'local_academictranscript');
$PAGE->set_title($title . ': ' . fullname($user));
$PAGE->set_heading(fullname($user));

$data = transcript::build($userid);
logger::log('view', $userid);

echo $OUTPUT->header();
echo $OUTPUT->heading($title . ': ' . fullname($user));
if (
    !get_config('local_academictranscript', 'setupcomplete') && has_capability(
        'local/academictranscript:configure',
        context_system::instance()
    )
) {
    echo $OUTPUT->notification(get_string(
        'setupnotice',
        'local_academictranscript',
        (new moodle_url('/local/academictranscript/setup.php'))->out()
    ), 'warning');
}

$issuedid = optional_param('issued', 0, PARAM_INT);
if ($issuedid && $canissue) {
    echo $OUTPUT->notification(html_writer::link(
        new moodle_url('/local/academictranscript/issued.php', ['id' => $issuedid]),
        get_string('downloadissued', 'local_academictranscript')
    ), 'success');
}
if ($canissue && $data['problems']) {
    echo $OUTPUT->notification(get_string('problemsheading', 'local_academictranscript')
        . html_writer::alist(array_map('s', $data['problems'])), 'warning');
}

echo html_view::transcript($data);

// Actions.
echo html_writer::start_div('d-flex flex-wrap gap-2 my-3 align-items-start');
echo $OUTPUT->single_button(
    new moodle_url($url, ['action' => 'unofficial']),
    get_string('downloadunofficial', 'local_academictranscript'),
    'get'
);
if (access::is_admin()) {
    $adminpages = ['record.php' => 'editdetails', 'transfer.php' => 'transfercredits', 'studentterms.php' => 'courseterms'];
    foreach ($adminpages as $page => $key) {
        echo $OUTPUT->single_button(
            new moodle_url('/local/academictranscript/' . $page, ['userid' => $userid]),
            get_string($key, 'local_academictranscript'),
            'get'
        );
    }
}
echo html_writer::end_div();

if ($canissue) {
    echo $OUTPUT->heading(get_string('issueofficial', 'local_academictranscript'), 4);
    if ($data['problems']) {
        echo html_writer::div(get_string('cannotissueyet', 'local_academictranscript'), 'text-muted mb-3');
    } else {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'mb-3',
            'onsubmit' => 'return confirm(' . json_encode(get_string('issueconfirm', 'local_academictranscript', fullname($user)))
                . ');']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'issue']);
        echo html_writer::tag('label', get_string('issuedto', 'local_academictranscript') . ' *', ['for' => 'recipient',
            'class' => 'me-2']);
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'recipient', 'id' => 'recipient', 'required' => 'required',
            'maxlength' => 255, 'size' => 40, 'class' => 'form-control d-inline-block w-auto me-2',
            'placeholder' => get_string('recipientplaceholder', 'local_academictranscript')]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
            'value' => get_string('issueofficial', 'local_academictranscript')]);
        echo html_writer::end_tag('form');
    }

    $issues = $DB->get_records(
        'local_academictranscript_issue',
        ['userid' => $userid],
        'timecreated DESC',
        'id, verifycode, issuedby, timecreated, recipient, transcriptno'
    );
    if ($issues) {
        echo $OUTPUT->heading(get_string('issuedforstudent', 'local_academictranscript'), 4);
        $table = new html_table();
        $table->head = [get_string('dateissuedcol', 'local_academictranscript'),
            get_string('transcriptno', 'local_academictranscript'),
            get_string('issuedto', 'local_academictranscript'), get_string('issuedby', 'local_academictranscript'),
            get_string('verifycode', 'local_academictranscript'), ''];
        foreach ($issues as $issue) {
            $table->data[] = [userdate($issue->timecreated), s((string)$issue->transcriptno), s((string)$issue->recipient),
                fullname(core_user::get_user($issue->issuedby)), transcript::format_code($issue->verifycode),
                html_writer::link(
                    new moodle_url('/local/academictranscript/issued.php', ['id' => $issue->id]),
                    get_string('download')
                )];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->heading(get_string('transcriptkey', 'local_academictranscript'), 4);
echo html_view::scale_table();
echo $OUTPUT->footer();
