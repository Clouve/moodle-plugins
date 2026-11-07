<?php

namespace local_studentrecords\task;

use local_studentrecords\local\academic;
use local_studentrecords\local\messages;
use local_studentrecords\local\registration;
use local_studentrecords\local\students;
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
    /** @var \local_studentrecords_generator */
    private $generator;

    /**
     * Self-registration is on, signed in as the admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->generator->set_settings(['selfreg' => 1]);
    }

    /**
     * A full section with one seat, and students on its waitlist.
     *
     * @param int $waiting how many students wait
     * @return array offer, regid (of the seat), students, entries (their waitlist ids, in order)
     */
    private function full_section(int $waiting): array {
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course()->id,
            'termid' => $this->generator->create_term()->id, 'capacity' => 1]);
        $regid = registration::add($this->generator->create_user_student(), (int)$offer->id);
        $students = [];
        $entries = [];
        for ($i = 0; $i < $waiting; $i++) {
            $students[$i] = $this->generator->create_user_student();
            $entries[$i] = waitlist::join($students[$i], (int)$offer->id);
        }
        return ['offer' => $offer, 'regid' => $regid, 'students' => $students, 'entries' => $entries];
    }

    /**
     * Free the seat of a section with nobody told: no code that offers seats has run.
     *
     * @param array $section from full_section()
     */
    private function free_the_seat(array $section): void {
        global $DB;
        $DB->set_field(registration::REG, 'status', 'dropped', ['id' => $section['regid']]);
    }

    /**
     * Run the task and throw away what it prints.
     */
    private function run_task(): void {
        ob_start();
        try {
            (new waitlist_daily())->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * The status of a waitlist entry.
     *
     * @param int $id
     * @return string
     */
    private function entry_status(int $id): string {
        global $DB;
        return (string)$DB->get_field(waitlist::TABLE, 'status', ['id' => $id]);
    }

    /**
     * Running the task twice lapses an old offer once and tells the next student once.
     */
    public function test_running_twice_offers_the_seat_once(): void {
        global $DB;
        $section = $this->full_section(2);
        [$firstid, $secondid] = $section['entries'];
        registration::end($section['regid'], 'drop');
        $DB->set_field(waitlist::TABLE, 'timeoffered', time() - (waitlist::HOLDDAYS * DAYSECS + 1), ['id' => $firstid]);
        $DB->delete_records(messages::LOG);

        $this->run_task();
        $this->run_task();

        $this->assertSame('left', $this->entry_status($firstid));
        $this->assertSame('offered', $this->entry_status($secondid));
        $this->assertSame(1, $DB->count_records(messages::LOG, ['msgkey' => 'waitseat']));
    }

    /**
     * A run that stopped after ending an offer left the seat free with nobody offered it. The next run offers it.
     */
    public function test_the_next_run_offers_a_seat_a_stopped_run_left_free(): void {
        global $DB;
        $section = $this->full_section(3);
        [$firstid, $secondid, $thirdid] = $section['entries'];
        registration::end($section['regid'], 'drop');
        // Where the run stopped: the offer has ended, and no next offer was made.
        $DB->set_field(waitlist::TABLE, 'status', 'left', ['id' => $firstid]);
        $DB->delete_records(messages::LOG);

        $this->run_task();
        $this->run_task();

        $this->assertSame('offered', $this->entry_status($secondid));
        $this->assertSame('waiting', $this->entry_status($thirdid));
        $this->assertSame(1, $DB->count_records(messages::LOG, ['msgkey' => 'waitseat']));
    }

    /**
     * A seat that no lapsed offer freed is offered too, and only as many seats as are free.
     */
    public function test_any_free_seat_is_offered(): void {
        global $DB;
        $section = $this->full_section(3);
        $this->free_the_seat($section);
        $DB->set_field(registration::OFFER, 'capacity', 2, ['id' => $section['offer']->id]);

        $this->run_task();

        $this->assertSame(
            ['offered', 'offered', 'waiting'],
            array_map(fn($id) => $this->entry_status($id), $section['entries'])
        );
    }

    /**
     * A student passed over for a hold keeps their place, and gets the free seat from the run after the hold ends.
     */
    public function test_a_student_passed_over_gets_the_seat_when_they_can_take_it(): void {
        $section = $this->full_section(1);
        $holdid = academic::place_hold($section['students'][0], ['holdtype' => 'financial', 'reason' => 'Test', 'blocksreg' => 1,
            'startdate' => time() - DAYSECS]);
        $this->free_the_seat($section);

        $this->run_task();
        $this->assertSame('waiting', $this->entry_status($section['entries'][0]));

        academic::release_hold($holdid, 'Paid');
        $this->run_task();
        $this->assertSame('offered', $this->entry_status($section['entries'][0]));
    }

    /**
     * Students who fail the self-registration check or have a blocking hold are passed over for the next student.
     */
    public function test_students_who_could_not_take_a_seat_are_passed_over(): void {
        $section = $this->full_section(3);
        [$withdrawn, $held, $free] = $section['entries'];
        students::save(['id' => $section['students'][0]->id, 'status' => 'withdrawn']);
        academic::place_hold($section['students'][1], ['holdtype' => 'financial', 'reason' => 'Test', 'blocksreg' => 1,
            'startdate' => time() - DAYSECS]);
        $this->free_the_seat($section);

        $this->run_task();

        $this->assertSame('waiting', $this->entry_status($withdrawn));
        $this->assertSame('waiting', $this->entry_status($held));
        $this->assertSame('offered', $this->entry_status($free));
    }

    /**
     * With self-registration off nobody could take up an offer, so none is made. A lapsed offer still ends.
     */
    public function test_nothing_is_offered_with_self_registration_off(): void {
        global $DB;
        $section = $this->full_section(2);
        [$firstid, $secondid] = $section['entries'];
        registration::end($section['regid'], 'drop');
        $DB->set_field(waitlist::TABLE, 'timeoffered', time() - (waitlist::HOLDDAYS * DAYSECS + 1), ['id' => $firstid]);
        $this->generator->set_settings(['selfreg' => 0]);
        $DB->delete_records(messages::LOG);

        $this->run_task();

        $this->assertSame('left', $this->entry_status($firstid));
        $this->assertSame('waiting', $this->entry_status($secondid));
        $this->assertSame(0, $DB->count_records(messages::LOG, ['msgkey' => 'waitseat']));
    }

    /**
     * After the add period nobody could take a seat, so none is offered.
     */
    public function test_nothing_is_offered_after_the_add_period(): void {
        global $DB;
        $section = $this->full_section(1);
        $this->free_the_seat($section);
        $DB->set_field('local_studentrecords_term', 'adddropend', time() - 3 * DAYSECS, ['id' => $section['offer']->termid]);

        $this->run_task();

        $this->assertSame('waiting', $this->entry_status($section['entries'][0]));
    }

    /**
     * A section that can't be done doesn't cost the other sections their offers, and the task still ends as failed, so
     * Moodle runs it again.
     */
    public function test_one_failed_section_does_not_stop_the_others(): void {
        global $DB;
        $broken = $this->full_section(1);
        $fine = $this->full_section(1);
        $this->free_the_seat($broken);
        $this->free_the_seat($fine);
        $DB->set_field(waitlist::TABLE, 'studentid', 999999, ['id' => $broken['entries'][0]]);

        try {
            $this->run_task();
            $this->fail('The task ended as if every section had been done.');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\dml_missing_record_exception::class, $e);
        }

        $this->assertSame('offered', $this->entry_status($fine['entries'][0]));
    }
}
