<?php

namespace local_studentrecords\task;

/**
 * Daily: seat offers from section waitlists that were not taken in time lapse, and free seats go to the students waiting.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class waitlist_daily extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_waitlistdaily', 'local_studentrecords');
    }

    /**
     * Run the task.
     */
    public function execute() {
        mtrace('Student Records waitlists: ' . \local_studentrecords\local\waitlist::daily() . ' lapsed offer(s)');
    }
}
