<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(waitlist::class)]
/**
 * Tests for waitlists: a freed seat goes to the first student waiting, is held for them, and then moves on.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\waitlist
 */
final class waitlist_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $gen;

    /** @var \stdClass Section with one seat. */
    private $offer;

    /** @var \stdClass The student in the seat. */
    private $seated;

    /** @var int The registration in the seat. */
    private $regid;

    /**
     * A full section with one seat, signed in as the admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $this->gen->set_settings(['selfreg' => 1]);
        $term = $this->gen->create_term();
        $this->offer = $this->gen->create_offering(['courseid' => $this->gen->create_course()->id, 'termid' => $term->id,
            'capacity' => 1]);
        $this->seated = $this->student();
        $this->regid = registration::add($this->seated, (int)$this->offer->id);
    }

    /**
     * A new student.
     *
     * @return \stdClass
     */
    private function student(): \stdClass {
        static $n = 0;
        $n++;
        return $this->gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'W-' . $n]);
    }

    /**
     * The freed seat goes to the first student waiting, the next keeps waiting, and the place in line shows it.
     */
    public function test_the_first_in_line_is_offered_the_seat(): void {
        $first = $this->student();
        $second = $this->student();
        waitlist::join($first, (int)$this->offer->id);
        waitlist::join($second, (int)$this->offer->id);
        $this->assertSame(2, waitlist::position((int)$second->id, (int)$this->offer->id));

        registration::end($this->regid, 'drop');

        $this->assertSame('offered', waitlist::entry((int)$first->id, (int)$this->offer->id)->status);
        $this->assertSame('waiting', waitlist::entry((int)$second->id, (int)$this->offer->id)->status);
    }

    /**
     * A held seat is taken for everyone else, and the student it is held for can take it.
     */
    public function test_a_held_seat_is_only_for_the_student_offered_it(): void {
        $first = $this->student();
        $stranger = $this->student();
        waitlist::join($first, (int)$this->offer->id);
        registration::end($this->regid, 'drop');

        try {
            registration::add($stranger, (int)$this->offer->id);
            $this->fail('A held seat went to somebody else.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_full', $e->errorcode);
        }

        registration::add($first, (int)$this->offer->id);
        $this->assertNull(waitlist::entry((int)$first->id, (int)$this->offer->id));
        $this->assertSame(1, registration::taken((int)$this->offer->id));
    }

    /**
     * An offer not taken in time lapses, the student stays off the list, and the seat goes to the next one.
     */
    public function test_a_lapsed_offer_goes_to_the_next_student(): void {
        global $DB;
        $first = $this->student();
        $second = $this->student();
        $firstid = waitlist::join($first, (int)$this->offer->id);
        waitlist::join($second, (int)$this->offer->id);
        registration::end($this->regid, 'drop');
        $DB->set_field(waitlist::TABLE, 'timeoffered', time() - (waitlist::HOLDDAYS * DAYSECS + 1), ['id' => $firstid]);

        $this->assertSame(1, waitlist::daily());

        $this->assertSame('left', $DB->get_field(waitlist::TABLE, 'status', ['id' => $firstid]));
        $this->assertSame('offered', waitlist::entry((int)$second->id, (int)$this->offer->id)->status);
    }

    /**
     * A student who leaves with an offer passes the seat to the next one.
     */
    public function test_leaving_passes_the_seat_on(): void {
        $first = $this->student();
        $second = $this->student();
        $firstid = waitlist::join($first, (int)$this->offer->id);
        waitlist::join($second, (int)$this->offer->id);
        registration::end($this->regid, 'drop');

        waitlist::leave($firstid);

        $this->assertNull(waitlist::entry((int)$first->id, (int)$this->offer->id));
        $this->assertSame('offered', waitlist::entry((int)$second->id, (int)$this->offer->id)->status);
    }

    /**
     * Two seats opened at once offer two students, and no more seats than are free.
     */
    public function test_only_free_seats_are_offered(): void {
        global $DB;
        $students = [$this->student(), $this->student(), $this->student()];
        foreach ($students as $s) {
            waitlist::join($s, (int)$this->offer->id);
        }
        $DB->set_field(registration::OFFER, 'capacity', 3, ['id' => $this->offer->id]);

        $this->assertSame(2, waitlist::seat_opened((int)$this->offer->id));
        $this->assertSame(0, waitlist::seat_opened((int)$this->offer->id));

        $this->assertSame('waiting', waitlist::entry((int)$students[2]->id, (int)$this->offer->id)->status);
        $this->assertSame(2, waitlist::held_seats((int)$this->offer->id));
        $this->assertSame(1, waitlist::held_seats((int)$this->offer->id, (int)$students[0]->id));
    }

    /**
     * After the add period nobody could take a seat, so a withdrawal offers none: no message, no held seat.
     */
    public function test_no_seat_is_offered_after_the_add_period(): void {
        global $DB;
        $first = $this->student();
        waitlist::join($first, (int)$this->offer->id);
        $DB->set_field('local_studentrecords_term', 'adddropend', time() - 3 * DAYSECS, ['id' => $this->offer->termid]);

        registration::end($this->regid, 'withdraw', true, 'Moved away');

        $this->assertSame('waiting', waitlist::entry((int)$first->id, (int)$this->offer->id)->status);
    }

    /**
     * Students can't register in a term that isn't active, so a withdrawal there offers no seat.
     */
    public function test_no_seat_is_offered_in_a_term_that_is_not_active(): void {
        global $DB;
        $first = $this->student();
        waitlist::join($first, (int)$this->offer->id);
        $DB->set_field('local_studentrecords_term', 'active', 0, ['id' => $this->offer->termid]);

        registration::end($this->regid, 'withdraw', true, 'Moved away');

        $this->assertSame('waiting', waitlist::entry((int)$first->id, (int)$this->offer->id)->status);
    }

    /**
     * Joining twice gives the same place; a student with a seat can't join; a section without a limit has no waitlist.
     */
    public function test_join_checks(): void {
        global $DB;
        $first = $this->student();
        $id = waitlist::join($first, (int)$this->offer->id);
        $this->assertSame($id, waitlist::join($first, (int)$this->offer->id));
        try {
            waitlist::join($this->seated, (int)$this->offer->id);
            $this->fail('A student with a seat joined the waitlist.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_alreadyregistered', $e->errorcode);
        }
        $DB->set_field(registration::OFFER, 'status', 'cancelled', ['id' => $this->offer->id]);
        try {
            waitlist::join($this->student(), (int)$this->offer->id);
            $this->fail('A student joined a cancelled section.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('offerstatus_cancelled', 'local_studentrecords'), $e->getMessage());
        }
    }

    /**
     * A student who couldn't take a seat stays on the list and uses none: the next student gets the offer.
     */
    public function test_a_student_who_cannot_register_is_passed_over(): void {
        global $DB;
        [$first, $second] = [$this->student(), $this->student()];
        waitlist::join($first, (int)$this->offer->id);
        waitlist::join($second, (int)$this->offer->id);
        $DB->set_field('local_studentrecords_student', 'status', 'withdrawn', ['id' => $first->id]);

        registration::end($this->regid, 'drop', true, 'test');

        $this->assertSame('waiting', waitlist::entry((int)$first->id, (int)$this->offer->id)->status);
        $this->assertSame('offered', waitlist::entry((int)$second->id, (int)$this->offer->id)->status);
    }

    /**
     * With self-registration off nobody could take up an offer, so none is made.
     */
    public function test_no_offers_without_self_registration(): void {
        $first = $this->student();
        waitlist::join($first, (int)$this->offer->id);
        set_config('selfreg', '0', 'local_studentrecords');

        registration::end($this->regid, 'drop', true, 'test');

        $this->assertSame('waiting', waitlist::entry((int)$first->id, (int)$this->offer->id)->status);
    }

    /**
     * A prerequisite added after the student joined means they couldn't take the seat. They are passed over and keep their
     * place, and the next student gets the offer at once instead of two days later.
     */
    public function test_a_student_who_lacks_a_prerequisite_is_passed_over(): void {
        global $DB;
        [$first, $second] = [$this->student(), $this->student()];
        waitlist::join($first, (int)$this->offer->id);
        waitlist::join($second, (int)$this->offer->id);
        $required = $this->gen->create_course(['code' => 'BIO 100']);
        $DB->insert_record('local_studentrecords_prereq', (object)['courseid' => $this->offer->courseid,
            'reqcourseid' => $required->id]);
        $DB->insert_record('local_studentrecords_xfer', (object)['studentid' => $second->id, 'institution' => 'Riverside',
            'credits' => 3, 'equivcourseid' => $required->id, 'timecreated' => time()]);

        registration::end($this->regid, 'drop', true, 'test');

        $this->assertSame('waiting', waitlist::entry((int)$first->id, (int)$this->offer->id)->status);
        $this->assertSame('offered', waitlist::entry((int)$second->id, (int)$this->offer->id)->status);
    }

    /**
     * A section that no longer has a limit offers a seat to everyone waiting.
     */
    public function test_a_section_with_no_limit_offers_everyone(): void {
        global $DB;
        $students = [$this->student(), $this->student(), $this->student()];
        foreach ($students as $s) {
            waitlist::join($s, (int)$this->offer->id);
        }
        $DB->set_field(registration::OFFER, 'capacity', null, ['id' => $this->offer->id]);

        $this->assertSame(3, waitlist::seat_opened((int)$this->offer->id));

        foreach ($students as $s) {
            $this->assertSame('offered', waitlist::entry((int)$s->id, (int)$this->offer->id)->status);
        }
    }
}
