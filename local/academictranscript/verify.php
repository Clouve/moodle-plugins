<?php

/**
 * Public verification of an Official transcript by its code.
 *
 * Deliberately no require_login(): anyone holding a code (for example an employer or another school) can confirm what
 * was issued. Only who, what, when and to whom are shown, from the frozen snapshot. No grades and no date of birth.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing
require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\logger;
use local_academictranscript\local\transcript;

$input = optional_param('code', '', PARAM_TEXT);
$code = transcript::clean_code($input);
$at = 'local_academictranscript';

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/academictranscript/verify.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('verifytitle', $at));
$PAGE->set_heading(get_string('verifytitle', $at));

echo $OUTPUT->header();
echo html_writer::start_div('at-verify');
echo html_writer::tag('p', get_string('verify_intro', $at, s(transcript::school_name())), ['class' => 'sr-lead']);
$form = html_writer::label(get_string('verifycode', $at), 'atcode', false, ['class' => 'd-block small']);
$form .= html_writer::div(html_writer::empty_tag('input', ['type' => 'text', 'name' => 'code', 'value' => $input, 'id' => 'atcode',
    'class' => 'form-control' . ($input !== '' && !$code ? ' is-invalid' : ''), 'autocomplete' => 'off',
    'spellcheck' => 'false', 'placeholder' => 'XXXX-XXXX-XXXX-XXXX-XXXX-XXXX']) . html_writer::tag(
        'button',
        get_string('verify_check', $at),
        ['type' => 'submit', 'class' => 'btn btn-primary']
    ), 'sr-search');
echo html_writer::tag('form', $form, ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring(), 'role' => 'search']);

if ($input !== '') {
    $issue = strlen($code) === 24 ? $DB->get_record('local_academictranscript_issue', ['verifycode' => $code]) : false;
    if (!$issue) {
        logger::log('verify_fail', 0, 0, \core_text::substr($code, 0, 40));
        echo html_writer::div(html_writer::tag('strong', get_string('verify_notfound', $at)) . ' ' .
            get_string('verify_notfoundhelp', $at), 'sr-note sr-note-bad', ['role' => 'alert']);
    } else {
        logger::log('verify_ok', (int)$issue->userid, (int)$issue->id);
        $data = json_decode($issue->snapshot, true);
        $rows = [
            get_string('verify_student', $at) => s($data['fullname'] ?? ''),
            get_string('verify_type', $at) => get_string('verify_official', $at),
            get_string('verify_issued', $at) => s(transcript::date((int)$issue->timecreated)) . (!empty($issue->transcriptno) ?
                ' · ' . get_string('transcriptno', $at) . ' ' . s((string)$issue->transcriptno) : ''),
        ];
        $degree = trim((string)($data['qualification'] ?? ''));
        if ($degree !== '') {
            $rows[get_string('verify_degree', $at)] = s($degree) . (!empty($data['awarddate']) ? ', ' .
                get_string('verify_awarded', $at, s(transcript::date((int)$data['awarddate']))) : '');
        } else if (!empty($data['program'])) {
            $rows[get_string('verify_program', $at)] = s($data['program']);
        }
        if (!empty($issue->recipient)) {
            $rows[get_string('verify_sentto', $at)] = s((string)$issue->recipient);
        }
        $facts = '';
        foreach ($rows as $label => $value) {
            $facts .= html_writer::tag('dt', s($label)) . html_writer::tag('dd', $value);
        }
        echo html_writer::div(
            html_writer::div(html_writer::span('✓', '', ['aria-hidden' => 'true']) . ' ' .
            get_string('verify_valid', $at), 'at-valid-head') . html_writer::div(html_writer::tag(
                'dl',
                $facts,
                ['class' => 'sr-facts']
            ) .
            html_writer::tag('p', get_string('verify_privacy', $at), ['class' => 'small mb-0']), 'at-valid-body'),
            'at-valid',
            ['role' => 'status']
        );
    }
}
$contact = trim(transcript::setting('email') . ' ' . transcript::setting('phone'));
if ($contact !== '') {
    echo html_writer::tag('p', get_string('verify_contact', $at, s($contact)), ['class' => 'sr-muted mt-3']);
}
echo html_writer::end_div();
echo $OUTPUT->footer();
