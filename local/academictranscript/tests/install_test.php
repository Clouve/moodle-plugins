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

use core_course\customfield\course_handler;
use local_academictranscript\local\coursefields;
use local_academictranscript\local\scale;

#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_academictranscript_install')]
/**
 * Tests for the install hook.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_academictranscript_install
 */
final class install_test extends \advanced_testcase {
    /**
     * Load the install hook and start each test with an empty request cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        scale::reset_cache();
        require_once(__DIR__ . '/../db/install.php');
    }

    /**
     * A fresh install holds the standard scale and marks, and the setup wizard is flagged as not done.
     */
    public function test_install_loads_standard_scale_and_marks(): void {
        global $DB;

        // Checks the PHPUnit site's own install, so db/install.php edits only show after PHPUnit is re-initialised.
        $letters = array_values(array_map(fn($row) => $row->letter, scale::grades()));
        $this->assertSame(['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'F'], $letters);

        $marks = $DB->get_records('local_academictranscript_mark', null, 'sortorder');
        $this->assertSame(['P', 'NP', 'I', 'NC', 'W', 'TR', 'IP'], array_values(array_map(fn($m) => $m->code, $marks)));
        $systemmarks = array_filter($marks, fn($m) => (int)$m->systemmark === 1);
        $this->assertSame(['TR', 'IP'], array_values(array_map(fn($m) => $m->code, $systemmarks)));

        $this->assertSame('0', get_config('local_academictranscript', 'setupcomplete'));
    }

    /**
     * The install hook writes the setup flag and no other setting.
     */
    public function test_install_writes_only_the_setup_flag(): void {
        unset_all_config_for_plugin('local_academictranscript');

        $this->assertTrue(xmldb_local_academictranscript_install());

        $this->assertEquals((object)['setupcomplete' => '0'], get_config('local_academictranscript'));
    }

    /**
     * The four course fields exist, share one category, and the course code field is required and unique.
     */
    public function test_install_creates_course_fields(): void {
        $config = coursefields::field(coursefields::CODE)->get('configdata');
        $this->assertEquals(1, $config['required']);
        $this->assertEquals(1, $config['uniquevalues']);
        $this->assertEquals(1, $config['locked']);

        $categoryname = get_string('coursefieldcategory', 'local_academictranscript');
        $shortnames = [coursefields::CODE, coursefields::HOURS, coursefields::HOURTYPE, coursefields::TERM];
        foreach ($shortnames as $shortname) {
            $field = coursefields::field($shortname);
            $this->assertNotNull($field, $shortname);
            $this->assertSame($categoryname, $field->get_category()->get('name'), $shortname);
        }
    }

    /**
     * Running the hook on a site that is already set up adds nothing, keeps edited marks and keeps the setup flag.
     */
    public function test_install_again_adds_nothing(): void {
        global $DB;

        set_config('setupcomplete', 1, 'local_academictranscript');
        $DB->set_field('local_academictranscript_mark', 'label', 'Pass (edited)', ['code' => 'P']);
        $before = $this->count_rows();

        $this->assertTrue(xmldb_local_academictranscript_install());

        $this->assertSame($before, $this->count_rows());
        $this->assertSame('Pass (edited)', $DB->get_field('local_academictranscript_mark', 'label', ['code' => 'P']));
        $this->assertSame('1', get_config('local_academictranscript', 'setupcomplete'));
    }

    /**
     * Running the hook puts back a deleted scale, a deleted standard mark and a deleted course field.
     */
    public function test_install_restores_missing_defaults(): void {
        global $DB;

        $DB->delete_records('local_academictranscript_grade');
        $DB->delete_records('local_academictranscript_mark', ['code' => 'W']);
        scale::reset_cache();
        // Delete through the handler: it also clears the cached field list that the install hook reads.
        course_handler::create()->delete_field_configuration(coursefields::field(coursefields::TERM));
        $this->assertFalse($DB->record_exists('customfield_field', ['shortname' => coursefields::TERM]));
        $this->assertNull(coursefields::field(coursefields::TERM));

        $this->assertTrue(xmldb_local_academictranscript_install());

        $this->assertCount(12, scale::grades());
        $this->assertTrue($DB->record_exists('local_academictranscript_mark', ['code' => 'W']));
        $this->assertTrue($DB->record_exists('customfield_field', ['shortname' => coursefields::TERM]));
        $this->assertNotNull(coursefields::field(coursefields::TERM));
    }

    /**
     * Row counts that a second run of the install hook must leave alone.
     *
     * @return array table => number of rows
     */
    private function count_rows(): array {
        global $DB;
        $tables = ['local_academictranscript_grade', 'local_academictranscript_mark', 'customfield_category',
            'customfield_field'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $DB->count_records($table);
        }
        return $counts;
    }
}
