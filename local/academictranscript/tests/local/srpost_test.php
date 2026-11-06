<?php

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(srpost::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(posting::class)]
/**
 * Tests for final grades that go into Student Records.
 *
 * Every test needs Student Records and is skipped when it is not installed.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_academictranscript\local\srpost
 * @covers     \local_academictranscript\local\posting
 */
final class srpost_test extends \advanced_testcase {
    /** @var \stdClass The Moodle course. */
    private \stdClass $course;

    /** @var \stdClass The Moodle user of the student. */
    private \stdClass $user;

    /** @var int The registration in Student Records. */
    private int $regid;

    /** @var \stdClass The term in Student Records. */
    private \stdClass $srterm;

    /**
     * A course with a total of 91%, linked to a registration of the student in Student Records. The school's scale in
     * Student Records has its own letters: 90 and up is "Excellent", so the letter posted must come from there.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
        if (!class_exists('\\local_studentrecords\\local\\registration')) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        set_config('source', srsource::STUDENTRECORDS, 'local_academictranscript');
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $this->course = $generator->create_course_with_fields(['fullname' => 'Cell Biology', 'code' => 'BIO 101', 'hours' => 3,
            'hourtype' => 'credit', 'termid' => $term->id]);
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id, 'student');
        $generator->set_course_total($this->course->id, $this->user->id, 91.0);

        foreach ([['EX', 90, 100, 4], ['GD', 0, 89.99, 3]] as $i => [$letter, $min, $max, $points]) {
            $DB->insert_record('local_studentrecords_grade', (object)['letter' => $letter, 'minpct' => $min, 'maxpct' => $max,
                'points' => $points, 'ingpa' => 1, 'earns' => 0, 'sortorder' => $i, 'timemodified' => time()]);
        }
        $DB->insert_record('local_studentrecords_grade', (object)['letter' => 'P', 'ingpa' => 0, 'earns' => 1,
            'sortorder' => 5, 'timemodified' => time()]);
        $this->srterm = $sr->create_term();
        $catalog = $sr->create_course(['code' => 'BIO 101']);
        $offer = $sr->create_offering(['courseid' => $catalog->id, 'termid' => $this->srterm->id,
            'moodlecourseid' => $this->course->id]);
        $student = $sr->create_student(['userid' => $this->user->id, 'studentid' => 'S-1001']);
        $this->regid = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
    }

    /**
     * Posting saves the grade on the registration, with the letter of the school's scale in Student Records, and locks the
     * course total as before.
     */
    public function test_post_saves_on_the_registration(): void {
        global $DB;

        $posted = posting::post($this->course->id, $this->user->id);

        $reg = $DB->get_record('local_studentrecords_reg', ['id' => $this->regid]);
        $this->assertSame('EX', $posted->grade);
        $this->assertSame('EX', $reg->grade);
        $this->assertSame('completed', $reg->status);
        $this->assertSame('teacher', $reg->gradesource);
        $item = \grade_item::fetch_course_item($this->course->id);
        $this->assertTrue(\grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->user->id])->is_locked());
    }

    /**
     * A hand-entered mark on the school's scale goes in as it is.
     */
    public function test_post_saves_a_hand_entered_mark(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_code_row(['userid' => $this->user->id, 'courseid' => $this->course->id, 'code' => 'P']);

        posting::post($this->course->id, $this->user->id);

        $this->assertSame('P', $DB->get_field('local_studentrecords_reg', 'grade', ['id' => $this->regid]));
    }

    /**
     * A hand-entered mark that the school's scale doesn't have is an error, and nothing is posted.
     */
    public function test_mark_missing_from_the_scale_is_refused(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_code_row(['userid' => $this->user->id, 'courseid' => $this->course->id, 'code' => 'NC']);

        $preview = posting::preview($this->course->id, $this->user->id);

        $this->assertSame(get_string('err_teacherpost_notonscale', 'local_studentrecords'), $preview['error']);
        $this->assertSame(0, $DB->count_records('local_academictranscript_posted'));
    }

    /**
     * After the term's grade freeze a teacher can't post, and the preview says why.
     */
    public function test_grade_freeze_blocks_posting(): void {
        global $DB;
        $DB->set_field('local_studentrecords_term', 'gradefreeze', time() - 3 * DAYSECS, ['id' => $this->srterm->id]);

        $preview = posting::preview($this->course->id, $this->user->id);

        $this->assertStringContainsString('frozen', $preview['error']);
        try {
            posting::post($this->course->id, $this->user->id);
            $this->fail('The grade was posted after the freeze.');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannotpost', $e->errorcode);
        }
        $this->assertNull($DB->get_field('local_studentrecords_reg', 'grade', ['id' => $this->regid]));
        $this->assertSame(0, $DB->count_records('local_academictranscript_posted'));
    }

    /**
     * A grade the Registrar set is never replaced.
     */
    public function test_a_grade_staff_set_is_kept(): void {
        global $DB;
        \local_studentrecords\local\registration::set_grade($this->regid, 'GD', null, 'staff');

        $preview = posting::preview($this->course->id, $this->user->id);

        $this->assertStringContainsString('GD', $preview['error']);
        try {
            posting::post($this->course->id, $this->user->id);
            $this->fail('The grade was posted over the one staff set.');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannotpost', $e->errorcode);
        }
        $this->assertSame('GD', $DB->get_field('local_studentrecords_reg', 'grade', ['id' => $this->regid]));
        $this->assertSame(0, $DB->count_records('local_academictranscript_posted'));
    }

    /**
     * A posted grade remembers the registration that got it.
     */
    public function test_post_links_the_registration(): void {
        $posted = posting::post($this->course->id, $this->user->id);

        $this->assertSame($this->regid, $posted->srregid);
        $this->assertSame(['regid' => $this->regid, 'grade' => 'EX'], srpost::posted_target($posted));
    }

    /**
     * When the course runs again and the student has no new registration, posting stops. It doesn't replace the finished
     * attempt, which would drop that attempt from the transcript.
     */
    public function test_a_new_term_never_overwrites_a_finished_attempt(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        posting::post($this->course->id, $this->user->id);
        $next = $generator->create_term(['name' => 'Spring 2027']);
        $this->rerun_course_in($next);
        $generator->set_course_total($this->course->id, $this->user->id, 50.0);

        $preview = posting::preview($this->course->id, $this->user->id);

        $this->assertStringContainsString('EX', $preview['error']);
        $this->assertSame('EX', $DB->get_field('local_studentrecords_reg', 'grade', ['id' => $this->regid]));
    }

    /**
     * A change request on a posted row reaches the attempt that row belongs to, also when a later attempt exists.
     */
    public function test_each_posted_row_finds_its_own_attempt(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $sr = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $first = posting::post($this->course->id, $this->user->id);

        $this->rerun_course_in($generator->create_term(['name' => 'Spring 2027']));
        $offer = $sr->create_offering(['courseid' => $sr->create_course(['code' => 'BIO 102'])->id,
            'termid' => $sr->create_term()->id, 'moodlecourseid' => $this->course->id]);
        $student = \local_studentrecords\local\students::get_by_user($this->user->id);
        $newreg = $sr->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $second = posting::post($this->course->id, $this->user->id);

        $this->assertSame($this->regid, srpost::posted_target($first)['regid']);
        $this->assertSame($newreg, srpost::posted_target($second)['regid']);
        $this->assertNotSame($first->id, $second->id);
    }

    /**
     * A row with no link gives no target, so the change page can refuse it, and the student counts as having a file.
     */
    public function test_a_row_without_a_link_has_no_target(): void {
        $this->assertNull(srpost::posted_target((object)['srregid' => null]));
        $this->assertTrue(srpost::has_file($this->user->id));
    }

    /**
     * When Student Records refuses part way through, nothing is saved, nothing stays open, and the next student's grade
     * is saved for good. The page goes on to the next student after a failure.
     */
    public function test_a_failed_post_leaves_the_next_one_alone(): void {
        global $CFG, $DB;
        require_once(__DIR__ . '/../fixtures/refusing_lock_factory.php');
        $this->preventResetByRollback();

        $CFG->lock_factory = '\\local_academictranscript\\tests\\refusing_lock_factory';
        try {
            posting::post($this->course->id, $this->user->id);
            $this->fail('The grade was posted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_busy', $e->errorcode);
        }

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(0, $DB->count_records('local_academictranscript_posted'));

        unset($CFG->lock_factory);
        posting::post($this->course->id, $this->user->id);
        $this->assertSame(1, $DB->count_records('local_academictranscript_posted'));
        $this->assertSame('EX', $DB->get_field('local_studentrecords_reg', 'grade', ['id' => $this->regid]));
    }

    /**
     * A row that can't post still shows the letter of the school's scale, the one the Status column shows beside it.
     */
    public function test_a_row_that_cannot_post_shows_the_letter_of_the_school(): void {
        global $DB;
        $DB->set_field('local_studentrecords_term', 'gradefreeze', time() - 3 * DAYSECS, ['id' => $this->srterm->id]);
        $this->assertSame('EX', posting::preview($this->course->id, $this->user->id)['grade']);

        $DB->set_field('local_studentrecords_term', 'gradefreeze', null, ['id' => $this->srterm->id]);
        posting::post($this->course->id, $this->user->id);
        $this->assertSame('EX', posting::preview($this->course->id, $this->user->id)['grade']);
    }

    /**
     * A student who has no registration for the course can't be posted, and the error says to ask the Registrar.
     */
    public function test_no_registration(): void {
        global $DB;
        $DB->delete_records('local_studentrecords_reg', ['id' => $this->regid]);

        $preview = posting::preview($this->course->id, $this->user->id);

        $this->assertSame(get_string('err_teacherpost_noreg', 'local_studentrecords'), $preview['error']);
    }

    /**
     * A change to a posted grade is a grade change request in Student Records, and only one can wait at a time.
     */
    public function test_request_change_goes_to_student_records(): void {
        global $DB;
        posting::post($this->course->id, $this->user->id);

        $this->assertFalse(srpost::change_pending($this->regid));
        $id = srpost::request_change($this->regid, 'GD', 'The exam was marked again.');

        $this->assertTrue(srpost::change_pending($this->regid));
        $this->assertSame('GD', $DB->get_field(\local_studentrecords\local\academic::CHANGES, 'newgrade', ['id' => $id]));
        $this->assertSame('EX', $DB->get_field('local_studentrecords_reg', 'grade', ['id' => $this->regid]));
    }

    /**
     * Point the course at another term, as a school does when the same Moodle course runs again.
     *
     * @param \stdClass $term
     */
    private function rerun_course_in(\stdClass $term): void {
        global $DB;
        $field = $DB->get_record('customfield_field', ['shortname' => 'transcript_term'], '*', MUST_EXIST);
        $data = \core_customfield\api::get_instance_fields_data(
            [$field->id => \core_customfield\field_controller::create($field->id)],
            $this->course->id
        );
        $value = reset($data);
        $value->set($value->datafield(), (int)$term->optionindex);
        $value->save();
    }

    /**
     * The change request menu offers the letters and the marks of the school's scale in Student Records.
     */
    public function test_letters_include_the_marks(): void {
        $this->assertSame(['EX' => 'EX', 'GD' => 'GD', 'P' => 'P'], srpost::letters());
    }
}
