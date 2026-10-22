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
 * Review drafts pulled from documents or CSV files: each item is shown next to the document text it came
 * from, can be corrected, and is saved only when approved.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\import\drafts;
use local_academictranscript\local\programs;

$type = optional_param('type', 'grade', PARAM_ALPHA);
$type = isset(drafts::FIELDS[$type]) ? $type : 'grade';
$step = optional_param('step', '', PARAM_ALPHA);
admin_externalpage_setup('local_academictranscript_drafts');
require_capability('local/academictranscript:configure', context_system::instance());
$p = 'local_academictranscript';
$url = new moodle_url('/local/academictranscript/drafts.php', ['type' => $type, 'step' => $step]);
$PAGE->set_url($url);

$errors = [];
if (data_submitted() && confirm_sesskey()) {
    $selected = array_keys(array_filter(optional_param_array('sel', [], PARAM_BOOL)));
    $values = (array)(data_submitted()->f ?? []);
    if (optional_param('reject', '', PARAM_RAW) !== '') {
        drafts::reject($selected);
        redirect($url, get_string('draftsrejected', $p, count($selected)), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $edited = [];
    foreach ($selected as $id) {
        $row = [];
        foreach (drafts::FIELDS[$type] as $field) {
            $raw = $values[$id][$field] ?? '';
            $row[$field] = in_array($field, ['minpct', 'maxpct', 'points', 'hours', 'totalhours']) ?
                ($raw === '' ? '' : (float)clean_param($raw, PARAM_FLOAT)) :
                (in_array($field, ['ingpa', 'earns']) ? (int)!empty($raw) : clean_param($raw, PARAM_TEXT));
        }
        $edited[$id] = $row;
    }
    $errors = drafts::approve($type, $edited);
    if (!$errors) {
        $back = $step ? new moodle_url('/local/academictranscript/setup.php', ['step' => $step]) : $url;
        redirect($back, get_string('draftsapproved', $p, count($edited)), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reviewdraftstitle', $p, get_string('itemtype_' . $type, $p)));
$tabs = [];
foreach (array_keys(drafts::FIELDS) as $t) {
    $tabs[] = new tabobject(
        $t,
        new moodle_url('/local/academictranscript/drafts.php', ['type' => $t, 'step' => $step]),
        get_string('itemtype_' . $t, $p) . ' (' . drafts::counts()[$t] . ')'
    );
}
echo $OUTPUT->tabtree($tabs, $type);
echo html_writer::tag('p', get_string('draftsintro', $p));
if ($type === 'grade') {
    echo $OUTPUT->notification(get_string('draftsgradewarning', $p), 'warning');
}
foreach ($errors as $error) {
    echo $OUTPUT->notification(s($error), 'error');
}

$list = drafts::open($type);
if (!$list) {
    echo $OUTPUT->notification(get_string('nodrafts', $p), 'info');
} else {
    $syscontext = context_system::instance();
    $fs = get_file_storage();
    $input = function (int $id, string $field, $value) use ($p) {
        $name = "f[$id][$field]";
        if (in_array($field, ['ingpa', 'earns'])) {
            return html_writer::checkbox($name, 1, !empty($value), '', ['aria-label' => get_string('field_' . $field, $p)]);
        }
        if ($field === 'unittype') {
            return html_writer::select(
                programs::unittype_menu(),
                $name,
                programs::clean_unittype($value),
                false,
                ['class' => 'custom-select', 'aria-label' => get_string('field_' . $field, $p)]
            );
        }
        $size = in_array($field, ['description', 'title', 'name', 'credential', 'program']) ? 24 : 6;
        return html_writer::empty_tag('input', ['type' => 'text', 'name' => $name, 'value' => (string)$value, 'size' => $size,
            'class' => 'form-control form-control-sm', 'aria-label' => get_string('field_' . $field, $p)]);
    };
    $table = new html_table();
    $table->head = [get_string('approve', $p)];
    foreach (drafts::FIELDS[$type] as $field) {
        $table->head[] = get_string('field_' . $field, $p);
    }
    $table->head[] = get_string('fromdocument', $p);
    foreach ($list as $d) {
        $item = json_decode($d->data, true) ?: [];
        $row = [html_writer::checkbox("sel[{$d->id}]", 1, true, '', ['aria-label' => get_string('approve', $p)])];
        foreach (drafts::FIELDS[$type] as $field) {
            $row[] = $input((int)$d->id, $field, $item[$field] ?? '');
        }
        $source = s((string)$d->sourcename);
        if ($d->sourcefileid && ($file = $fs->get_file_by_id($d->sourcefileid))) {
            $source = html_writer::link(moodle_url::make_pluginfile_url(
                $syscontext->id,
                $p,
                'sourcedocs',
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            ), s($file->get_filename()), ['target' => '_blank']);
        }
        $badge = $d->source === 'csv' ? html_writer::span(get_string('fromcsvbadge', $p), 'badge bg-secondary') :
            ($d->quotefound ? html_writer::span(get_string('quotefound', $p), 'badge bg-success') :
                html_writer::span(get_string('quotenotfound', $p), 'badge bg-warning text-dark'));
        $row[] = $source . ' ' . $badge . html_writer::tag(
            'blockquote',
            s((string)$d->excerpt),
            ['class' => 'small text-muted border-start ps-2 mb-0']
        );
        $table->data[] = $row;
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::table($table);
    echo html_writer::div(
        html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'approve', 'class' => 'btn btn-primary',
            'value' => get_string('approveselected', $p)]) . ' ' .
        html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'reject', 'class' => 'btn btn-secondary',
        'value' => get_string(
            'rejectselected',
            $p
        )]),
        'd-flex gap-2'
    );
    echo html_writer::end_tag('form');
}
if ($step) {
    echo html_writer::div(html_writer::link(
        new moodle_url('/local/academictranscript/setup.php', ['step' => $step]),
        get_string('backtowizard', $p)
    ), 'mt-3');
}
echo $OUTPUT->footer();
