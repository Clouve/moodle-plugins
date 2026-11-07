<?php

namespace local_studentrecords\task;

/**
 * Carry out the finished deletion requests of accounts Moodle has removed. A privacy officer approved each of them, but on a site
 * with data registry defaults Moodle's own task can't reach this plugin's data once the user context is gone.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class erase_removed_accounts extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_eraseremovedaccounts', 'local_studentrecords');
    }

    /**
     * Run the task.
     */
    public function execute() {
        $erased = \local_studentrecords\privacy\provider::erase_removed_accounts();
        mtrace('Student Records deletion requests carried out: ' . $erased);
    }
}
