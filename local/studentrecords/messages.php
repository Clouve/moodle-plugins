<?php

/**
 * Automatic messages (setup staff): switch each message on or off, change its text (reset to default), test mode, and
 * the message log (every message, sent or skipped).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\messages;
use local_studentrecords\local\records;
use local_studentrecords\local\staffarea;

require_login(null, false);
$tab = optional_param('tab', 'list', PARAM_ALPHA);
$tab = $tab === 'log' ? 'log' : 'list';
$key = optional_param('key', '', PARAM_ALPHA);
$key = in_array($key, messages::keys(), true) ? $key : '';
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/messages.php');
staffarea::setup('messages', new moodle_url($url, ['tab' => $tab]));
$audit = fn($info) => \local_studentrecords\local\audit::log('update', 'setup', null, null, $info);

if (optional_param('reset', '', PARAM_ALPHA) === 'text' && $key) {
    require_sesskey();
    records::set_setting('msgsubject_' . $key, '');
    records::set_setting('msgbody_' . $key, '');
    $audit('message text reset ' . $key);
    redirect($url, get_string('msgresetdone', $p), null, \core\output\notification::NOTIFY_SUCCESS);
}
if (optional_param('sendtest', 0, PARAM_BOOL) && $key) {
    require_sesskey();
    $sent = messages::send_test($key);
    $audit('message test ' . $key);
    redirect(
        new moodle_url($url, ['key' => $key]),
        get_string($sent ? 'msg_testsent' : 'msg_testfailed', $p),
        null,
        $sent ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}
if (optional_param('saveinbox', 0, PARAM_BOOL)) {
    require_sesskey();
    $inbox = trim(optional_param('testinbox', '', PARAM_RAW_TRIMMED));
    if ($inbox !== '' && !validate_email($inbox)) {
        redirect($url, get_string('msg_badinbox', $p), null, \core\output\notification::NOTIFY_ERROR);
    }
    records::set_setting('msgtestinbox', $inbox);
    $audit('message test inbox ' . ($inbox !== '' ? 'set' : 'cleared'));
    redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
}
if (optional_param('testmode', -1, PARAM_INT) >= 0) {
    require_sesskey();
    records::set_setting('msgtestmode', optional_param('testmode', 0, PARAM_INT) ? '1' : '0');
    $audit('message test mode ' . catalog::setting('msgtestmode'));
    redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($key) {
    $default = fn($part) => get_string('msgdef_' . $key . '_' . $part, $p);
    $form = new \local_studentrecords\form\msgtext(new moodle_url($url, ['key' => $key]), ['defaultsubject' => $default('subject'),
        'defaultbody' => $default('body')]);
    $form->set_data(['key' => $key, 'on' => messages::is_on($key) ? 1 : 0, 'subject' => messages::text($key, 'subject'),
        'body' => messages::text($key, 'body')]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        records::set_setting('msgon_' . $key, $data->on ? '1' : '0');
        // Text equal to the default is not kept as the school's own text, so a later better default still applies.
        records::set_setting('msgsubject_' . $key, trim($data->subject) === $default('subject') ? '' : trim($data->subject));
        records::set_setting('msgbody_' . $key, trim($data->body) === $default('body') ? '' : trim($data->body));
        $audit('message ' . $key);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->heading(get_string('msgname_' . $key, $p));
    echo html_writer::tag('p', get_string('msg_fillins', $p, implode(' ', array_map(
        fn($n) => '{' . $n . '}',
        messages::PLACEHOLDERS
    ))), ['class' => 'sr-muted']);
    $form->display();
    $inbox = messages::test_inbox();
    echo html_writer::tag(
        'form',
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'key', 'value' => $key]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sendtest', 'value' => 1]) .
        html_writer::tag('button', get_string('msg_sendtest', $p), ['type' => 'submit', 'class' => 'btn btn-outline-secondary']) .
        html_writer::span(get_string(
            messages::test_mode() && $inbox !== '' ? 'msg_sendtest_inbox' : 'msg_sendtest_me',
            $p,
            s(messages::test_mode() && $inbox !== '' ? $inbox : $USER->email)
        ), 'sr-muted small ms-2'),
        ['method' => 'post', 'action' => $url->out_omit_querystring(), 'class' => 'mt-3']
    );
    echo $OUTPUT->footer();
    return;
}

echo staffarea::header();
$tabs = [new tabobject('list', new moodle_url($url, ['tab' => 'list']), get_string('automessages', $p)),
    new tabobject('log', new moodle_url($url, ['tab' => 'log']), get_string('msglog', $p))];
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'log') {
    $status = optional_param('status', '', PARAM_ALPHA);
    $table = new html_table();
    $table->head = [get_string('date'), get_string('msgmessage', $p), get_string('msgto', $p), get_string('studentid', $p),
        get_string('msgsubject', $p), get_string('status', $p)];
    foreach (messages::recent(300, $status) as $l) {
        $table->data[] = [userdate($l->timecreated, get_string('strftimedatetimeshort', 'langconfig'), catalog::timezone()),
            get_string('msgname_' . $l->msgkey, $p), $l->userid ? s($l->firstname . ' ' . $l->lastname) . ' (' . s($l->email) .
            ')' : '-', s((string)$l->studentno), s((string)$l->subject), get_string('msgstatus_' . $l->status, $p)];
    }
    echo html_writer::tag('p', get_string('msglog_intro', $p));
    echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('nothingfound', $p));
    echo $OUTPUT->footer();
    return;
}

echo html_writer::tag('p', get_string('automessages_intro', $p));
$testmode = messages::test_mode();
$inbox = messages::test_inbox();
echo $OUTPUT->notification(get_string(
    $testmode ? ($inbox !== '' ? 'msgtestmode_oninbox' : 'msgtestmode_on') : 'msgtestmode_off',
    $p,
    s($inbox)
), $testmode ? 'warning' : 'info');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out_omit_querystring(), 'class' => 'mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'testmode', 'value' => $testmode ? 0 : 1]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary',
    'value' => get_string($testmode ? 'msgtestmode_turnoff' : 'msgtestmode_turnon', $p)]);
echo html_writer::end_tag('form');
$f = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'saveinbox', 'value' => 1]) .
    html_writer::div(html_writer::label(get_string('msg_testinbox', $p), 'msginbox') . html_writer::empty_tag('input', [
    'type' => 'email', 'name' => 'testinbox', 'id' => 'msginbox', 'value' => $inbox, 'class' => 'form-control',
    'placeholder' => 'test-inbox@example.com']), 'sr-field sr-field-grow') .
    html_writer::div(html_writer::tag('button', get_string('savechanges'), ['type' => 'submit',
    'class' => 'btn btn-outline-secondary']), 'sr-field');
echo html_writer::tag('form', $f, ['method' => 'post', 'action' => $url->out_omit_querystring(), 'class' => 'sr-filter']);
echo html_writer::tag('p', get_string('msg_testinbox_help', $p), ['class' => 'sr-muted small']);
foreach (['student' => messages::STUDENT, 'staff' => messages::STAFF] as $group => $list) {
    echo $OUTPUT->heading(get_string('msggroup_' . $group, $p), 3);
    $table = new html_table();
    $table->attributes['class'] = 'sr-table';
    $table->head = [get_string('msgmessage', $p), get_string('msg_when', $p), get_string('msgon', $p), get_string('msgsubject', $p),
        get_string('msgown', $p), ''];
    foreach (array_keys($list) as $k) {
        $own = catalog::setting('msgsubject_' . $k) !== '' || catalog::setting('msgbody_' . $k) !== '';
        $links = [html_writer::link(new moodle_url($url, ['key' => $k]), get_string('edit'))];
        if ($own) {
            $links[] = html_writer::link(
                new moodle_url($url, ['key' => $k, 'reset' => 'text', 'sesskey' => sesskey()]),
                get_string('msgreset', $p)
            );
        }
        $table->data[] = [html_writer::link(new moodle_url($url, ['key' => $k]), get_string('msgname_' . $k, $p)),
            get_string('msgwhen_' . $k, $p), \local_studentrecords\local\shell::tag(messages::is_on($k) ? get_string('msg_on', $p) :
            get_string('msg_off', $p), messages::is_on($k) ? 'good' : 'neutral'),
            s(messages::text($k, 'subject')), $own ? get_string('yes') : get_string('no'), implode(' | ', $links)];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
