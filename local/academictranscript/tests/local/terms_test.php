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

#[\PHPUnit\Framework\Attributes\CoversClass(terms::class)]
/**
 * Tests for the terms and the options of the course Term field.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\terms
 */
final class terms_test extends \advanced_testcase {
    /**
     * Start each test with a clean site, so terms and course values made by a test do not outlast it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A new term takes the next option index, and renaming a term keeps its index, so the values already saved on
     * courses still point at the same term. The Term field of the courses lists the names in that order.
     */
    public function test_save_appends_option_index(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $fall = $generator->create_term(['name' => 'Fall 2026']);
        $spring = $generator->create_term(['name' => 'Spring 2027']);

        $this->assertEquals(1, $fall->optionindex);
        $this->assertEquals(2, $spring->optionindex);

        terms::save((object)['id' => $fall->id, 'name' => 'Fall 2026 (renamed)', 'startdate' => $fall->startdate,
            'enddate' => $fall->enddate]);

        $this->assertEquals(1, $DB->get_field('local_academictranscript_term', 'optionindex', ['id' => $fall->id]));
        $this->assertSame("Fall 2026 (renamed)\nSpring 2027", terms::options_text());
        $this->assertSame(terms::options_text(), coursefields::term_field()->get('configdata')['options']);
    }

    /**
     * A removed term leaves a placeholder in the options, so the later terms keep their positions. A term made
     * after the gap goes at the end, and does not take the free place.
     */
    public function test_options_text_keeps_gaps(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $fall = $generator->create_term(['name' => 'Fall 2026']);
        $generator->create_term(['name' => 'Spring 2027']);

        $DB->delete_records('local_academictranscript_term', ['id' => $fall->id]);

        $this->assertSame("(unused 1)\nSpring 2027", terms::options_text());

        $summer = $generator->create_term(['name' => 'Summer 2027']);

        $this->assertEquals(3, $summer->optionindex);
        $this->assertSame("(unused 1)\nSpring 2027\nSummer 2027", terms::options_text());
    }

    /**
     * The term an admin chose for one student wins. Without one, the term chosen on the course is used. A course
     * with no term, or with an option that no term has, gives 0.
     */
    public function test_effective_prefers_override(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $fall = $generator->create_term(['name' => 'Fall 2026']);
        $spring = $generator->create_term(['name' => 'Spring 2027']);
        $course = $generator->create_course_with_fields(['termid' => $fall->id]);
        $user = $this->getDataGenerator()->create_user();
        $optionindex = coursefields::get([$course->id])[$course->id]['termindex'];
        $this->assertEquals($fall->optionindex, $optionindex);

        $override = (object)['termid' => $spring->id];
        $this->assertSame((int)$spring->id, terms::effective($user->id, $course->id, $optionindex, $override));

        $this->assertSame((int)$fall->id, terms::effective($user->id, $course->id, $optionindex, null));
        // A row that holds only a hand-entered code has no term of its own.
        $nooverride = (object)['termid' => null];
        $this->assertSame((int)$fall->id, terms::effective($user->id, $course->id, $optionindex, $nooverride));

        $this->assertSame(0, terms::effective($user->id, $course->id, 0, null));
        $this->assertSame(0, terms::effective($user->id, $course->id, 99, null));
    }
}
