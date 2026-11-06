<?php

/**
 * Financial aid award letter (federal aid module): the student's awards for an award year, cost of attendance and need,
 * ready to print. Financial Aid staff and the student can open it.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\aid;
use local_studentrecords\local\aidhelp;
use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\students;

require_login(null, false);
aid::require_enabled();
$id = required_param('id', PARAM_INT);
$p = 'local_studentrecords';
$student = students::get($id);
$year = optional_param('year', aidhelp::award_year(time()), PARAM_TEXT);
$year = preg_match('/^\d{4}-\d{2}$/', $year) ? $year : aidhelp::award_year(time());
$syscontext = context_system::instance();
$own = (int)$student->userid === (int)$USER->id && has_capability('local/studentrecords:viewown', $syscontext);
if (!$own) {
    require_capability('local/studentrecords:viewaid', $syscontext);
}
$url = new moodle_url('/local/studentrecords/aidletter.php', ['id' => $id, 'year' => $year]);
$PAGE->set_context($syscontext);
$PAGE->set_url($url);
$PAGE->set_pagelayout(optional_param('print', 0, PARAM_BOOL) ? 'print' : 'standard');
$title = get_string('aid_lettertitle', $p, $year);
$PAGE->set_title($title);
$PAGE->set_heading($title);
\local_studentrecords\local\audit::log('view', 'aid', $id, (int)$student->userid, 'award letter ' . $year);

echo $OUTPUT->header();
$school = catalog::setting('schoolname') !== '' ? s(catalog::setting('schoolname')) : format_string($SITE->fullname);
echo html_writer::tag('p', $school . ' · ' . get_string('aid_letterfor', $p, (object)['name' =>
    s(students::display_name($student)), 'id' => s($student->studentid)]), ['class' => 'sr-lead']);
$y = aidhelp::year($id, $year);
$awards = array_filter(aid::awards($id), fn($a) => $a->awardyear === $year && $a->status !== 'cancelled');
$terms = catalog::terms();
$t = new html_table();
$t->attributes['class'] = 'sr-table';
$t->head = [get_string('fund', $p), get_string('term', $p), get_string('amountusd', $p), get_string('aid_decision', $p)];
$t->colclasses = [2 => 'text-end'];
$total = 0.0;
foreach ($awards as $a) {
    $t->data[] = [get_string('fund_' . $a->fund, $p), $a->termid ? format_string($terms[$a->termid] ?? '') : '-',
        billing::money((float)$a->amount), get_string('aidstatus_' . $a->status, $p)];
    $total += $a->status !== 'declined' ? (float)$a->amount : 0;
}
$t->data[] = [html_writer::tag('strong', get_string('aid_totaloffered', $p)), '', html_writer::tag(
    'strong',
    billing::money($total)
), ''];
if ($y->coa !== null) {
    echo html_writer::tag('p', get_string('aid_lettersummary', $p, (object)['coa' => billing::money((float)$y->coa),
        'need' => billing::money(aidhelp::need((float)$y->coa, (float)$y->sai))]));
}
echo html_writer::table($t);
echo html_writer::tag('p', get_string('aid_letternext', $p), ['class' => 'sr-muted']);
if (!optional_param('print', 0, PARAM_BOOL)) {
    echo html_writer::link(
        new moodle_url($url, ['print' => 1]),
        get_string('printversion', $p),
        ['class' => 'btn btn-outline-secondary']
    );
}
echo $OUTPUT->footer();
