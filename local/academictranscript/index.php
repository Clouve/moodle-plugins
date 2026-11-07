<?php

/**
 * A student's transcript: view, Unofficial download, Official issue (staff), and My transcript, where students see their
 * own transcript and request an Official one.
 *
 * Whoever has staff tools here has them on every student's page, their own included. On their own page they also have My
 * transcript (view=mine), and tabs show which of the two they are in. Without staff tools, My transcript is the whole page.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\access;
use local_academictranscript\local\html_view;
use local_academictranscript\local\logger;
use local_academictranscript\local\pdf\builder;
use local_academictranscript\local\request_view;
use local_academictranscript\local\requests;
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
    $holds = requests::holds($userid);
    if ($holds) {
        redirect(
            $url,
            get_string('issueonhold', 'local_academictranscript', s(implode('; ', $holds))),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $issue = requests::issue_direct($userid, $recipient, $repeated);
    $issuedurl = new moodle_url($url, ['issued' => $issue->id]);
    $numbers = ['no' => $issue->transcriptno, 'code' => transcript::format_code($issue->verifycode)];
    if ($repeated) {
        \core\notification::info(get_string('issuedrepeat', 'local_academictranscript', $numbers));
        redirect($issuedurl);
    }
    redirect(
        $issuedurl,
        get_string('issuedok', 'local_academictranscript', $numbers),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$own = (int)$userid === (int)$USER->id;
$at = 'local_academictranscript';
$twoviews = $own && ($canissue || access::is_admin());
$mine = $own && (!$twoviews || optional_param('view', '', PARAM_ALPHA) === 'mine');
// The Student Records menu marks My Transcript by this view in the page address.
$myurl = new moodle_url($url, ['view' => 'mine']);
$PAGE->set_url($mine ? $myurl : $url);
// A student's own transcript has no profile header, so it uses the system context. Staff see the student's user context.
$PAGE->set_context($mine ? context_system::instance() : context_user::instance($userid));
$PAGE->set_pagelayout('report');
$title = get_string('studenttranscript', 'local_academictranscript');
$PAGE->set_title($title . ': ' . fullname($user));
$PAGE->set_heading(fullname($user));

if ($own && $action === 'request') {
    require_sesskey();
    $request = ['delivery' => optional_param('delivery', 'email', PARAM_ALPHA),
        'recipient' => optional_param('recipient', '', PARAM_TEXT), 'email' => optional_param('email', '', PARAM_RAW_TRIMMED),
        'address' => optional_param('address', '', PARAM_TEXT), 'timing' => optional_param('timing', 'now', PARAM_ALPHA)];
    try {
        $requestid = requests::create($userid, $request, $repeated);
    } catch (moodle_exception $e) {
        redirect($myurl, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
    if ($repeated) {
        \core\notification::info(get_string('req_saved_repeat', $at));
        redirect($myurl);
    }
    $charged = $DB->get_field(requests::TABLE, 'charged', ['id' => $requestid]);
    redirect(
        $myurl,
        get_string($charged ? 'req_saved' : 'req_saved_nocharge', 'local_academictranscript'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$data = transcript::build($userid);
logger::log('view', $userid);

$viewtabs = fn(string $current) => $twoviews ? $OUTPUT->tabtree([new tabobject('staff', $url, get_string('view_staff', $at)),
    new tabobject('mine', $myurl, get_string('mytranscript_title', $at))], $current) : '';

if ($mine) {
    $PAGE->set_pagelayout('standard');
    $PAGE->set_heading(get_string('mytranscript_title', $at));
    $PAGE->navbar->ignore_active();
    $srshell = class_exists('\\local_studentrecords\\local\\shell');
    if ($srshell) {
        $PAGE->navbar->add(get_string('pluginname', 'local_studentrecords'), new moodle_url('/local/studentrecords/index.php'));
    }
    $PAGE->navbar->add(get_string('mytranscript_title', $at), $myurl);
    echo $OUTPUT->header();
    if ($srshell) {
        echo \local_studentrecords\local\shell::inline_menu();
    }
    echo $viewtabs('mine');
    $left = html_writer::div(html_view::transcript($data), 'sr-card at-preview', ['tabindex' => 0, 'role' => 'region',
        'aria-label' => get_string('mytranscript_preview', $at)]);
    $left .= html_writer::div(html_writer::link(
        new moodle_url($url, ['action' => 'unofficial']),
        get_string('downloadunofficial', $at),
        ['class' => 'btn btn-outline-secondary']
    ), 'sr-actions');
    $right = request_view::form($userid, $url) . request_view::mine($userid);
    echo html_writer::div(html_writer::div($left, 'sr-col-main') . html_writer::div($right, 'sr-col-side'), 'sr-columns');
    $PAGE->requires->js_amd_inline(request_view::script());
    echo $OUTPUT->footer();
    return;
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title . ': ' . fullname($user));
echo $viewtabs('staff');
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
    $holds = requests::holds($userid);
    if ($own) {
        echo html_writer::div(get_string('issueown', 'local_academictranscript'), 'text-muted mb-3');
    } else if ($holds) {
        echo html_writer::div(get_string('issueonhold', 'local_academictranscript', s(implode('; ', $holds))), 'text-muted mb-3');
    } else if ($data['problems']) {
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
echo html_view::scale_table(transcript::key_rows($data));
echo $OUTPUT->footer();
