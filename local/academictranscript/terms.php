<?php

/**
 * Admins: the term list (name, start, end). Terms can be added and edited; they are never deleted,
 * because posted grades and course settings point to them.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_academictranscript\local\terms;

admin_externalpage_setup('local_academictranscript_terms');
// This page does not wait for the setup. The last step of the setup wizard asks for at least one term, so an admin has to
// be able to add one before the setup is finished.
$url = new moodle_url('/local/academictranscript/terms.php');
$PAGE->set_url($url);

$id = optional_param('id', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_BOOL);
$form = new \local_academictranscript\form\term(new moodle_url($url, ['edit' => 1]));
if ($id) {
    $form->set_data($DB->get_record('local_academictranscript_term', ['id' => $id], '*', MUST_EXIST));
}
if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    terms::save($data);
    redirect($url, get_string('termsaved', 'local_academictranscript'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('terms', 'local_academictranscript'));
echo html_writer::tag('p', get_string('termsintro', 'local_academictranscript'));

if ($edit || $id) {
    $form->display();
} else {
    echo $OUTPUT->single_button(new moodle_url($url, ['edit' => 1]), get_string('addterm', 'local_academictranscript'), 'get');
}

$all = terms::all();
if ($all) {
    $table = new html_table();
    $table->head = [get_string('termname', 'local_academictranscript'), get_string('termstart', 'local_academictranscript'),
        get_string('termend', 'local_academictranscript'), ''];
    foreach ($all as $term) {
        $table->data[] = [format_string($term->name), userdate($term->startdate, get_string('strftimedate', 'langconfig')),
            userdate($term->enddate, get_string('strftimedate', 'langconfig')),
            html_writer::link(new moodle_url($url, ['id' => $term->id]), get_string('edit'))];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
