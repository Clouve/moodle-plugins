<?php

/**
 * Student Records staff area: the entry page with the staff menu.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\staffarea;

require_login(null, false);
$p = 'local_studentrecords';
$pages = staffarea::pages();
if (!$pages) {
    throw new required_capability_exception(context_system::instance(), 'local/studentrecords:viewstudents', 'nopermissions', '');
}
staffarea::start_page(new moodle_url(staffarea::URL));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('staffarea', $p));
echo html_writer::tag('p', get_string('staffarea_intro', $p));
echo html_writer::start_div('list-group mb-3');
foreach ($pages as $name => [$url, $label]) {
    $desc = get_string_manager()->string_exists('staffpage_' . $name, $p) ?
        html_writer::div(get_string('staffpage_' . $name, $p), 'small text-muted') : '';
    echo html_writer::link($url, html_writer::div($label, 'fw-bold') . $desc, [
        'class' => 'list-group-item list-group-item-action']);
}
echo html_writer::end_div();
echo $OUTPUT->footer();
