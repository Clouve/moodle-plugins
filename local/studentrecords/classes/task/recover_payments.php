<?php

namespace local_studentrecords\task;

/**
 * Record online payments that never reached the student account. Moodle's payment system delivers a payment once and doesn't
 * try again, so this runs every few minutes, not with the daily work. It does nothing when no payment is missing.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class recover_payments extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_recoverpayments', 'local_studentrecords');
    }

    /**
     * Run the task. A payment taken while billing was on still counts after billing is turned off.
     */
    public function execute() {
        mtrace('Student Records online payments recovered: ' . \local_studentrecords\local\payments::recover());
    }
}
