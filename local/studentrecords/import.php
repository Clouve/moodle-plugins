<?php

/**
 * Import from a spreadsheet or CSV: upload, then review every item (approve, fix or reject) before it is saved.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\drafts;
use local_studentrecords\local\importer;
use local_studentrecords\local\sheet_reader;

$id = optional_param('id', 0, PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHA);
$state = optional_param('state', 'pending', PARAM_ALPHA);
$state = in_array($state, ['pending', 'approved', 'rejected', 'same'], true) ? $state : 'pending';
$fix = optional_param('fix', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
require_login(null, false);
\local_studentrecords\local\staffarea::setup('import');
$syscontext = context_system::instance();
require_capability('local/studentrecords:import', $syscontext);
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/import.php');
$PAGE->set_url(new moodle_url($url, array_filter(['id' => $id, 'type' => $type])));

// Fix one item.
if ($id && $fix) {
    $draft = $DB->get_record(
        'local_studentrecords_draft',
        ['id' => $fix, 'importid' => $id, 'state' => 'pending'],
        '*',
        MUST_EXIST
    );
    $data = json_decode($draft->data, true);
    $form = new \local_studentrecords\form\draft_fix(
        new moodle_url($url, ['id' => $id, 'fix' => $fix, 'type' => $type]),
        ['fields' => $data]
    );
    $back = new moodle_url($url, ['id' => $id, 'type' => $draft->itemtype]);
    if ($form->is_cancelled()) {
        redirect($back);
    }
    if ($submitted = $form->get_data()) {
        $new = [];
        foreach ($data as $k => $v) {
            if (!is_array($v) && property_exists($submitted, 'f_' . $k)) {
                $value = trim((string)$submitted->{'f_' . $k});
                $new[$k] = (is_numeric($value) && (is_float($v) || is_int($v) || $v === null)) ? (float)$value :
                    ($value === '' && $v === null ? null : $value);
            }
        }
        drafts::fix($fix, $new);
        redirect($back, get_string('saved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo \local_studentrecords\local\staffarea::header();
    echo $OUTPUT->heading(get_string('fixitem', $p, s($draft->itemkey)));
    echo html_writer::div(drafts::problems_html((array)json_decode((string)$draft->problems, true)), 'mb-3');
    echo html_writer::tag('p', get_string('source', $p) . ': ' . s((string)$draft->source));
    $form->display();
    echo $OUTPUT->footer();
    die();
}

// Approve or reject.
if ($id && in_array($action, ['approve', 'approveall', 'reject'], true)) {
    require_sesskey();
    $ids = optional_param_array('ids', [], PARAM_INT);
    if ($action === 'approveall') {
        $ids = [];
        foreach (
            $DB->get_records('local_studentrecords_draft', ['importid' => $id, 'state' => 'pending'] +
                ($type ? ['itemtype' => $type] : [])) as $d
        ) {
            if (!drafts::has_error($d)) {
                $ids[] = $d->id;
            }
        }
    }
    core_php_time_limit::raise(600);
    if ($action === 'reject') {
        $n = drafts::reject($ids);
        redirect(new moodle_url($url, ['id' => $id, 'type' => $type]), get_string('rejectedn', $p, $n));
    }
    [$n, $messages] = drafts::approve($ids);
    redirect(
        new moodle_url($url, ['id' => $id, 'type' => $type]),
        get_string('approvedn', $p, $n) .
        ($messages ? html_writer::alist(array_slice($messages, 0, 30)) : ''),
        null,
        $messages ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

// Upload.
$form = new \local_studentrecords\form\import_upload($url);
if (!$id && ($data = $form->get_data())) {
    core_php_time_limit::raise(300);
    raise_memory_limit(MEMORY_EXTRA);
    $name = (string)$form->get_new_filename('importfile');
    $content = (string)$form->get_file_content('importfile');
    $tmp = make_request_directory() . '/upload';
    file_put_contents($tmp, $content);
    try {
        if (preg_match('/\.csv$/i', $name)) {
            $sheets = [pathinfo($name, PATHINFO_FILENAME) => sheet_reader::read_csv($content)];
        } else {
            $sheets = sheet_reader::read_xlsx($tmp);
        }
    } catch (moodle_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $result = importer::build($sheets);
    $importid = drafts::store($name, $result);
    get_file_storage()->create_file_from_string(['contextid' => $syscontext->id, 'component' => $p,
        'filearea' => 'importfile', 'itemid' => $importid, 'filepath' => '/', 'filename' => clean_filename($name)], $content);
    redirect(new moodle_url($url, ['id' => $importid]));
}

echo \local_studentrecords\local\staffarea::header();
if (!$id) {
    echo $OUTPUT->heading(get_string('importtitle', $p));
    echo html_writer::tag('p', get_string('import_intro', $p));
    $form->display();
    $imports = $DB->get_records('local_studentrecords_import', null, 'timecreated DESC', '*', 0, 20);
    if ($imports) {
        echo $OUTPUT->heading(get_string('previousimports', $p), 3);
        $table = new html_table();
        $table->head = [get_string('date'), get_string('file'), get_string('pending', $p)];
        foreach ($imports as $imp) {
            $pending = $DB->count_records('local_studentrecords_draft', ['importid' => $imp->id, 'state' => 'pending']);
            $table->data[] = [userdate($imp->timecreated), html_writer::link(
                new moodle_url($url, ['id' => $imp->id]),
                s($imp->filename)
            ), $pending];
        }
        echo html_writer::table($table);
    }
    echo $OUTPUT->footer();
    die();
}

$import = $DB->get_record('local_studentrecords_import', ['id' => $id], '*', MUST_EXIST);
$summary = json_decode((string)$import->summary, true);
echo $OUTPUT->heading(get_string('reviewimport', $p, s($import->filename)));
echo html_writer::tag('p', get_string('review_intro', $p));

// Sheets read and not read.
$list = [];
foreach ($summary['sheets'] ?? [] as $sheet => $info) {
    $label = $info['type'] === '' ? get_string('sheet_notused', $p) : ($info['type'] === 'later' ? get_string('sheet_later', $p) :
        get_string('type_' . $info['type'], $p));
    $list[] = s($sheet) . ': ' . $label . ' (' . (int)$info['rows'] . ')';
}
echo html_writer::tag('details', html_writer::tag('summary', get_string('sheetsread', $p)) . html_writer::alist($list));
if (!empty($summary['gaps'])) {
    echo $OUTPUT->notification(
        get_string('gapsfound', $p) . '<br>' . drafts::problems_html($summary['gaps']),
        'warning'
    );
}

// Counts by type and state.
$counts = drafts::counts($id);
$table = new html_table();
$states = ['pending', 'approved', 'rejected', 'same'];
$table->head = array_merge([get_string('itemtype', $p)], array_map(fn($s) => get_string('state_' . $s, $p), $states));
foreach (importer::TYPES as $t) {
    if (empty($counts[$t])) {
        continue;
    }
    $row = [html_writer::link(new moodle_url($url, ['id' => $id, 'type' => $t]), get_string('type_' . $t, $p))];
    foreach ($states as $s) {
        $n = $counts[$t][$s] ?? 0;
        $row[] = $n ? html_writer::link(new moodle_url($url, ['id' => $id, 'type' => $t, 'state' => $s]), $n) : '0';
    }
    $table->data[] = $row;
}
echo html_writer::table($table);
$approveall = new moodle_url($url, ['id' => $id, 'action' => 'approveall', 'sesskey' => sesskey()]);
echo $OUTPUT->single_button($approveall, get_string('approveallnoerrors', $p), 'post');

// Items of one type.
if ($type && in_array($type, importer::TYPES)) {
    echo $OUTPUT->heading(get_string('type_' . $type, $p) . ': ' . get_string('state_' . $state, $p), 3);
    $items = $DB->get_records(
        'local_studentrecords_draft',
        ['importid' => $id, 'itemtype' => $type, 'state' => $state],
        'sortorder'
    );
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url($url, ['id' => $id,
        'type' => $type]))->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $table = new html_table();
    $table->head = ['', get_string('item', $p), get_string('values', $p), get_string('source', $p), get_string('tocheck', $p), ''];
    foreach ($items as $d) {
        $data = json_decode($d->data, true);
        $vals = [];
        foreach ($data as $k => $v) {
            if ($v !== null && $v !== '' && $v !== []) {
                $vals[] = html_writer::tag('small', s($k) . ': ' . s(is_array($v) ? implode(', ', $v) : (string)$v));
            }
        }
        $problems = (array)json_decode((string)$d->problems, true);
        $check = $state === 'pending' ? html_writer::checkbox(
            'ids[]',
            $d->id,
            false,
            '',
            ['aria-label' => get_string('selectitem', $p, $d->itemkey)]
        ) : '';
        $fixlink = $state === 'pending' ? html_writer::link(new moodle_url($url, ['id' => $id, 'fix' => $d->id,
            'type' => $type]), get_string('fix', $p)) : '';
        $table->data[] = [$check, s($d->itemkey), implode('<br>', $vals), s((string)$d->source),
            drafts::problems_html($problems), $fixlink];
    }
    echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('noitems', $p));
    if ($state === 'pending' && $items) {
        echo html_writer::tag('button', get_string('approveselected', $p), ['type' => 'submit', 'name' => 'action',
            'value' => 'approve', 'class' => 'btn btn-primary me-2']);
        echo html_writer::tag('button', get_string('rejectselected', $p), ['type' => 'submit', 'name' => 'action',
            'value' => 'reject', 'class' => 'btn btn-secondary']);
    }
    echo html_writer::end_tag('form');
}
echo $OUTPUT->footer();
