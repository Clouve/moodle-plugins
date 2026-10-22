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
 * Public verification of an Official transcript by its code.
 *
 * Deliberately no require_login(): anyone holding a code (for example an employer or another
 * school) can confirm what was issued. Only the frozen snapshot is shown; no date of birth.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing
require_once(__DIR__ . '/../../config.php');

use local_academictranscript\local\html_view;
use local_academictranscript\local\logger;
use local_academictranscript\local\transcript;

$input = optional_param('code', '', PARAM_TEXT);
$code = transcript::clean_code($input);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/academictranscript/verify.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('verifytitle', 'local_academictranscript'));
$PAGE->set_heading(get_string('verifytitle', 'local_academictranscript'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('verifytitle', 'local_academictranscript'));
echo html_writer::tag('p', get_string('verifyintro', 'local_academictranscript'));

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring(),
    'class' => 'form-inline d-flex gap-2 mb-4']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'code', 'value' => $input, 'size' => 34,
    'class' => 'form-control', 'aria-label' => get_string('verifycode', 'local_academictranscript'),
    'placeholder' => 'XXXX-XXXX-XXXX-XXXX-XXXX-XXXX']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
    'value' => get_string('verify', 'local_academictranscript')]);
echo html_writer::end_tag('form');

if ($input !== '') {
    $issue = strlen($code) === 24 ? $DB->get_record('local_academictranscript_issue', ['verifycode' => $code]) : false;
    if (!$issue) {
        logger::log('verify_fail', 0, 0, \core_text::substr($code, 0, 40));
        echo $OUTPUT->notification(get_string('verifynotfound', 'local_academictranscript'), 'error');
    } else {
        logger::log('verify_ok', (int)$issue->userid, (int)$issue->id);
        $data = json_decode($issue->snapshot, true);
        $a = ['name' => s($data['fullname']), 'date' => transcript::date((int)$issue->timecreated),
            'no' => s((string)$issue->transcriptno), 'to' => s((string)$issue->recipient)];
        echo $OUTPUT->notification(get_string('verifyfound', 'local_academictranscript', $a), 'success');
        echo html_view::transcript($data, false);
    }
}
echo $OUTPUT->footer();
