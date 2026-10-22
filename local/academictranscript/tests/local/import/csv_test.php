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

#[\PHPUnit\Framework\Attributes\CoversClass(csv::class)]
/**
 * Tests for the CSV templates and the CSV import.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_academictranscript\local\import\csv
 */
final class csv_test extends \advanced_testcase {
    /**
     * Each template is a CSV file that the import reads back without a complaint.
     *
     * @dataProvider template_provider
     * @param string $type The item type
     * @param int $count The number of example rows in the template
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('template_provider')]
    public function test_template_round_trips(string $type, int $count): void {
        $result = csv::parse($type, csv::template($type));

        $this->assertSame([], $result['errors']);
        $this->assertCount($count, $result['items']);
    }

    /**
     * Data for test_template_round_trips.
     *
     * @return array
     */
    public static function template_provider(): array {
        return [
            'grades' => ['grade', 3],
            'marks' => ['mark', 2],
            'programs' => ['program', 2],
            'courses' => ['course', 2],
        ];
    }

    /**
     * A byte order mark, Windows line endings and a quoted comma are all read correctly.
     */
    public function test_parse_strips_bom_and_handles_crlf(): void {
        $content = "\xEF\xBB\xBFcode,label,description,earns\r\nP,Pass,\"Passed, with credit\",1\r\n";

        $result = csv::parse('mark', $content);

        $this->assertSame([], $result['errors']);
        $this->assertSame([[
            'code' => 'P',
            'label' => 'Pass',
            'description' => 'Passed, with credit',
            'earns' => 1,
            'source' => 'P,Pass,"Passed, with credit",1',
        ]], $result['items']);
    }

    /**
     * A file whose header does not have the template's columns is refused, and the message names the columns.
     */
    public function test_parse_reports_missing_columns(): void {
        $result = csv::parse('mark', "code;label;description;earns\nP;Pass;x;1");

        $this->assertSame([], $result['items']);
        $this->assertSame(
            [get_string('err_csvheader', 'local_academictranscript', 'code, label, description, earns')],
            $result['errors']
        );
    }

    /**
     * A line that lacks a required value is skipped with its line number, and the other lines are still read.
     */
    public function test_parse_skips_incomplete_lines(): void {
        $content = "code,title,hours,unittype,program\n"
            . "BUS 101,Introduction to Business,3,credit,Business Administration\n"
            . "BUS 102,Accounting,,credit,Business Administration\n";

        $result = csv::parse('course', $content);

        $this->assertCount(1, $result['items']);
        $this->assertSame('BUS 101', $result['items'][0]['code']);
        $this->assertSame([get_string('err_csvline', 'local_academictranscript', 3)], $result['errors']);
    }
}
