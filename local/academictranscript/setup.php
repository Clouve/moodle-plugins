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
 * Setup wizard: school details, grading, marks, programs and hours, transcript look.
 *
 * Grading policies and catalogs can be uploaded and read with the school's own AI provider (core_ai);
 * without one, data is entered by hand or from CSV templates. Everything read from a document or CSV
 * is a draft until an admin approves it.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\import\ai_extractor;
use local_academictranscript\local\import\csv;
use local_academictranscript\local\import\document_text;
use local_academictranscript\local\import\drafts;
use local_academictranscript\local\scale;
use local_academictranscript\local\programs;

$steps = ['school', 'grading', 'marks', 'programs', 'look', 'done'];
$step = optional_param('step', 'school', PARAM_ALPHA);
$step = in_array($step, $steps) ? $step : 'school';
$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_academictranscript_setup');
$syscontext = context_system::instance();
require_capability('local/academictranscript:configure', $syscontext);
$p = 'local_academictranscript';
$url = new moodle_url('/local/academictranscript/setup.php', ['step' => $step]);
$PAGE->set_url($url);
$next = fn($s) => new moodle_url('/local/academictranscript/setup.php', ['step' => $steps[array_search($s, $steps) + 1]]);
$steptypes = ['grading' => ['grade', 'mark'], 'marks' => ['mark'], 'programs' => ['program', 'course']];

// One-click actions.
if ($action && confirm_sesskey()) {
    if ($action === 'preset' && $step === 'grading') {
        scale::replace_grades(scale::standard_preset());
        redirect($url, get_string('presetloaded', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'standardmarks' && $step === 'marks') {
        scale::add_missing_standard_marks();
        redirect($url, get_string('standardmarksadded', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'finish' && $step === 'done') {
        set_config('setupcomplete', 1, $p);
        redirect(
            new moodle_url('/admin/category.php', ['category' => 'local_academictranscript_category']),
            get_string('setupfinished', $p),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Forms for this step.
$form = null;
$docform = null;
$csvform = null;
if ($step === 'school') {
    $form = new \local_academictranscript\form\setup_school($url);
    $draftid = file_get_submitted_draft_itemid('logo');
    file_prepare_draft_area($draftid, $syscontext->id, $p, 'logo', 0, ['maxfiles' => 1, 'subdirs' => 0]);
    $current = ['step' => 'school', 'logo' => $draftid];
    foreach (['schoolname', 'address', 'phone', 'email', 'website', 'registrarname', 'registrartitle'] as $k) {
        $current[$k] = get_config($p, $k);
    }
    $form->set_data($current);
    if ($data = $form->get_data()) {
        foreach (['schoolname', 'address', 'phone', 'email', 'website', 'registrarname', 'registrartitle'] as $k) {
            set_config($k, trim((string)$data->$k), $p);
        }
        file_save_draft_area_files($data->logo, $syscontext->id, $p, 'logo', 0, ['maxfiles' => 1, 'subdirs' => 0]);
        redirect($next('school'));
    }
} else if ($step === 'look') {
    $form = new \local_academictranscript\form\setup_look($url);
    $current = ['step' => 'look'];
    $defaults = ['pagesize' => 'LETTER', 'accentcolor' => '#1F3A5F', 'signaturemode' => 'lines', 'roundpercent' => 1,
        'repeatrule' => 'latest', 'creditlabel' => get_string('creditlabel_default', $p),
        'clocklabel' => get_string('clocklabel_default', $p)];
    foreach ($defaults as $k => $default) {
        $v = get_config($p, $k);
        $current[$k] = ($v === false || $v === '') ? $default : $v;
    }
    $form->set_data($current);
    if ($data = $form->get_data()) {
        foreach (array_keys($defaults) as $k) {
            set_config($k, is_string($data->$k) ? trim($data->$k) : $data->$k, $p);
        }
        redirect($next('look'));
    }
} else if (isset($steptypes[$step])) {
    $aiavailable = ai_extractor::available();
    if ($aiavailable) {
        $docform = new \local_academictranscript\form\document_upload(
            $url,
            ['step' => $step, 'needpolicy' => !ai_extractor::policy_accepted($USER->id)]
        );
        if ($data = $docform->get_data()) {
            if (!empty($data->acceptpolicy)) {
                ai_extractor::accept_policy($USER->id);
            }
            core_php_time_limit::raise(600);
            $itemid = (int)(get_config($p, 'docbatch') ?: 0) + 1;
            set_config('docbatch', $itemid, $p);
            file_save_draft_area_files(
                $data->documents,
                $syscontext->id,
                $p,
                'sourcedocs',
                $itemid,
                ['maxfiles' => 5, 'subdirs' => 0]
            );
            $total = 0;
            $errors = [];
            foreach (get_file_storage()->get_area_files($syscontext->id, $p, 'sourcedocs', $itemid, 'id', false) as $file) {
                try {
                    $text = document_text::from_stored_file($file);
                    $items = ai_extractor::extract($text, $steptypes[$step], $USER->id);
                    $total += drafts::store($items, 'ai', $file->get_filename(), $file->get_id(), $text);
                } catch (moodle_exception $e) {
                    $errors[] = $file->get_filename() . ': ' . $e->getMessage();
                }
            }
            $message = get_string('draftscreated', $p, $total) . ($errors ? html_writer::alist(array_map('s', $errors)) : '');
            redirect(
                new moodle_url('/local/academictranscript/drafts.php', ['type' => $steptypes[$step][0], 'step' => $step]),
                $message,
                null,
                $errors ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }
    $csvform = new \local_academictranscript\form\csv_upload($url, ['step' => $step, 'types' => $steptypes[$step]]);
    if ($data = $csvform->get_data()) {
        $type = in_array($data->itemtype, $steptypes[$step]) ? $data->itemtype : $steptypes[$step][0];
        $parsed = csv::parse($type, (string)$csvform->get_file_content('csvfile'));
        $count = drafts::store([$type => $parsed['items']], 'csv', (string)$csvform->get_new_filename('csvfile'), 0, '');
        $message = get_string('draftscreated', $p, $count) .
            ($parsed['errors'] ? html_writer::alist(array_map('s', $parsed['errors'])) : '');
        redirect(
            new moodle_url('/local/academictranscript/drafts.php', ['type' => $type, 'step' => $step]),
            $message,
            null,
            $parsed['errors'] ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setupwizard', $p));

// Step list.
$items = [];
foreach ($steps as $i => $s) {
    $label = ($i + 1) . '. ' . get_string('step_' . $s, $p);
    $items[] = $s === $step ? html_writer::tag('strong', $label) :
        html_writer::link(new moodle_url('/local/academictranscript/setup.php', ['step' => $s]), $label);
}
echo html_writer::div(implode(' &nbsp;›&nbsp; ', $items), 'mb-3');
echo $OUTPUT->heading(get_string('step_' . $step, $p), 3);
echo html_writer::tag('p', get_string('step_' . $step . '_intro', $p));

$counts = drafts::counts();
$draftlinks = function (array $types) use ($counts, $step, $p) {
    $out = '';
    foreach ($types as $type) {
        if ($counts[$type]) {
            $out .= html_writer::div(html_writer::link(new moodle_url(
                '/local/academictranscript/drafts.php',
                ['type' => $type, 'step' => $step]
            ), get_string(
                'reviewdrafts',
                $p,
                ['n' => $counts[$type], 'type' => get_string('itemtype_' . $type, $p)]
            )), 'alert alert-warning');
        }
    }
    return $out;
};
$sourcesections = function (array $types) use ($docform, $csvform, $p, $OUTPUT) {
    echo $OUTPUT->heading(get_string('fromdocuments', $p), 4);
    if ($docform) {
        echo html_writer::tag('p', get_string('fromdocuments_intro', $p));
        $docform->display();
    } else {
        echo $OUTPUT->notification(get_string('noaiprovider', $p, (new moodle_url(
            '/admin/settings.php',
            ['section' => 'aiprovider']
        ))->out()), 'info');
    }
    echo $OUTPUT->heading(get_string('fromcsv', $p), 4);
    $links = [];
    foreach ($types as $type) {
        $links[] = html_writer::link(
            new moodle_url('/local/academictranscript/templates.php', ['type' => $type]),
            get_string('csvtemplate', $p, get_string('itemtype_' . $type, $p))
        );
    }
    echo html_writer::tag('p', get_string('fromcsv_intro', $p) . ' ' . implode(' · ', $links));
    $csvform->display();
};

if ($step === 'school' || $step === 'look') {
    $form->display();
} else if ($step === 'grading') {
    echo $draftlinks(['grade', 'mark']);
    echo \local_academictranscript\local\html_view::scale_table();
    echo html_writer::div(
        $OUTPUT->single_button(
            new moodle_url($url, ['action' => 'preset']),
            get_string('loadpreset', $p),
            'post',
            ['type' => single_button::BUTTON_SECONDARY]
        ) . ' ' .
        $OUTPUT->single_button(new moodle_url('/local/academictranscript/grading.php'), get_string('editscale', $p), 'get'),
        'd-flex gap-2 my-3'
    );
    $sourcesections($steptypes[$step]);
} else if ($step === 'marks') {
    echo $draftlinks(['mark']);
    $table = new html_table();
    $table->head = [get_string('markcode', $p), get_string('marklabel', $p), get_string('col_description', $p),
        get_string('earnshours', $p)];
    foreach (scale::marks() as $m) {
        $table->data[] = [s($m->code), format_string($m->label), format_string((string)$m->description),
            $m->earns ? get_string('yes') : get_string('no')];
    }
    echo html_writer::table($table);
    echo html_writer::div(
        $OUTPUT->single_button(
            new moodle_url($url, ['action' => 'standardmarks']),
            get_string('addstandardmarks', $p),
            'post',
            ['type' => single_button::BUTTON_SECONDARY]
        ) . ' ' .
        $OUTPUT->single_button(new moodle_url('/local/academictranscript/marks.php'), get_string('editmarks', $p), 'get'),
        'd-flex gap-2 my-3'
    );
    $sourcesections($steptypes[$step]);
} else if ($step === 'programs') {
    echo $draftlinks(['program', 'course']);
    $table = new html_table();
    $table->head = [get_string('programname', $p), get_string('credential', $p), get_string('unittype', $p),
        get_string('catalogcourses', $p)];
    $unitmenu = programs::unittype_menu();
    foreach (programs::all() as $prog) {
        $table->data[] = [format_string($prog->name), s((string)$prog->credential), $unitmenu[$prog->unittype],
            $DB->count_records('local_academictranscript_catalog', ['programid' => $prog->id, 'active' => 1])];
    }
    echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('noprograms', $p), 'info');
    echo html_writer::div(
        $OUTPUT->single_button(
            new moodle_url('/local/academictranscript/programs.php'),
            get_string('editprograms', $p),
            'get'
        ) . ' ' .
        $OUTPUT->single_button(new moodle_url('/local/academictranscript/catalog.php'), get_string('editcatalog', $p), 'get'),
        'd-flex gap-2 my-3'
    );
    $sourcesections($steptypes[$step]);
} else if ($step === 'done') {
    $checks = [
        'check_school' => trim((string)get_config($p, 'schoolname')) !== '',
        'check_scale' => (bool)scale::grades(),
        'check_marks' => (bool)scale::marks(),
        'check_programs' => (bool)programs::all(),
        'check_terms' => $DB->record_exists('local_academictranscript_term', []),
        'check_drafts' => !array_sum($counts),
    ];
    $list = [];
    foreach ($checks as $key => $ok) {
        $label = get_string($key, $p);
        if ($key === 'check_terms') {
            // The term is added on the Terms page, which opens before the setup is finished.
            $label = html_writer::link(new moodle_url('/local/academictranscript/terms.php'), $label);
        }
        $list[] = ($ok ? '✔ ' : '✖ ') . $label;
    }
    echo html_writer::alist($list);
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'finish']),
        get_string('finishsetup', $p),
        'post',
        ['type' => single_button::BUTTON_PRIMARY]
    );
}

if ($step !== 'done' && $step !== 'school' && $step !== 'look') {
    echo html_writer::div($OUTPUT->single_button(
        $next($step),
        get_string('nextstep', $p),
        'get',
        ['type' => single_button::BUTTON_PRIMARY]
    ), 'mt-4');
}
echo $OUTPUT->footer();
