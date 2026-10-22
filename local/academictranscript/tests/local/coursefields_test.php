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

#[\PHPUnit\Framework\Attributes\CoversClass(coursefields::class)]
/**
 * Tests for reading the Transcript fields of courses.
 *
 * The PHPUnit site starts with the four fields (course code, hours, hour type and term) that the install hook makes.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\coursefields
 */
final class coursefields_test extends \advanced_testcase {
    /**
     * Start each test with a clean site, so courses and terms made by a test do not outlast it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The values saved on a course come back as the code, the hours, the hour type and the term option. A course
     * with no values gives the empty answer for each field.
     */
    public function test_get_reads_course_values(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $term = $generator->create_term(['name' => 'Fall 2026']);
        $clock = $generator->create_course_with_fields(
            ['code' => 'BUS 101', 'hours' => 3, 'hourtype' => 'clock', 'termid' => $term->id]
        );
        $credit = $generator->create_course_with_fields(['code' => 'ENG 101', 'hours' => 1.5, 'hourtype' => 'credit']);
        $empty = $generator->create_course_with_fields();

        $values = coursefields::get([$clock->id, $credit->id, $empty->id]);

        $this->assertSame(
            ['code' => 'BUS 101', 'hours' => 3.0, 'unittype' => 'clock', 'termindex' => 1],
            $values[$clock->id]
        );
        $this->assertSame(
            ['code' => 'ENG 101', 'hours' => 1.5, 'unittype' => 'credit', 'termindex' => 0],
            $values[$credit->id]
        );
        $this->assertSame(
            ['code' => '', 'hours' => null, 'unittype' => '', 'termindex' => 0],
            $values[$empty->id]
        );
    }

    /**
     * A course with a code but no hours or hour type takes them from the catalog entry with that code, whatever the
     * case. Values on the course stay, and a code that is not in the catalog leaves the gaps.
     */
    public function test_get_resolved_fills_gaps_from_catalog(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_academictranscript');
        $generator->create_catalog_course(
            ['code' => 'BUS 101', 'title' => 'Introduction to Business', 'hours' => 4, 'unittype' => 'clock']
        );
        $gaps = $generator->create_course_with_fields(['code' => 'bus 101']);
        $own = $generator->create_course_with_fields(['code' => 'BUS 101', 'hours' => 2, 'hourtype' => 'credit']);
        $unknown = $generator->create_course_with_fields(['code' => 'XYZ 999']);

        $values = coursefields::get_resolved([$gaps->id, $own->id, $unknown->id]);

        $this->assertSame(4.0, $values[$gaps->id]['hours']);
        $this->assertSame('clock', $values[$gaps->id]['unittype']);
        $this->assertSame(2.0, $values[$own->id]['hours']);
        $this->assertSame('credit', $values[$own->id]['unittype']);
        $this->assertNull($values[$unknown->id]['hours']);
        $this->assertSame('', $values[$unknown->id]['unittype']);
    }

    /**
     * Whole hours print without a decimal, other hours print with one, and no hours print as nothing.
     *
     * @dataProvider format_hours_provider
     * @param float|null $hours The hours
     * @param string $expected The text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('format_hours_provider')]
    public function test_format_hours(?float $hours, string $expected): void {
        $this->assertSame($expected, coursefields::format_hours($hours));
    }

    /**
     * Data for test_format_hours.
     *
     * @return array
     */
    public static function format_hours_provider(): array {
        return [
            'whole hours' => [3.0, '3'],
            'half an hour' => [1.5, '1.5'],
            'no hours' => [null, ''],
            'zero keeps its digit' => [0.0, '0'],
            'ten keeps its zero' => [10.0, '10'],
        ];
    }
}
