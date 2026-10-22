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

#[\PHPUnit\Framework\Attributes\CoversClass(html_view::class)]
/**
 * Tests for the HTML of the transcript pages.
 *
 * The transcript data holds names as plain text. The HTML escapes them once, so a name with an ampersand reads
 * "Sales &amp; Marketing" in the markup, and never "Sales &amp;amp; Marketing".
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\html_view
 */
final class html_view_test extends \advanced_testcase {
    /**
     * Start each test with an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
    }

    /**
     * The program, a course in progress, a posted course and the footnote of a mark are each escaped once.
     */
    public function test_transcript_html_escapes_names_once(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $program = $generator->create_program(['name' => 'Arts & Sciences']);
        $user = $this->getDataGenerator()->create_user();
        $generator->create_student_record(['userid' => $user->id, 'programid' => $program->id]);
        $current = $generator->create_course_with_fields(['fullname' => 'Health & Safety (Level 1)', 'code' => 'HLT 110',
            'hours' => 2, 'hourtype' => 'credit', 'termid' => $term->id]);
        $this->getDataGenerator()->enrol_user($user->id, $current->id, 'student');
        $earlier = $this->getDataGenerator()->create_course();
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $earlier->id, 'termid' => $term->id,
            'coursecode' => 'BUS 101', 'coursename' => 'Sales & Marketing', 'grade' => 'P']);
        scale::save_marks([['code' => 'P', 'label' => 'Pass & credit', 'earns' => 1]]);

        $html = html_view::transcript(transcript::build($user->id));

        $this->assertStringContainsString('Arts &amp; Sciences', $html);
        $this->assertStringContainsString('Health &amp; Safety (Level 1)', $html);
        $this->assertStringContainsString('Sales &amp; Marketing', $html);
        $this->assertStringContainsString('Pass &amp; credit', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * Plain text may hold angle brackets, so the HTML must show them as text. A name that looks like a tag is not a tag.
     */
    public function test_transcript_html_shows_angle_brackets_in_names_as_text(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator->create_posted_grade(['userid' => $user->id, 'courseid' => $course->id, 'termid' => $term->id,
            'coursecode' => 'BUS 101', 'coursename' => 'Sales <b>bold</b> & Co']);

        $html = html_view::transcript(transcript::build($user->id));

        $this->assertStringContainsString('Sales &lt;b&gt;bold&lt;/b&gt; &amp; Co', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    /**
     * The descriptions of the grades and of the marks in the Transcript Key are each escaped once.
     */
    public function test_scale_table_escapes_descriptions_once(): void {
        scale::replace_grades([
            ['letter' => 'G', 'minpct' => 50, 'maxpct' => 100, 'points' => 4.0, 'ingpa' => 1, 'earns' => 1,
                'description' => 'Good & solid'],
            ['letter' => 'U', 'minpct' => 0, 'maxpct' => 49, 'points' => 0.0, 'ingpa' => 1, 'earns' => 0,
                'description' => 'Not yet'],
        ]);
        scale::save_marks([['code' => 'P', 'label' => 'Pass', 'description' => 'Passed & earned credit', 'earns' => 1]]);

        $html = html_view::scale_table();

        $this->assertStringContainsString('Good &amp; solid', $html);
        $this->assertStringContainsString('Passed &amp; earned credit', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }
}
