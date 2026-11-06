<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(selfreg::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(waitlist::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(record_view::class)]
/**
 * Tests for self-registration rules (class days and times, clashes), waitlist seat holds and record view tones.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\selfreg
 * @covers     \local_studentrecords\local\waitlist
 * @covers     \local_studentrecords\local\record_view
 */
final class selfreg_test extends \advanced_testcase {
    /**
     * Self-registration on, a term open for adding and a student on an empty site.
     *
     * @param array $offer more fields of the section, such as capacity
     * @return array gen, student, offer, term
     */
    private function setup_selfreg(array $offer = []): array {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $gen->set_settings(['selfreg' => 1]);
        $term = $gen->create_term();
        $DB->set_field('local_studentrecords_term', 'active', 1, ['id' => $term->id]);
        $section = $gen->create_offering(['courseid' => $gen->create_course()->id, 'termid' => $term->id] + $offer);
        $student = $gen->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-1']);
        $this->setUser((int)$student->userid);
        return ['gen' => $gen, 'student' => $student, 'offer' => $section, 'term' => $term];
    }

    /**
     * Run some code and give back the error code of the Moodle exception it ends with.
     *
     * @param callable $work
     * @return string
     */
    private function error_of(callable $work): string {
        try {
            $work();
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        $this->fail('No exception was thrown.');
    }

    /**
     * Cart to confirmation: the class is registered, the cart is emptied, and the confirmation number is given.
     */
    public function test_confirm_registers_the_cart(): void {
        global $DB;
        $s = $this->setup_selfreg();
        selfreg::add($s['student'], (int)$s['offer']->id);
        $this->assertArrayHasKey((int)$s['offer']->id, selfreg::cart($s['student'], (int)$s['term']->id));

        $result = selfreg::confirm($s['student'], (int)$s['term']->id);

        $this->assertCount(1, $result['done']);
        $this->assertStringStartsWith('SR-', $result['confirmation']);
        $this->assertSame([], selfreg::cart($s['student'], (int)$s['term']->id));
        $this->assertSame('registered', $DB->get_field(registration::REG, 'status', ['studentid' => $s['student']->id]));
        $this->assertArrayHasKey((int)$s['offer']->id, selfreg::registered($s['student'], (int)$s['term']->id));
    }

    /**
     * A student acts only on their own cart: taking a section out of one cart leaves the same section in another.
     */
    public function test_carts_are_separate(): void {
        $s = $this->setup_selfreg();
        $other = $s['gen']->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-2']);
        selfreg::add($s['student'], (int)$s['offer']->id);
        selfreg::add($other, (int)$s['offer']->id);

        selfreg::remove($other, (int)$s['offer']->id);

        $this->assertCount(1, selfreg::cart($s['student'], (int)$s['term']->id));
        $this->assertSame([], selfreg::cart($other, (int)$s['term']->id));
    }

    /**
     * A hold stops every step that a direct request can reach, even when the class is already in the cart.
     */
    public function test_a_hold_stops_a_direct_request(): void {
        global $DB;
        $s = $this->setup_selfreg(['capacity' => 1]);
        $student = $s['student'];
        $offerid = (int)$s['offer']->id;
        selfreg::add($student, $offerid);
        academic::place_hold($student, ['holdtype' => 'financial', 'reason' => 'Balance', 'blocksreg' => 1,
            'startdate' => time() - 100]);

        $this->assertSame('err_hold', $this->error_of(fn() => selfreg::add($student, $offerid)));
        $this->assertSame('err_hold', $this->error_of(fn() => selfreg::wait($student, $offerid)));
        $result = selfreg::confirm($student, (int)$s['term']->id);

        $this->assertSame([], $result['done']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame(0, $DB->count_records(registration::REG));
    }

    /**
     * A missing prerequisite stops the cart and the waitlist.
     */
    public function test_a_prerequisite_stops_a_direct_request(): void {
        global $DB;
        $s = $this->setup_selfreg();
        $DB->insert_record('local_studentrecords_prereq', (object)['courseid' => $s['offer']->courseid,
            'reqcourseid' => $s['gen']->create_course()->id]);
        $this->assertSame('err_prereqs', $this->error_of(fn() => selfreg::add($s['student'], (int)$s['offer']->id)));
        $this->assertSame('err_prereqs', $this->error_of(fn() => selfreg::wait($s['student'], (int)$s['offer']->id)));
    }

    /**
     * The waitlist obeys the same switch and term as the cart, and a section with seats has none.
     */
    public function test_wait_needs_a_full_section_in_an_open_term(): void {
        global $DB;
        $s = $this->setup_selfreg(['capacity' => 1]);
        $student = $s['student'];
        $offerid = (int)$s['offer']->id;
        $this->assertSame('err_notfull', $this->error_of(fn() => selfreg::wait($student, $offerid)));

        $other = $s['gen']->create_student(['userid' => $this->getDataGenerator()->create_user()->id, 'studentid' => 'S-2']);
        registration::add($other, $offerid);
        $s['gen']->set_settings(['selfreg' => 0]);
        $this->assertSame('err_selfregoff', $this->error_of(fn() => selfreg::wait($student, $offerid)));
        $s['gen']->set_settings(['selfreg' => 1]);
        $DB->set_field('local_studentrecords_term', 'active', 0, ['id' => $s['term']->id]);
        $this->assertSame('err_regclosed', $this->error_of(fn() => selfreg::wait($student, $offerid)));
        $this->assertSame(0, $DB->count_records(waitlist::TABLE));

        $DB->set_field('local_studentrecords_term', 'active', 1, ['id' => $s['term']->id]);
        selfreg::wait($student, $offerid);
        $this->assertSame('waiting', waitlist::entry((int)$student->id, $offerid)->status);
    }

    /**
     * Classes in the cart that clash stop the confirmation, whatever the page showed.
     */
    public function test_a_time_clash_stops_the_confirmation(): void {
        global $DB;
        $s = $this->setup_selfreg();
        $second = $s['gen']->create_offering(['courseid' => $s['gen']->create_course()->id, 'termid' => $s['term']->id]);
        foreach ([$s['offer'], $second] as $offer) {
            $DB->update_record(registration::OFFER, (object)['id' => $offer->id, 'meetdays' => 'mon', 'starttime' => '09:00',
                'endtime' => '10:00']);
            selfreg::add($s['student'], (int)$offer->id);
        }
        $this->assertSame('err_timeclash', $this->error_of(fn() => selfreg::confirm($s['student'], (int)$s['term']->id)));
        $this->assertSame(0, $DB->count_records(registration::REG));
    }

    /**
     * A class that fails stays in the cart with its reason, the transaction ends, and the next class still registers.
     */
    public function test_a_failed_class_does_not_stop_the_next_one(): void {
        global $DB;
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $DB->delete_records('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $s = $this->setup_selfreg(['moodlecourseid' => $course->id]);
        $second = $s['gen']->create_offering(['courseid' => $s['gen']->create_course()->id, 'termid' => $s['term']->id]);
        selfreg::add($s['student'], (int)$s['offer']->id);
        selfreg::add($s['student'], (int)$second->id);

        $result = selfreg::confirm($s['student'], (int)$s['term']->id);

        $this->assertFalse($DB->is_transaction_started());
        $this->assertCount(1, $result['failed']);
        $this->assertCount(1, $result['done']);
        $this->assertSame((int)$second->id, (int)$result['done'][0]->id);
        $this->assertArrayHasKey((int)$s['offer']->id, selfreg::cart($s['student'], (int)$s['term']->id));
        $this->assertSame(1, $DB->count_records(registration::REG));
    }

    /**
     * Self-registration switched off stops the cart.
     */
    public function test_self_registration_off(): void {
        $s = $this->setup_selfreg();
        $s['gen']->set_settings(['selfreg' => 0]);
        $this->assertSame('err_selfregoff', $this->error_of(fn() => selfreg::add($s['student'], (int)$s['offer']->id)));
        $this->assertSame('err_selfregoff', $this->error_of(fn() => selfreg::confirm($s['student'], (int)$s['term']->id)));
    }

    /**
     * Admitted, enrolled and active students may register themselves. A student with any other status can't add a class,
     * join a waitlist or confirm a cart, and that holds for a request sent straight to the page.
     */
    public function test_only_some_statuses_register_themselves(): void {
        global $DB;
        ['student' => $student, 'offer' => $offer, 'term' => $term] = $this->setup_selfreg();
        selfreg::add($student, (int)$offer->id);
        $allowed = [];
        foreach (catalog::STATUSES as $status) {
            $student->status = $status;
            if (selfreg::status_allows($student)) {
                $allowed[] = $status;
                continue;
            }
            $this->assertSame('err_selfregstatus', $this->error_of(fn() => selfreg::add($student, (int)$offer->id)), $status);
            $this->assertSame('err_selfregstatus', $this->error_of(fn() => selfreg::wait($student, (int)$offer->id)), $status);
            $this->assertSame('err_selfregstatus', $this->error_of(fn() => selfreg::confirm($student, (int)$term->id)), $status);
        }

        sort($allowed);
        $this->assertSame(['active', 'admitted', 'enrolled'], $allowed);
        $this->assertSame(0, $DB->count_records(registration::REG));
    }

    /**
     * A student who can't pick classes reads why. A term that isn't open and self-registration being off are each told
     * apart, and a student who can pick classes reads nothing.
     */
    public function test_closed_notice_tells_the_student_why(): void {
        global $DB;
        ['gen' => $gen, 'student' => $student, 'term' => $term] = $this->setup_selfreg();

        $this->assertNull(selfreg::closed_notice($student));

        $student->status = 'withdrawn';
        $this->assertSame(
            get_string('err_selfregstatus', 'local_studentrecords', get_string('status_withdrawn', 'local_studentrecords')),
            selfreg::closed_notice($student)
        );

        $student->status = 'active';
        $DB->set_field('local_studentrecords_term', 'active', 0, ['id' => $term->id]);
        $this->assertSame(get_string('myreg_noterms', 'local_studentrecords'), selfreg::closed_notice($student));

        $gen->set_settings(['selfreg' => 0]);
        $this->assertSame(get_string('myreg_off', 'local_studentrecords'), selfreg::closed_notice($student));
    }

    /**
     * What a student picked or waits for shows in every term, so a student who can't register can still take it back.
     */
    public function test_pending_lists_the_cart_and_waitlists(): void {
        ['gen' => $gen, 'student' => $student, 'offer' => $offer, 'term' => $term] = $this->setup_selfreg();
        selfreg::add($student, (int)$offer->id);
        $full = $gen->create_offering(['courseid' => $gen->create_course()->id, 'termid' => $term->id, 'capacity' => 0]);
        selfreg::wait($student, (int)$full->id);

        $pending = selfreg::pending($student);

        $this->assertSame([(int)$offer->id], array_keys($pending['cart']));
        $this->assertSame([(int)$full->id], array_keys($pending['waits']));
        $this->assertSame('waiting', $pending['waits'][(int)$full->id]->waitstatus);

        waitlist::leave((int)waitlist::entry((int)$student->id, (int)$full->id)->id);
        $this->assertSame([], selfreg::pending($student)['waits']);
    }

    /**
     * Leaving is always possible: a student who can no longer register still takes a class out of the cart.
     */
    public function test_a_stopped_student_can_still_empty_the_cart(): void {
        global $DB;
        ['student' => $student, 'offer' => $offer] = $this->setup_selfreg();
        selfreg::add($student, (int)$offer->id);
        $student->status = 'withdrawn';

        selfreg::remove($student, (int)$offer->id);

        $this->assertSame(0, $DB->count_records(selfreg::CART));
    }

    /**
     * Class days are cleaned to known codes in week order.
     */
    public function test_clean_days(): void {
        $this->assertSame('tue,thu', selfreg::clean_days('thu, Tue'));
        $this->assertSame('mon,wed,fri', selfreg::clean_days('fri,mon,xyz,wed'));
        $this->assertSame('', selfreg::clean_days(''));
    }

    /**
     * Times are HH:MM in 24 hours.
     */
    public function test_valid_time(): void {
        $this->assertTrue(selfreg::valid_time('09:00'));
        $this->assertTrue(selfreg::valid_time('23:59'));
        $this->assertFalse(selfreg::valid_time('9:00'));
        $this->assertFalse(selfreg::valid_time('24:00'));
        $this->assertFalse(selfreg::valid_time(''));
    }

    /**
     * A clash needs a shared day and overlapping times; touching times and missing times never clash.
     */
    public function test_times_clash(): void {
        $this->assertTrue(selfreg::times_clash('tue,thu', '09:00', '11:30', 'thu', '11:00', '12:00'));
        $this->assertFalse(selfreg::times_clash('tue,thu', '09:00', '11:30', 'thu', '11:30', '12:30'));
        $this->assertFalse(selfreg::times_clash('mon,wed', '09:00', '11:30', 'tue', '09:00', '11:30'));
        $this->assertFalse(selfreg::times_clash('', '', '', 'tue', '09:00', '11:30'));
        $this->assertFalse(selfreg::times_clash('tue', '09:00', '', 'tue', '09:00', '10:00'));
    }

    /**
     * Short times drop a leading zero; confirmation numbers carry term and student.
     */
    public function test_short_time_and_confirmation(): void {
        $this->assertSame('9:00', selfreg::short_time('09:00'));
        $this->assertSame('14:30', selfreg::short_time('14:30'));
        $expected = 'SR-12-345-' . strtoupper(base_convert('1767225600', 10, 36));
        $this->assertSame($expected, selfreg::confirmation(12, 345, 1767225600));
    }

    /**
     * An offered seat is held for two days.
     */
    public function test_offer_held(): void {
        $t = 1767225600;
        $this->assertTrue(waitlist::offer_held($t, $t + DAYSECS));
        $this->assertFalse(waitlist::offer_held($t, $t + 2 * DAYSECS));
        $this->assertFalse(waitlist::offer_held(0, $t));
    }

    /**
     * Attendance tones and progress percent.
     */
    public function test_record_view_rules(): void {
        $this->assertSame('bad', record_view::attendance_tone(79.0, 80));
        $this->assertSame('warn', record_view::attendance_tone(84.0, 80));
        $this->assertSame('', record_view::attendance_tone(85.0, 80));
        $this->assertSame('', record_view::attendance_tone(null, 80));
        $this->assertSame(56, record_view::percent(38, 68));
        $this->assertSame(100, record_view::percent(80, 68));
        $this->assertSame(0, record_view::percent(5, 0));
    }
}
