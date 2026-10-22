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

namespace local_academictranscript;

use local_academictranscript\local\coursefields;
use local_academictranscript\local\posting;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the test data generator of this plugin, which the other tests build on.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class generator_test extends \advanced_testcase {
    /**
     * Start each test with a clean site, so rows made by a test do not outlast it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Each create method fills in what the caller left out. The numbers in the default names start again at 1 for
     * every test.
     */
    public function test_create_methods_fill_in_defaults(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $start = make_timestamp(2026, 1, 5);
        $before = time();

        $term = $generator->create_term();
        $this->assertSame('Term 1', $term->name);
        $this->assertEquals($start, $term->startdate);
        $this->assertEquals($start + 90 * DAYSECS, $term->enddate);
        $this->assertEquals(1, $term->optionindex);
        $this->assertSame('Term 2', $generator->create_term()->name);
        $dated = $generator->create_term(['startdate' => $start + DAYSECS]);
        $this->assertEquals($start + DAYSECS + 90 * DAYSECS, $dated->enddate);

        $program = $generator->create_program();
        $this->assertSame('Program 1', $program->name);
        $this->assertSame('credit', $program->unittype);

        $catalog = $generator->create_catalog_course();
        $this->assertSame('CAT 1', $catalog->code);
        $this->assertSame('Catalog course 1', $catalog->title);
        $this->assertEquals(3, $catalog->hours);
        $this->assertSame('credit', $catalog->unittype);

        $student = $generator->create_student_record(['userid' => $user->id]);
        $this->assertEquals($user->id, $student->userid);
        $this->assertNull($student->studentid);
        $this->assertEquals(get_admin()->id, $student->usermodified);
        $this->assertGreaterThanOrEqual($before, $student->timemodified);

        $transfer = $generator->create_transfer(['userid' => $user->id]);
        $this->assertSame('Valley College', $transfer->institution);
        $this->assertSame('Transfer course 1', $transfer->title);
        $this->assertEquals(3, $transfer->hours);
        $this->assertEquals(1, $transfer->active);

        $posted = $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $term->id]);
        $this->assertSame('CRS 1', $posted->coursecode);
        $this->assertSame('Course 1', $posted->coursename);
        $this->assertEquals(3, $posted->hours);
        $this->assertSame('credit', $posted->unittype);
        $this->assertSame('A', $posted->grade);
        $this->assertEquals(get_admin()->id, $posted->postedby);
        $this->assertGreaterThanOrEqual($before, $posted->timeposted);
        $this->assertEquals($posted->timeposted, $posted->timemodified);

        $code = $generator->create_code_row(['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertEquals($user->id, $code->userid);
        $this->assertEquals($course->id, $code->courseid);
        $this->assertNull($code->code);
        $this->assertNull($code->note);
        $this->assertSame('Entered by the teacher', $code->reason);
        $this->assertNull($code->termid);
        $this->assertEquals(get_admin()->id, $code->usermodified);
        $this->assertGreaterThanOrEqual($before, $code->timemodified);
    }

    /**
     * A hand-entered row takes any column of its table, and what the caller gives replaces the defaults.
     */
    public function test_create_code_row_takes_any_column(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $user = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $term = $generator->create_term();

        $code = $generator->create_code_row(['userid' => $user->id, 'courseid' => $course->id, 'code' => 'W',
            'note' => 'Left in week 3', 'reason' => 'Withdrew', 'termid' => $term->id, 'usermodified' => $teacher->id,
            'timemodified' => 1000]);

        $this->assertSame('W', $code->code);
        $this->assertSame('Left in week 3', $code->note);
        $this->assertSame('Withdrew', $code->reason);
        $this->assertEquals($term->id, $code->termid);
        $this->assertEquals($teacher->id, $code->usermodified);
        $this->assertEquals(1000, $code->timemodified);
    }

    /**
     * A course gets its Transcript fields whoever is logged in, even though only an admin may save the locked
     * fields, and the user who was logged in is still logged in afterwards.
     */
    public function test_create_course_with_fields_works_for_any_user(): void {
        global $USER;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $fields = ['code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'clock', 'termid' => $term->id];
        $expected = ['code' => 'BUS 101', 'hours' => 3.0, 'unittype' => 'clock', 'termindex' => 1];

        // Nobody is logged in.
        $this->assertEquals(0, $USER->id);
        $course = $generator->create_course_with_fields($fields);
        $this->assertEquals(0, $USER->id);
        $this->assertSame($expected, coursefields::get([$course->id])[$course->id]);

        // A user who is not an admin is logged in.
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        $course = $generator->create_course_with_fields($fields);
        $this->assertEquals($teacher->id, $USER->id);
        $this->assertSame($expected, coursefields::get([$course->id])[$course->id]);

        // An admin is logged in.
        $this->setAdminUser();
        $course = $generator->create_course_with_fields($fields);
        $this->assertTrue(is_siteadmin());
        $this->assertSame($expected, coursefields::get([$course->id])[$course->id]);
    }

    /**
     * A Transcript field that is missing, null or empty stays unset, and zero hours are saved. Other keys go to the
     * course.
     */
    public function test_create_course_with_fields_leaves_empty_fields_unset(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');

        $empty = $generator->create_course_with_fields(['code' => '', 'hours' => null, 'hourtype' => '', 'termid' => 0]);
        $zero = $generator->create_course_with_fields(['fullname' => 'Seminar', 'hours' => 0]);

        $this->assertSame(0, $DB->count_records('customfield_data', ['instanceid' => $empty->id]));
        $this->assertSame(
            ['code' => '', 'hours' => null, 'unittype' => '', 'termindex' => 0],
            coursefields::get([$empty->id])[$empty->id]
        );
        $this->assertSame('Seminar', $zero->fullname);
        $this->assertSame(0.0, coursefields::get([$zero->id])[$zero->id]['hours']);
    }

    /**
     * An hour type other than credit, clock or empty is refused.
     */
    public function test_create_course_with_fields_refuses_unknown_hour_type(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');

        $this->expectException(\coding_exception::class);
        $generator->create_course_with_fields(['hourtype' => 'minutes']);
    }

    /**
     * A record without a value that the generator cannot choose, or with a column the table does not have, is
     * refused.
     *
     * @dataProvider refused_record_provider
     * @param string $method The generator method
     * @param array $record The record
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused_record_provider')]
    public function test_create_refuses_bad_record(string $method, array $record): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');

        $this->expectException(\coding_exception::class);
        $generator->$method($record);
    }

    /**
     * Data for test_create_refuses_bad_record.
     *
     * @return array
     */
    public static function refused_record_provider(): array {
        return [
            'student record without a user' => ['create_student_record', ['studentid' => 'S-1001']],
            'transfer without a user' => ['create_transfer', ['institution' => 'Valley College']],
            'posted grade without a user' => ['create_posted_grade', ['courseid' => 2, 'termid' => 3]],
            'posted grade without a course' => ['create_posted_grade', ['userid' => 2, 'termid' => 3]],
            'posted grade without a term' => ['create_posted_grade', ['userid' => 2, 'courseid' => 3]],
            'transfer with a column the table lacks' => ['create_transfer', ['userid' => 2, 'institutoin' => 'Typo College']],
            'code row without a user' => ['create_code_row', ['courseid' => 3]],
            'code row without a course' => ['create_code_row', ['userid' => 2]],
            'code row with a column the table lacks' => ['create_code_row', ['userid' => 2, 'courseid' => 3, 'coed' => 'W']],
        ];
    }

    /**
     * A course gets one manual grade item with a maximum of 100, however many totals are set. Setting a total again
     * changes it, and another course gets its own item.
     */
    public function test_set_course_total_keeps_one_item_per_course(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        foreach ([$course, $other] as $enrolled) {
            $this->getDataGenerator()->enrol_user($one->id, $enrolled->id, 'student');
            $this->getDataGenerator()->enrol_user($two->id, $enrolled->id, 'student');
        }

        $generator->set_course_total($course->id, $one->id, 80);
        $generator->set_course_total($course->id, $two->id, 65.5);
        $generator->set_course_total($course->id, $one->id, 90);
        $generator->set_course_total($other->id, $one->id, 40);

        $items = $DB->get_records('grade_items', ['courseid' => $course->id, 'itemtype' => 'manual']);
        $this->assertCount(1, $items);
        $this->assertEquals(100, reset($items)->grademax);
        $this->assertCount(1, $DB->get_records('grade_items', ['courseid' => $other->id, 'itemtype' => 'manual']));
        $this->assertEqualsWithDelta(90.0, posting::course_percent($course->id, $one->id), 0.001);
        $this->assertEqualsWithDelta(65.5, posting::course_percent($course->id, $two->id), 0.001);
        $this->assertEqualsWithDelta(40.0, posting::course_percent($other->id, $one->id), 0.001);
    }
}
