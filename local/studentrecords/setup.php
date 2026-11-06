<?php

/**
 * Setup wizard. Every answer can be changed later; programs, courses, grading and staff can also come from
 * the spreadsheet or CSV import (reviewed before anything is saved).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\records;

$steps = ['school', 'approvals', 'calendar', 'grading', 'programs', 'courses', 'admissions', 'billing', 'staff', 'import',
    'summary'];
$step = optional_param('step', 'school', PARAM_ALPHA);
$step = in_array($step, $steps) ? $step : 'school';

require_login(null, false);
\local_studentrecords\local\staffarea::setup('setup');
$syscontext = context_system::instance();
require_capability('local/studentrecords:configure', $syscontext);
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/setup.php', ['step' => $step]);
$PAGE->set_url($url);
$nexturl = $step === 'summary' ? $url :
    new moodle_url('/local/studentrecords/setup.php', ['step' => $steps[array_search($step, $steps) + 1]]);

$form = null;
if (in_array($step, ['school', 'approvals', 'calendar', 'admissions'])) {
    $form = new \local_studentrecords\form\school($url, ['step' => $step]);
    $current = [];
    foreach (catalog::SCHOOL as $name => [, $fieldstep]) {
        // Settings not answered yet keep the form's defaults.
        if ($fieldstep === $step && catalog::setting($name) !== '') {
            $current[$name] = catalog::setting($name);
        }
    }
    if ($step === 'school') {
        $draftid = file_get_submitted_draft_itemid('logo');
        file_prepare_draft_area($draftid, $syscontext->id, $p, 'logo', 0, ['maxfiles' => 1, 'subdirs' => 0]);
        $current['logo'] = $draftid;
    }
    $form->set_data($current);
    if ($data = $form->get_data()) {
        foreach (catalog::SCHOOL as $name => [, $fieldstep]) {
            if ($fieldstep === $step && property_exists($data, $name)) {
                records::set_setting($name, trim((string)$data->$name));
            }
        }
        if ($step === 'school') {
            file_save_draft_area_files($data->logo, $syscontext->id, $p, 'logo', 0, ['maxfiles' => 1, 'subdirs' => 0]);
        }
        \local_studentrecords\local\audit::log('update', 'setup', null, null, $step);
        redirect($nexturl);
    }
}

$index = array_search($step, $steps);
$PAGE->set_heading(get_string('step_' . $step, $p));
echo \local_studentrecords\local\staffarea::header();
$items = '';
foreach ($steps as $i => $s) {
    $label = html_writer::span($i < $index ? '✓' : (string)($i + 1), 'sr-step-no', ['aria-hidden' => 'true']) . ' ' .
        get_string('step_' . $s, $p);
    $attrs = ['class' => 'sr-wiz-step' . ($s === $step ? ' active' : ($i < $index ? ' done' : ''))];
    if ($s === $step) {
        $attrs['aria-current'] = 'step';
    }
    $items .= html_writer::tag('li', html_writer::link(
        new moodle_url('/local/studentrecords/setup.php', ['step' => $s]),
        $label,
        $attrs
    ));
}
echo html_writer::tag(
    'p',
    get_string('wiz_stepof', $p, (object)['n' => $index + 1, 'total' => count($steps)]),
    ['class' => 'sr-card-kicker mb-1']
);
echo html_writer::tag('p', get_string('step_' . $step . '_intro', $p), ['class' => 'sr-lead']);
$pct = (int)round(($index + 1) / count($steps) * 100);
echo html_writer::div(html_writer::span('', 'sr-progress-bar', ['style' => 'width:' . $pct . '%']), 'sr-progress', [
    'role' => 'progressbar', 'aria-valuenow' => $pct, 'aria-valuemin' => 0, 'aria-valuemax' => 100,
    'aria-label' => get_string('setupwizard', $p)]);
echo html_writer::tag(
    'nav',
    html_writer::tag('ol', $items, ['class' => 'sr-wiz']),
    ['aria-label' => get_string('setupwizard', $p)]
);

$counts = [
    'terms' => $DB->count_records('local_studentrecords_term'),
    'grades' => $DB->count_records('local_studentrecords_grade'),
    'levels' => $DB->count_records('local_studentrecords_level'),
    'rules' => $DB->count_records('local_studentrecords_minpass'),
    'programs' => $DB->count_records('local_studentrecords_program'),
    'courses' => $DB->count_records('local_studentrecords_course'),
    'reqdocs' => $DB->count_records('local_studentrecords_reqdoc', ['active' => 1]),
    'faculty' => $DB->count_records('local_studentrecords_faculty'),
    'students' => $DB->count_records('local_studentrecords_student'),
    'fees' => $DB->count_records('local_studentrecords_fee', ['active' => 1]),
];
// Plain links (not buttons): the button style did not open the page in Chrome.
$link = fn($page, $label) => html_writer::div(html_writer::link(
    new moodle_url('/local/studentrecords/' . $page),
    get_string($label, $p)
), 'mb-2');
$importlink = html_writer::div(get_string('orimport', $p) . ' ' .
    html_writer::link(new moodle_url('/local/studentrecords/import.php'), get_string('importtitle', $p)), 'mb-3');

if ($form) {
    if ($step === 'admissions') {
        $example = \local_studentrecords\local\admissions::auto_id() ?
            \local_studentrecords\local\admissions::next_student_id() : get_string('idmanual', $p);
        echo html_writer::div(get_string('idexample', $p, s($example)), 'mb-2');
        echo html_writer::div(get_string('countreqdocs', $p, $counts['reqdocs']), 'mb-2') .
            $link('checklist.php', 'managechecklist') . $importlink;
    }
    if ($step === 'calendar') {
        echo html_writer::div(get_string('countterms', $p, $counts['terms']), 'mb-2') . $link('terms.php', 'manageterms');
    }
    $form->display();
} else if ($step === 'grading') {
    echo html_writer::div(get_string('countgrading', $p, (object)$counts), 'mb-2');
    echo $link('grading.php', 'managegrading') . $importlink;
} else if ($step === 'programs') {
    echo html_writer::div(get_string('countprograms', $p, $counts['programs']), 'mb-2');
    echo $link('programs.php', 'manageprograms') . $importlink;
} else if ($step === 'courses') {
    echo html_writer::div(get_string('countcourses', $p, $counts['courses']), 'mb-2');
    echo $link('courses.php', 'managecourses') . $importlink;
} else if ($step === 'billing') {
    $state = \local_studentrecords\local\billing::enabled() ? 'billingison' : 'billingisoff';
    echo html_writer::div(get_string($state, $p), 'mb-2');
    echo html_writer::div(get_string('countfees', $p, $counts['fees']), 'mb-2');
    if (has_capability('local/studentrecords:managebilling', $syscontext)) {
        echo $link('billingsetup.php', 'billingsetup');
    }
} else if ($step === 'staff') {
    echo html_writer::div(get_string('countfaculty', $p, $counts['faculty']), 'mb-2');
    echo $link('faculty.php', 'managefaculty') . $link('roster.php', 'roster') . $importlink;
    echo html_writer::tag('p', get_string('staffroles', $p));
    echo html_writer::div(html_writer::link(new moodle_url('/admin/roles/manage.php'), get_string('defineroles', 'role')));
} else if ($step === 'import') {
    echo $link('import.php', 'importtitle');
} else if ($step === 'summary') {
    $table = new html_table();
    $table->head = [get_string('item', $p), get_string('count', $p)];
    foreach ($counts as $k => $n) {
        $table->data[] = [get_string('sum_' . $k, $p), $n];
    }
    foreach (['schoolname', 'stateagency', 'accreditor', 'calendartype'] as $k) {
        $v = catalog::setting($k);
        $table->data[] = [get_string('set_' . $k, $p), $v === '' ? html_writer::span(get_string('notset', $p), 'text-danger') :
            s($v)];
    }
    foreach (['fedaid', 'f1', 'billing'] as $k) {
        $table->data[] = [get_string('set_' . $k, $p), catalog::module_on($k) ? get_string('yes') : get_string('no')];
    }
    echo html_writer::table($table);
    $missing = [];
    foreach ($DB->get_records('local_studentrecords_level') as $level) {
        if (!$DB->record_exists('local_studentrecords_minpass', ['levelid' => $level->id])) {
            $missing[] = format_string($level->name);
        }
    }
    if ($missing) {
        echo $OUTPUT->notification(get_string('levelsnorule', $p, implode(', ', $missing)), 'warning');
    }
    echo html_writer::tag('p', get_string('summary_next', $p));
}
if (!$form) {
    $nav = $index > 0 ? html_writer::link(
        new moodle_url('/local/studentrecords/setup.php', ['step' => $steps[$index - 1]]),
        get_string('wiz_back', $p, get_string('step_' . $steps[$index - 1], $p)),
        ['class' => 'btn btn-outline-secondary']
    ) : '';
    $nav .= $step !== 'summary' ? html_writer::link($nexturl, get_string(
        'wiz_continue',
        $p,
        get_string('step_' . $steps[$index + 1], $p)
    ), ['class' => 'btn btn-primary ms-auto']) : '';
    echo html_writer::div($nav, 'sr-wiz-nav');
}
echo $OUTPUT->footer();
