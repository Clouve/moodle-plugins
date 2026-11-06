<?php

namespace local_studentrecords\task;

use local_studentrecords\local\messages;
use local_studentrecords\local\registration;
use local_studentrecords\local\waitlist;

#[\PHPUnit\Framework\Attributes\CoversClass(waitlist_daily::class)]
/**
 * Tests for the daily waitlist task.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\task\waitlist_daily
 */
final class waitlist_daily_test extends \advanced_testcase {
    /**
     * Running the task twice lapses an old offer once and tells the next student once.
     */
    public function test_running_twice_offers_the_seat_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $generator->set_settings(['selfreg' => 1]);
        $offer = $generator->create_offering(['courseid' => $generator->create_course()->id,
            'termid' => $generator->create_term()->id, 'capacity' => 1]);
        $seated = $generator->create_user_student();
        $first = $generator->create_user_student();
        $second = $generator->create_user_student();
        $regid = registration::add($seated, (int)$offer->id);
        $firstid = waitlist::join($first, (int)$offer->id);
        $secondid = waitlist::join($second, (int)$offer->id);
        registration::end($regid, 'drop');
        $DB->set_field(waitlist::TABLE, 'timeoffered', time() - (waitlist::HOLDDAYS * DAYSECS + 1), ['id' => $firstid]);
        $DB->delete_records(messages::LOG);

        $task = new waitlist_daily();
        ob_start();
        $task->execute();
        $task->execute();
        ob_end_clean();

        $this->assertSame('left', $DB->get_field(waitlist::TABLE, 'status', ['id' => $firstid]));
        $this->assertSame('offered', $DB->get_field(waitlist::TABLE, 'status', ['id' => $secondid]));
        $this->assertSame(1, $DB->count_records(messages::LOG, ['msgkey' => 'waitseat']));
    }
}
