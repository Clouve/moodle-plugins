<?php

namespace local_studentrecords\task;

/**
 * Send one automatic message that could not go at once, because it was written during a transaction or under a lock. The task
 * is saved with that transaction, so work that is rolled back sends nothing, and each message goes on its own, so one that
 * fails doesn't stop the others.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class send_message extends \core\task\adhoc_task {
    /**
     * Send the message. A failure goes in the message log and the task ends, so a person is not told the same thing twice.
     */
    public function execute() {
        \local_studentrecords\local\messages::send_queued($this->get_custom_data());
    }
}
