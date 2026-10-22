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
 * Edit marks not used in GPA (P, NP, I, NC, W, TR, IP and custom marks). Marks are never deleted; untick
 * "Active" to hide one.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\scale;

admin_externalpage_setup('local_academictranscript_marks');
require_capability('local/academictranscript:configure', context_system::instance());
$p = 'local_academictranscript';
$url = new moodle_url('/local/academictranscript/marks.php');
$PAGE->set_url($url);

if (data_submitted() && confirm_sesskey()) {
    $rows = [];
    foreach ((array)(data_submitted()->m ?? []) as $raw) {
        $code = clean_param($raw['code'] ?? '', PARAM_ALPHANUMEXT);
        if ($code === '') {
            continue;
        }
        $rows[] = ['code' => $code, 'label' => clean_param($raw['label'] ?? $code, PARAM_TEXT),
            'description' => clean_param($raw['description'] ?? '', PARAM_TEXT), 'earns' => (int)!empty($raw['earns']),
            'active' => (int)!empty($raw['active'])];
    }
    scale::save_marks($rows);
    redirect($url, get_string('markssaved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('marks_title', $p));
echo html_writer::tag('p', get_string('marksintro', $p));
$all = array_values($DB->get_records('local_academictranscript_mark', null, 'sortorder, id'));
for ($i = 0; $i < 3; $i++) {
    $all[] = (object)['id' => 0, 'code' => '', 'label' => '', 'description' => '', 'earns' => 0, 'active' => 1, 'systemmark' => 0];
}
$table = new html_table();
$table->head = [get_string('markcode', $p), get_string('marklabel', $p), get_string('col_description', $p),
    get_string('earnshours', $p), get_string('active', $p)];
foreach ($all as $i => $m) {
    $attr = fn($field, $size) => ['type' => 'text', 'name' => "m[$i][$field]", 'value' => (string)$m->$field, 'size' => $size,
        'class' => 'form-control form-control-sm', 'aria-label' => get_string($field === 'code' ? 'markcode' :
            ($field === 'label' ? 'marklabel' : 'col_description'), 'local_academictranscript')];
    $code = $m->id ? s($m->code) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => "m[$i][code]",
        'value' => $m->code]) . ($m->systemmark ? ' ' . html_writer::span(get_string('systemmark', $p), 'badge bg-info') : '') :
        html_writer::empty_tag('input', $attr('code', 5));
    $table->data[] = [$code, html_writer::empty_tag('input', $attr('label', 18)),
        html_writer::empty_tag('input', $attr('description', 48)),
        html_writer::checkbox("m[$i][earns]", 1, !empty($m->earns), '', ['aria-label' => get_string('earnshours', $p)]),
        html_writer::checkbox("m[$i][active]", 1, !empty($m->active), '', ['aria-label' => get_string('active', $p)])];
}
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::table($table);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('savechanges')]);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
