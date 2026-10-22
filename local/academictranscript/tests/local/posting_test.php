<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(posting::class)]
/**
 * Tests for posting final grades and for approved changes to posted grades.
 *
 * The PHPUnit site starts with the standard scale (12 letters) and the standard marks (P, NP, I, NC, W, TR, IP).
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\posting
 */
final class posting_test extends \advanced_testcase {
    /**
     * Start each test as the admin user, with an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
        $this->setAdminUser();
    }

    /**
     * Preview names the first field that the course lacks: the code, then the hours, then the term. A course total
     * that is ready does not change that.
     *
     * @dataProvider missing_fields_provider
     * @param array $fields The Transcript fields the course has (a term is added unless the data set says termid 0)
     * @param string $errorkey The language string id of the message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missing_fields_provider')]
    public function test_preview_reports_missing_fields(array $fields, string $errorkey): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $user = $this->getDataGenerator()->create_user();
        $course = $generator->create_course_with_fields($fields + ['termid' => $term->id]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $generator->set_course_total($course->id, $user->id, 90);

        $preview = posting::preview($course->id, $user->id);

        $this->assertSame(get_string($errorkey, 'local_academictranscript'), $preview['error']);
    }

    /**
     * Data for test_preview_reports_missing_fields.
     *
     * @return array
     */
    public static function missing_fields_provider(): array {
        return [
            'no course code' => [['hours' => 3, 'hourtype' => 'credit'], 'missingcode'],
            'no hours and nothing in the catalog' => [['code' => 'BUS 101', 'hourtype' => 'credit'], 'missinghours'],
            'no term' => [['code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'credit', 'termid' => 0], 'missingterm'],
        ];
    }

    /**
     * Without a hand-entered code, the grade is the letter for the course total, and the total is kept as a
     * percentage. The scale rounds the percentage first, so 92.6 is an A.
     */
    public function test_preview_uses_course_total_letter(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        ['user' => $user, 'course' => $course, 'term' => $term] = $this->make_student_course();
        $generator->set_course_total($course->id, $user->id, 88.6);

        $preview = posting::preview($course->id, $user->id);

        $this->assertSame('', $preview['error']);
        $this->assertSame('B+', $preview['grade']);
        $this->assertEqualsWithDelta(88.6, $preview['percent'], 0.001);
        $this->assertSame((int)$term->id, $preview['termid']);
        $this->assertNull($preview['posted']);

        $generator->set_course_total($course->id, $user->id, 92.6);

        $preview = posting::preview($course->id, $user->id);
        $this->assertSame('A', $preview['grade']);
        $this->assertEqualsWithDelta(92.6, $preview['percent'], 0.001);
    }

    /**
     * A code that a teacher entered (a mark such as W) is the grade, whatever the course total is. A system mark
     * such as IP is not accepted from a teacher, so the course total decides then.
     */
    public function test_preview_prefers_teacher_code(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        ['user' => $user, 'course' => $course] = $this->make_student_course();
        $generator->set_course_total($course->id, $user->id, 88.6);
        $rowid = $generator->create_code_row(['userid' => $user->id, 'courseid' => $course->id, 'code' => 'W',
            'note' => 'Left in week 3'])->id;

        $preview = posting::preview($course->id, $user->id);

        $this->assertSame('', $preview['error']);
        $this->assertSame('W', $preview['grade']);
        $this->assertNull($preview['percent']);
        $this->assertSame('Left in week 3', $preview['note']);

        $DB->set_field('local_academictranscript_code', 'code', 'IP', ['id' => $rowid]);

        $preview = posting::preview($course->id, $user->id);
        $this->assertSame('B+', $preview['grade']);
        $this->assertEqualsWithDelta(88.6, $preview['percent'], 0.001);
    }

    /**
     * With no code and no course total there is nothing to post. That holds when the course has no gradebook yet
     * and when the course has totals for other students only.
     */
    public function test_preview_without_grade(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        ['user' => $user, 'course' => $course] = $this->make_student_course();

        $preview = posting::preview($course->id, $user->id);

        $this->assertSame(get_string('nogradetopost', 'local_academictranscript'), $preview['error']);
        $this->assertNull($preview['grade']);
        $this->assertNull($preview['percent']);

        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $generator->set_course_total($course->id, $other->id, 70);

        $preview = posting::preview($course->id, $user->id);
        $this->assertSame(get_string('nogradetopost', 'local_academictranscript'), $preview['error']);
        $this->assertNull($preview['grade']);
    }

    /**
     * Posting saves one attempt with the course details as they are now, keeps the note of the hand-entered row and
     * clears that row's code and note, locks the student's course total, and writes the log.
     */
    public function test_post_saves_attempt_locks_total_and_clears_code(): void {
        global $DB, $USER;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        ['user' => $user, 'course' => $course, 'term' => $term] = $this->make_student_course(
            ['fullname' => 'BUS 101 - Introduction to Business']
        );
        $generator->set_course_total($course->id, $user->id, 95);
        $rowid = $generator->create_code_row(['userid' => $user->id, 'courseid' => $course->id,
            'note' => 'Late project accepted'])->id;
        $this->assertFalse($this->course_total($course->id, $user->id)->is_locked());

        $record = posting::post($course->id, $user->id);

        $posted = $DB->get_record('local_academictranscript_posted', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertEquals($user->id, $posted->userid);
        $this->assertEquals($course->id, $posted->courseid);
        $this->assertSame((int)$term->id, (int)$posted->termid);
        $this->assertSame('BUS 101', $posted->coursecode);
        $this->assertSame('Introduction to Business', $posted->coursename);
        $this->assertEquals(3.0, $posted->hours);
        $this->assertSame('credit', $posted->unittype);
        $this->assertSame('A', $posted->grade);
        $this->assertEqualsWithDelta(95.0, (float)$posted->percent, 0.00001);
        $this->assertSame('Late project accepted', $posted->note);
        $this->assertEquals($USER->id, $posted->postedby);

        $this->assertTrue($this->course_total($course->id, $user->id)->is_locked());

        $row = $DB->get_record('local_academictranscript_code', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertNull($row->code);
        $this->assertSame('', $row->note);
        $this->assertSame(get_string('clearedonpost', 'local_academictranscript'), $row->reason);

        $log = $DB->get_record('local_academictranscript_log', ['action' => 'post'], '*', MUST_EXIST);
        $this->assertEquals($user->id, $log->studentid);
        $this->assertSame("course {$course->id} term {$term->id} grade A", $log->info);
    }

    /**
     * The course title is stored as plain text, as it was typed: an ampersand is "&" in the posted grade, not "&amp;".
     * The pages and the PDF escape it when they print it.
     */
    public function test_post_stores_plain_course_name(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        ['user' => $user, 'course' => $course] = $this->make_student_course(['fullname' => 'BUS 101 - Sales & Marketing']);
        $generator->set_course_total($course->id, $user->id, 95);

        $record = posting::post($course->id, $user->id);

        $this->assertSame(
            'Sales & Marketing',
            $DB->get_field('local_academictranscript_posted', 'coursename', ['id' => $record->id])
        );
    }

    /**
     * A title that starts with a curly quote is stored whole. The dash in front of it is cut off by character, not by
     * byte: the bytes of a curly quote begin like the bytes of a dash, and a cut through the quote leaves text that is
     * not valid UTF-8, which the database refuses.
     */
    public function test_post_stores_a_title_that_starts_with_a_curly_quote(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $titles = ['BUS 101' => "\u{201C}Quoted\u{201D} title", 'BUS 102' => "\u{2018}Quoted\u{2019} title"];
        $stored = [];
        foreach ($titles as $code => $title) {
            ['user' => $user, 'course' => $course] = $this->make_student_course(['fullname' => "$code - $title",
                'code' => $code]);
            $generator->set_course_total($course->id, $user->id, 95);

            $record = posting::post($course->id, $user->id);

            $stored[$code] = $DB->get_field('local_academictranscript_posted', 'coursename', ['id' => $record->id]);
        }

        $this->assertSame($titles, $stored);
    }

    /**
     * The final grade of a course is posted once for a term. A second post is refused and changes nothing.
     */
    public function test_post_twice_in_same_term_fails(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        ['user' => $user, 'course' => $course] = $this->make_student_course();
        $generator->set_course_total($course->id, $user->id, 95);
        posting::post($course->id, $user->id);

        $error = $this->failure(fn() => posting::post($course->id, $user->id));

        $this->assertSame('alreadyposted', $error->errorcode);
        $this->assertSame(1, $DB->count_records('local_academictranscript_posted'));
        $this->assertSame(1, $DB->count_records('local_academictranscript_log', ['action' => 'post']));
    }

    /**
     * A change to a posted grade is asked for with a reason and waits for approval. The new grade must be on the
     * scale, and only one request can wait at a time. Approving changes the grade and note and drops the stored
     * percentage, because the new letter was not decided by it.
     */
    public function test_change_request_and_approval(): void {
        global $DB, $USER;

        $posted = $this->make_posted_grade('B', 85.5);

        $this->assertSame('invalidgrade', $this->failure(
            fn() => posting::request_change($posted->id, 'Z', 'note', 'reason')
        )->errorcode);
        $this->assertSame('reasonmissing', $this->failure(
            fn() => posting::request_change($posted->id, 'A', 'note', '')
        )->errorcode);
        $this->assertSame(0, $DB->count_records('local_academictranscript_change'));

        $changeid = posting::request_change($posted->id, 'A', 'note', 'reason');

        $change = $DB->get_record('local_academictranscript_change', ['id' => $changeid], '*', MUST_EXIST);
        $this->assertSame('pending', $change->status);
        $this->assertEquals($posted->id, $change->postedid);
        $this->assertSame('B', $change->oldgrade);
        $this->assertSame('A', $change->newgrade);
        $this->assertSame('Original note', $change->oldnote);
        $this->assertSame('note', $change->newnote);
        $this->assertSame('reason', $change->reason);
        $this->assertEquals($USER->id, $change->requestedby);
        $this->assertSame('changepending', $this->failure(
            fn() => posting::request_change($posted->id, 'A', 'note', 'reason')
        )->errorcode);
        // Nothing changes until the request is decided.
        $this->assertSame('B', $DB->get_field('local_academictranscript_posted', 'grade', ['id' => $posted->id]));

        posting::decide($changeid, true, 'ok');

        $now = $DB->get_record('local_academictranscript_posted', ['id' => $posted->id], '*', MUST_EXIST);
        $this->assertSame('A', $now->grade);
        $this->assertSame('note', $now->note);
        $this->assertNull($now->percent);
        $change = $DB->get_record('local_academictranscript_change', ['id' => $changeid], '*', MUST_EXIST);
        $this->assertSame('approved', $change->status);
        $this->assertSame('ok', $change->decisionnote);
        $this->assertEquals($USER->id, $change->decidedby);
        $this->assertNotEmpty($change->timedecided);

        $actions = $DB->get_records_menu('local_academictranscript_log', null, 'id', 'id, action');
        $this->assertSame(['change_request', 'change_approve'], array_values($actions));
    }

    /**
     * A rejected request leaves the posted grade, note and percentage as they were, and a new request can be made.
     */
    public function test_rejected_change_keeps_grade(): void {
        global $DB;

        $posted = $this->make_posted_grade('B', 85.5);
        $changeid = posting::request_change($posted->id, 'A', 'note', 'reason');

        posting::decide($changeid, false, 'not supported');

        $now = $DB->get_record('local_academictranscript_posted', ['id' => $posted->id], '*', MUST_EXIST);
        $this->assertSame('B', $now->grade);
        $this->assertSame('Original note', $now->note);
        $this->assertEqualsWithDelta(85.5, (float)$now->percent, 0.00001);
        $change = $DB->get_record('local_academictranscript_change', ['id' => $changeid], '*', MUST_EXIST);
        $this->assertSame('rejected', $change->status);
        $this->assertSame('not supported', $change->decisionnote);
        $actions = $DB->get_records_menu('local_academictranscript_log', null, 'id', 'id, action');
        $this->assertSame(['change_request', 'change_reject'], array_values($actions));

        $newid = posting::request_change($posted->id, 'A-', 'note', 'second try');
        $this->assertNotEquals($changeid, $newid);
        $this->assertSame('pending', $DB->get_field('local_academictranscript_change', 'status', ['id' => $newid]));
    }

    /**
     * The printed title is the course name without a leading course code and the dash or colon after it.
     *
     * @dataProvider title_provider
     * @param string $fullname The course name
     * @param string $code The course code
     * @param string $expected The title
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('title_provider')]
    public function test_title_strips_leading_code(string $fullname, string $code, string $expected): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => $fullname]);

        $this->assertSame($expected, posting::title($course, $code));
    }

    /**
     * Data for test_title_strips_leading_code.
     *
     * @return array
     */
    public static function title_provider(): array {
        return [
            'code and dash' => ['BUS 101 - Intro to Business', 'BUS 101', 'Intro to Business'],
            'code and colon' => ['BUS 101: Intro', 'BUS 101', 'Intro'],
            'code in another case' => ['bus 101 - Intro', 'BUS 101', 'Intro'],
            'code is not at the start' => ['Intro to Business', 'BUS 101', 'Intro to Business'],
            'no code' => ['Intro to Business', '', 'Intro to Business'],
            'name is only the code' => ['BUS 101', 'BUS 101', 'BUS 101'],
            'code and en dash' => ["BUS 101 \u{2013} Intro", 'BUS 101', 'Intro'],
            'code and em dash' => ["BUS 101 \u{2014} Intro", 'BUS 101', 'Intro'],
            'an ampersand stays as typed' => ['BUS 101 - Sales & Marketing', 'BUS 101', 'Sales & Marketing'],
            'code with an ampersand' => ['R&D 101 - Intro', 'R&D 101', 'Intro'],
            'accented letter after the dash' => ["BUS 101 - \u{00C9}thique", 'BUS 101', "\u{00C9}thique"],
            'curly double quotes after the dash' => ["BUS 101 - \u{201C}Quoted\u{201D} title", 'BUS 101',
                "\u{201C}Quoted\u{201D} title"],
            'curly single quote after the colon' => ["BUS 101: \u{2018}Quoted\u{2019} title", 'BUS 101',
                "\u{2018}Quoted\u{2019} title"],
        ];
    }

    /**
     * A term, a student, and a course with all the Transcript fields that the student takes.
     *
     * The term is made on the first call and used again on the next ones, because term names are unique.
     *
     * @param array $record Course keys that replace the defaults (code BUS 101, 3 credit hours, the term)
     * @return array with the keys user, course and term
     */
    private function make_student_course(array $record = []): array {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $DB->get_record('local_academictranscript_term', ['name' => 'Fall 2026']) ?:
            $generator->create_term(['name' => 'Fall 2026']);
        $user = $this->getDataGenerator()->create_user();
        $course = $generator->create_course_with_fields($record + ['code' => 'BUS 101', 'hours' => 3,
            'hourtype' => 'credit', 'termid' => $term->id]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        return ['user' => $user, 'course' => $course, 'term' => $term];
    }

    /**
     * A posted grade of a student in a new course, with a note and a percentage.
     *
     * @param string $grade
     * @param float $percent
     * @return \stdClass the posted row
     */
    private function make_posted_grade(string $grade, float $percent): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        ['user' => $user, 'course' => $course, 'term' => $term] = $this->make_student_course();
        return $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $term->id,
            'coursecode' => 'BUS 101', 'grade' => $grade, 'percent' => $percent, 'note' => 'Original note']);
    }

    /**
     * The gradebook row that holds a student's course total.
     *
     * @param int $courseid
     * @param int $userid
     * @return \grade_grade
     */
    private function course_total(int $courseid, int $userid): \grade_grade {
        $item = \grade_item::fetch_course_item($courseid);
        return \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
    }

    /**
     * Run code that must fail with a moodle_exception, and return the exception.
     *
     * @param callable $callback
     * @return \moodle_exception
     */
    private function failure(callable $callback): \moodle_exception {
        try {
            $callback();
        } catch (\moodle_exception $exception) {
            return $exception;
        }
        $this->fail('The code did not throw a moodle_exception.');
    }
}
