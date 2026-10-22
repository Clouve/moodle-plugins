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
 * Edit the grading scale: letter, minimum %, maximum %, grade points, counts in GPA, earns hours, description.
 * Saving retires the previous rows (kept, not deleted) and makes the edited rows active.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\scale;

admin_externalpage_setup('local_academictranscript_grading');
require_capability('local/academictranscript:configure', context_system::instance());
$p = 'local_academictranscript';
$url = new moodle_url('/local/academictranscript/grading.php');
$PAGE->set_url($url);
$fields = ['letter', 'minpct', 'maxpct', 'points', 'ingpa', 'earns', 'description', 'percentlabel'];

$errors = [];
if (data_submitted() && confirm_sesskey()) {
    if (optional_param('preset', '', PARAM_RAW) !== '') {
        scale::replace_grades(scale::standard_preset());
        redirect($url, get_string('presetloaded', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $rows = [];
    foreach ((array)(data_submitted()->g ?? []) as $raw) {
        $letter = clean_param($raw['letter'] ?? '', PARAM_TEXT);
        if (trim($letter) === '') {
            continue;
        }
        $rows[] = ['letter' => trim($letter), 'minpct' => (float)clean_param($raw['minpct'] ?? 0, PARAM_FLOAT),
            'maxpct' => (float)clean_param($raw['maxpct'] ?? 0, PARAM_FLOAT),
            'points' => (float)clean_param($raw['points'] ?? 0, PARAM_FLOAT), 'ingpa' => (int)!empty($raw['ingpa']),
            'earns' => (int)!empty($raw['earns']), 'description' => clean_param($raw['description'] ?? '', PARAM_TEXT),
            'percentlabel' => clean_param($raw['percentlabel'] ?? '', PARAM_TEXT)];
    }
    $errors = $rows ? scale::validate_rows($rows) : [get_string('err_emptyscale', $p)];
    if (!$errors) {
        scale::replace_grades($rows);
        redirect($url, get_string('scalesaved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('gradingscale_title', $p));
echo html_writer::tag('p', get_string('gradingintro', $p));
foreach ($errors as $error) {
    echo $OUTPUT->notification(s($error), 'error');
}

$current = array_values(scale::grades());
for ($i = 0; $i < 4; $i++) {
    $current[] = (object)array_fill_keys($fields, '');
}
$table = new html_table();
$table->head = [];
foreach ($fields as $field) {
    $table->head[] = get_string('field_' . $field, $p);
}
foreach ($current as $i => $g) {
    $row = [];
    foreach ($fields as $field) {
        $name = "g[$i][$field]";
        $value = $g->$field ?? '';
        if (in_array($field, ['ingpa', 'earns'])) {
            $checked = $g->letter === '' ? true : !empty($value);
            $row[] = html_writer::checkbox($name, 1, $checked, '', ['aria-label' => get_string('field_' . $field, $p)]);
        } else {
            if (in_array($field, ['minpct', 'maxpct', 'points']) && $value !== '') {
                $value = rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
            }
            $size = in_array($field, ['description']) ? 36 : ($field === 'percentlabel' ? 12 : 6);
            $row[] = html_writer::empty_tag('input', ['type' => 'text', 'name' => $name, 'value' => $value, 'size' => $size,
                'class' => 'form-control form-control-sm', 'aria-label' => get_string('field_' . $field, $p)]);
        }
    }
    $table->data[] = $row;
}
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::table($table);
echo html_writer::div(
    html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('savescale', $p)]) .
    ' ' . html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'preset', 'class' => 'btn btn-secondary',
        'value' => get_string('loadpreset', $p),
    'onclick' => 'return confirm(' . json_encode(get_string(
        'loadpresetconfirm',
        $p
    )) . ');']),
    'd-flex gap-2'
);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
