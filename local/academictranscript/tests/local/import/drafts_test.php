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

namespace local_academictranscript\local\import;

use local_academictranscript\local\scale;

#[\PHPUnit\Framework\Attributes\CoversClass(drafts::class)]
/**
 * Tests for drafts, the items that wait for an admin's decision.
 *
 * The PHPUnit site starts with the standard scale of 12 letters.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\import\drafts
 */
final class drafts_test extends \advanced_testcase {
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
     * Quotes are compared without regard to case, spacing or punctuation. An empty quote is never found.
     */
    public function test_quote_found_ignores_case_spacing_and_punctuation(): void {
        $this->assertTrue(drafts::quote_found('Excellent  work', 'EXCELLENT WORK, as defined'));
        $this->assertTrue(drafts::quote_found('excellent work as defined', 'EXCELLENT WORK, as defined'));
        $this->assertFalse(drafts::quote_found('', 'x'));
        $this->assertFalse(drafts::quote_found('Poor', 'Excellent work'));
    }

    /**
     * Items of a known type become drafts, and the quote flag says whether the quote is in the document text.
     */
    public function test_store_saves_drafts_with_quote_flag(): void {
        global $DB, $USER;

        $item = $this->grade_item('A', 93, 100, 4.0, 'A 93');
        $doctext = 'Grade A 93 to 100';

        $stored = drafts::store(['grade' => [$item], 'bogus' => [$item]], 'ai', 'policy.pdf', 0, $doctext);

        $this->assertSame(1, $stored);
        $rows = $DB->get_records('local_academictranscript_draft');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('grade', $row->itemtype);
        $this->assertSame('ai', $row->source);
        $this->assertSame('policy.pdf', $row->sourcename);
        $this->assertNull($row->sourcefileid);
        $this->assertSame('A 93', $row->excerpt);
        $this->assertEquals(1, $row->quotefound);
        $this->assertSame('draft', $row->status);
        $this->assertEquals($USER->id, $row->userid);
        $expected = $item;
        unset($expected['source']);
        $this->assertEquals($expected, json_decode($row->data, true));

        // A quote that the document does not hold is flagged.
        drafts::store(['grade' => [$this->grade_item('B', 83, 86, 3.0, 'B 83')]], 'ai', 'policy.pdf', 0, $doctext);
        $rows = array_values($DB->get_records('local_academictranscript_draft', null, 'id'));
        $this->assertCount(2, $rows);
        $this->assertSame('B 83', $rows[1]->excerpt);
        $this->assertEquals(0, $rows[1]->quotefound);
    }

    /**
     * Approved grade drafts, with the admin's edits, become the whole active scale.
     */
    public function test_approve_grade_drafts_replaces_scale(): void {
        global $DB, $USER;

        drafts::store(['grade' => [
            $this->grade_item('S', 50, 100, 4.0),
            $this->grade_item('U', 0, 49, 0.0),
        ]], 'csv', 'grades.csv', 0, '');
        $edited = $this->no_edits('grade');
        $this->assertCount(2, $edited);
        $first = array_key_first($edited);
        $edited[$first] = ['description' => 'Satisfactory'];

        $errors = drafts::approve('grade', $edited);

        $this->assertSame([], $errors);
        $this->assertSame(2, $DB->count_records('local_academictranscript_grade', ['active' => 1]));
        $grades = scale::grades();
        $this->assertSame(['S', 'U'], array_values(array_map(fn($grade) => $grade->letter, $grades)));
        $this->assertSame('Satisfactory', reset($grades)->description);
        $this->assertSame([], drafts::open('grade'));
        $statuses = $DB->get_records_menu('local_academictranscript_draft', ['itemtype' => 'grade'], 'id', 'id, status');
        $this->assertSame(['approved', 'approved'], array_values($statuses));
        $this->assertEquals($USER->id, $DB->get_field('local_academictranscript_draft', 'decidedby', ['id' => $first]));
    }

    /**
     * Grade drafts with a problem are refused, and nothing is saved: the scale and the drafts stay as they were.
     */
    public function test_approve_with_errors_saves_nothing(): void {
        global $DB;

        drafts::store(['grade' => [
            $this->grade_item('A', 90, 100, 4.0),
            $this->grade_item('B', 85, 91, 3.0),
        ]], 'csv', 'grades.csv', 0, '');

        $errors = drafts::approve('grade', $this->no_edits('grade'));

        $this->assertSame([get_string('err_overlap', 'local_academictranscript', 'B / A')], $errors);
        $this->assertSame(12, $DB->count_records('local_academictranscript_grade', ['active' => 1]));
        $statuses = $DB->get_records_menu('local_academictranscript_draft', ['itemtype' => 'grade'], 'id', 'id, status');
        $this->assertSame(['draft', 'draft'], array_values($statuses));
    }

    /**
     * Approved program and course drafts are saved, and a course that names its program is linked to it.
     */
    public function test_approve_program_and_course_drafts(): void {
        global $DB;

        drafts::store(['program' => [
            ['name' => 'Nursing', 'credential' => 'Diploma', 'unittype' => 'clock', 'totalhours' => 900.0, 'source' => ''],
        ]], 'csv', 'programs.csv', 0, '');
        drafts::store(['course' => [
            ['code' => 'NUR 101', 'title' => 'Foundations', 'hours' => 120.0, 'unittype' => 'clock', 'program' => 'Nursing',
                'source' => ''],
        ]], 'csv', 'courses.csv', 0, '');

        $this->assertSame([], drafts::approve('program', $this->no_edits('program')));
        $this->assertSame([], drafts::approve('course', $this->no_edits('course')));

        $program = $DB->get_record('local_academictranscript_program', ['name' => 'Nursing'], '*', MUST_EXIST);
        $this->assertSame('Diploma', $program->credential);
        $this->assertSame('clock', $program->unittype);
        $course = $DB->get_record('local_academictranscript_catalog', ['code' => 'NUR 101'], '*', MUST_EXIST);
        $this->assertSame('Foundations', $course->title);
        $this->assertEquals(120, $course->hours);
        $this->assertEquals($program->id, $course->programid);
        $this->assertSame(0, array_sum(drafts::counts()));
    }

    /**
     * Approved mark drafts are saved by code: a new code is added, and a code that exists is updated.
     */
    public function test_approve_mark_drafts_saves_marks(): void {
        global $DB;

        drafts::store(['mark' => [
            ['code' => 'AU', 'label' => 'Audit', 'description' => 'Course audited', 'earns' => 0, 'source' => ''],
            ['code' => 'W', 'label' => 'Withdrew', 'description' => '', 'earns' => 0, 'source' => ''],
        ]], 'csv', 'marks.csv', 0, '');

        $this->assertSame([], drafts::approve('mark', $this->no_edits('mark')));

        $audit = $DB->get_record('local_academictranscript_mark', ['code' => 'AU'], '*', MUST_EXIST);
        $this->assertSame('Audit', $audit->label);
        $this->assertEquals(0, $audit->systemmark);
        $this->assertSame('Withdrew', $DB->get_field('local_academictranscript_mark', 'label', ['code' => 'W']));
        $this->assertSame(8, $DB->count_records('local_academictranscript_mark'));
        $this->assertSame([], drafts::open('mark'));
    }

    /**
     * A rejected draft is kept, marked as rejected and as decided by the admin, and it leaves the open drafts.
     */
    public function test_reject_marks_drafts_rejected(): void {
        global $DB, $USER;

        drafts::store(['mark' => [
            ['code' => 'W', 'label' => 'Withdrawn', 'description' => '', 'earns' => 0, 'source' => ''],
            ['code' => 'I', 'label' => 'Incomplete', 'description' => '', 'earns' => 0, 'source' => ''],
        ]], 'csv', 'marks.csv', 0, '');
        [$first, $second] = array_keys(drafts::open('mark'));

        drafts::reject([$first]);

        $rejected = $DB->get_record('local_academictranscript_draft', ['id' => $first], '*', MUST_EXIST);
        $this->assertSame('rejected', $rejected->status);
        $this->assertEquals($USER->id, $rejected->decidedby);
        $this->assertGreaterThan(0, (int)$rejected->timedecided);
        $this->assertSame('draft', $DB->get_field('local_academictranscript_draft', 'status', ['id' => $second]));
        $this->assertSame([$second], array_keys(drafts::open('mark')));
        $this->assertSame(['grade' => 0, 'mark' => 1, 'program' => 0, 'course' => 0], drafts::counts());
    }

    /**
     * A grade item in the shape that the extractor and the CSV import produce.
     *
     * @param string $letter
     * @param float $min The lowest percentage
     * @param float $max The highest percentage
     * @param float $points The grade points
     * @param string $source The quote from the document
     * @return array
     */
    private function grade_item(string $letter, float $min, float $max, float $points, string $source = ''): array {
        return ['letter' => $letter, 'minpct' => $min, 'maxpct' => $max, 'points' => $points, 'ingpa' => 1, 'earns' => 1,
            'description' => 'Excellent', 'source' => $source];
    }

    /**
     * The edits to give approve() so that every open draft of a type is approved as it is.
     *
     * @param string $type The item type
     * @return array draft id => edited fields, none for each draft
     */
    private function no_edits(string $type): array {
        return array_map(fn($draft) => [], drafts::open($type));
    }
}
