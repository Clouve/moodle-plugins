<?php

/**
 * Billing: student accounts with their balance (Finance staff). Finance staff see this billing list, not the
 * academic records. Charges for registrations made before billing was on can be added per term.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

require_once(__DIR__ . '/../../config.php');

use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\staffarea;
use local_studentrecords\local\students;

require_login(null, false);
$search = trim(optional_param('q', '', PARAM_TEXT));
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$filter = in_array($filter, ['all', 'owing', 'credit'], true) ? $filter : 'all';
$chargeterm = optional_param('chargeterm', 0, PARAM_INT);
$p = 'local_studentrecords';
$url = new moodle_url('/local/studentrecords/billing.php', array_filter(['q' => $search, 'filter' => $filter]));
staffarea::setup('billing', $url);
$syscontext = context_system::instance();
$manage = has_capability('local/studentrecords:managebilling', $syscontext);
$terms = catalog::terms();

if ($chargeterm && $manage && billing::enabled()) {
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        $n = billing::charge_term($chargeterm);
        redirect($url, get_string('chargeterm_done', $p, $n), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo staffarea::header();
    echo $OUTPUT->confirm(
        get_string('chargeterm_confirm', $p, format_string($terms[$chargeterm] ?? '')),
        new moodle_url($url, ['chargeterm' => $chargeterm, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    return;
}

echo staffarea::header();
if (!billing::enabled()) {
    echo $OUTPUT->notification(get_string('billingoff', $p), 'info');
}
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'row g-2 mb-3']);
echo html_writer::div(
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => $search,
    'class' => 'form-control', 'placeholder' => get_string('searchnameid', $p), 'aria-label' => get_string('searchnameid', $p)]),
    'col-auto'
);
$options = '';
foreach (['all', 'owing', 'credit'] as $f) {
    $options .= html_writer::tag('option', get_string('accfilter_' . $f, $p), ['value' => $f] +
        ($f === $filter ? ['selected' => 'selected'] : []));
}
echo html_writer::div(html_writer::tag('select', $options, ['name' => 'filter', 'class' => 'form-select',
    'aria-label' => get_string('filter')]), 'col-auto');
echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-secondary']), 'col-auto');
echo html_writer::end_tag('form');

$programs = catalog::programs(false);
$table = new html_table();
$table->head = [get_string('student', $p), get_string('studentid', $p), get_string('program', $p), get_string('status', $p),
    get_string('balance', $p)];
$table->colclasses = [4 => 'text-end'];
$total = 0.0;
foreach (billing::accounts($search, $filter) as $a) {
    $total += (float)$a->balance;
    $table->data[] = [html_writer::link(
        new moodle_url('/local/studentrecords/account.php', ['id' => $a->id]),
        s(students::display_name($a))
    ), s($a->studentid), $a->programid ? ($programs[$a->programid] ?? '') : '',
        get_string('status_' . $a->status, $p), billing::money((float)$a->balance)];
}
if ($table->data) {
    $table->data[] = [html_writer::tag('strong', get_string('total')), '', '', '', html_writer::tag(
        'strong',
        billing::money($total)
    )];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('nothingfound', $p));

if ($manage && billing::enabled() && $terms) {
    echo $OUTPUT->heading(get_string('chargeterm', $p), 3);
    echo html_writer::tag('p', get_string('chargeterm_intro', $p));
    $select = new single_select($url, 'chargeterm', array_map('format_string', $terms), '', ['' => 'choosedots']);
    $select->set_label(get_string('term', $p));
    echo $OUTPUT->render($select);
}
echo $OUTPUT->footer();
