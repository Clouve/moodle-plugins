<?php

namespace local_studentrecords\task;

/**
 * Daily billing work: reminders before plan due dates; late plan payments (late fee, financial hold).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class billing_daily extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_billingdaily', 'local_studentrecords');
    }

    /**
     * Run the task (nothing happens while billing is off).
     */
    public function execute() {
        $counts = \local_studentrecords\local\plans::daily();
        mtrace('Student Records billing: ' . json_encode($counts));
    }
}
