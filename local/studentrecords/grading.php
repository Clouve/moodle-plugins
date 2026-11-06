<?php

/**
 * Grading: the scale, academic levels, lowest passing grades (by level, program, group or course) and the
 * academic rules.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\catalog;
use local_studentrecords\local\grading;
use local_studentrecords\local\records;

$what = optional_param('what', '', PARAM_ALPHA);
$id = optional_param('id', -1, PARAM_INT);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('grading');
require_capability('local/studentrecords:configure', context_system::instance());
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/grading.php');
$PAGE->set_url($url);

$tables = ['grade' => 'local_studentrecords_grade', 'level' => 'local_studentrecords_level',
    'minpass' => 'local_studentrecords_minpass'];
if (isset($tables[$what]) && $id >= 0) {
    $class = '\\local_studentrecords\\form\\' . $what;
    $form = new $class(new moodle_url($url, ['what' => $what, 'id' => $id]));
    if ($id) {
        $record = $DB->get_record($tables[$what], ['id' => $id], '*', MUST_EXIST);
        if ($what === 'minpass') {
            $record->coursecode = $record->courseid ?
                (string)$DB->get_field('local_studentrecords_course', 'code', ['id' => $record->courseid]) : '';
            $record->minpct = $record->minpct === null ? '' : (float)$record->minpct;
        }
        $form->set_data($record);
    }
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $record = (array)$data;
        unset($record['what'], $record['submitbutton']);
        if ($what === 'grade') {
            foreach (['minpct', 'maxpct', 'points'] as $f) {
                $record[$f] = $record[$f] === '' ? null : (float)$record[$f];
            }
            if (!$id) {
                $record['sortorder'] = $DB->count_records($tables[$what]) + 1;
            }
        } else if ($what === 'level' && !$id) {
            $record['sortorder'] = $DB->count_records($tables[$what]) + 1;
        } else if ($what === 'minpass') {
            $record['courseid'] = trim($record['coursecode']) !== '' ? catalog::course_id($record['coursecode']) : null;
            $record['levelid'] = $record['levelid'] ?: null;
            $record['programid'] = $record['programid'] ?: null;
            $record['minletter'] = $record['minletter'] ?: null;
            $record['minpct'] = $record['minpct'] === '' ? null : (float)$record['minpct'];
        }
        if (!$id) {
            unset($record['id']);
        }
        records::save($tables[$what], $record);
        \local_studentrecords\local\audit::log($id ? 'update' : 'create', $what, $id ?: null);
        redirect($url, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(get_string('edit_' . $what, $p));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

echo \local_studentrecords\local\staffarea::header();
$editlink = fn($w, $rid) => html_writer::link(new moodle_url($url, ['what' => $w, 'id' => $rid]), get_string('edit'));

// Scale.
echo $OUTPUT->heading(get_string('scale', $p), 3);
$scale = catalog::scale();
$table = new html_table();
$table->head = [get_string('letter', $p), get_string('minpct', $p), get_string('maxpct', $p), get_string('points', $p),
    get_string('ingpa', $p), get_string('earns', $p), get_string('description'), ''];
$num = fn($v) => $v === null ? '' : format_float($v, -1);
foreach ($scale as $g) {
    $table->data[] = [s($g->letter), $num($g->minpct), $num($g->maxpct), $num($g->points),
        $g->ingpa ? get_string('yes') : get_string('no'),
        $g->points === null ? ($g->earns ? get_string('yes') : get_string('no')) : '',
        s((string)$g->description), $editlink('grade', $g->id)];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('noscale', $p), 'info');
foreach (grading::check_scale(array_map(fn($r) => (array)$r, $scale)) as [$key, $a]) {
    echo $OUTPUT->notification(get_string($key, $p, $a), 'warning');
}
if ($scale && !array_filter($scale, fn($g) => !$g->ingpa)) {
    echo $OUTPUT->notification(get_string('gap_nomarks', $p), 'info');
}
echo html_writer::div(html_writer::link(
    new moodle_url($url, ['what' => 'grade', 'id' => 0]),
    get_string('addgrade', $p),
    ['class' => 'btn btn-secondary']
), 'mb-4');

// Levels and pass rules.
echo $OUTPUT->heading(get_string('levels', $p), 3);
$levels = $DB->get_records('local_studentrecords_level', null, 'sortorder, name');
$programs = catalog::programs(false);
$table = new html_table();
$table->head = [get_string('levelname', $p), get_string('description'), get_string('levelrule', $p), ''];
$rules = $DB->get_records('local_studentrecords_minpass');
$rulefor = function ($r) use ($p) {
    return $r->minletter ? get_string('rule_letter', $p, s($r->minletter)) :
        ($r->minpct !== null ? get_string('rule_pct', $p, format_float($r->minpct, -1)) : '');
};
foreach ($levels as $l) {
    $lr = array_filter($rules, fn($r) => $r->levelid == $l->id);
    $table->data[] = [format_string($l->name), s((string)$l->description),
        $lr ? implode(', ', array_map($rulefor, $lr)) : html_writer::span(get_string('norule', $p), 'text-danger'),
        $editlink('level', $l->id)];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('nolevels', $p), 'info');
echo html_writer::div(html_writer::link(
    new moodle_url($url, ['what' => 'level', 'id' => 0]),
    get_string('addlevel', $p),
    ['class' => 'btn btn-secondary']
), 'mb-4');

echo $OUTPUT->heading(get_string('passrules', $p), 3);
echo html_writer::tag('p', get_string('passrules_intro', $p));
$table = new html_table();
$table->head = [get_string('scope', $p), get_string('levelrule', $p), get_string('note', $p), ''];
$courses = $DB->get_records_menu('local_studentrecords_course', null, '', 'id, code');
foreach ($rules as $r) {
    $scope = $r->levelid ? get_string('level', $p) . ': ' . format_string($levels[$r->levelid]->name ?? '?') :
        ($programs[$r->programid] ?? '?') . ($r->groupname ? ' › ' . s($r->groupname) : '') .
        ($r->courseid ? ' › ' . s($courses[$r->courseid] ?? '?') : '');
    $table->data[] = [$scope, $rulefor($r), s((string)$r->note), $editlink('minpass', $r->id)];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('norules', $p), 'info');
echo html_writer::div(html_writer::link(
    new moodle_url($url, ['what' => 'minpass', 'id' => 0]),
    get_string('addrule', $p),
    ['class' => 'btn btn-secondary']
), 'mb-4');

// Academic rules (policies) as the school states them.
echo $OUTPUT->heading(get_string('policies', $p), 3);
$policies = $DB->get_records('local_studentrecords_policy', null, 'sortorder, id');
if ($policies) {
    $bykey = [];
    foreach ($policies as $pol) {
        $bykey[$pol->rulekey]['title'] = $pol->title;
        $bykey[$pol->rulekey]['values'][$pol->levelid ? format_string($levels[$pol->levelid]->name ?? '?') :
            get_string('alllevels', $p)] = $pol->value;
    }
    $table = new html_table();
    $table->head = [get_string('rule', $p), get_string('value', $p)];
    foreach ($bykey as $row) {
        $vals = [];
        foreach ($row['values'] as $lvl => $v) {
            $vals[] = html_writer::tag('strong', $lvl) . ': ' . s($v);
        }
        $table->data[] = [s($row['title']), implode('<br>', $vals)];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nopolicies', $p), 'info');
}
echo $OUTPUT->footer();
