<?php

namespace local_academictranscript;

#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_academictranscript_upgrade')]
/**
 * Tests for the upgrade steps.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     ::xmldb_local_academictranscript_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * A site on the first public release gets the request table with the same columns as a new install.
     */
    public function test_upgrade_from_the_first_release_adds_the_request_table(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->libdir . '/ddllib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_academictranscript_request');
        $columns = array_keys($DB->get_columns('local_academictranscript_request'));
        $dbman->drop_table($table);
        set_config('version', 2026102100, 'local_academictranscript');

        ob_start();
        $result = xmldb_local_academictranscript_upgrade(2026102100);
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertTrue($dbman->table_exists($table));
        $this->assertSame($columns, array_keys($DB->get_columns('local_academictranscript_request', false)));
    }

    /**
     * A site that already has the table keeps it and its rows.
     */
    public function test_upgrade_keeps_an_existing_request_table(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
        $this->resetAfterTest();
        $id = $DB->insert_record('local_academictranscript_request', (object)['userid' => 2, 'recipient' => 'Office']);
        set_config('version', 2026102201, 'local_academictranscript');

        ob_start();
        $result = xmldb_local_academictranscript_upgrade(2026102201);
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertTrue($DB->record_exists('local_academictranscript_request', ['id' => $id]));
    }

    /**
     * A site from before the first public release loses its two old columns when they hold nothing else.
     */
    public function test_upgrade_drops_the_old_student_columns_when_nothing_is_lost(): void {
        global $DB;
        $this->add_old_student_columns();
        try {
            $linked = (object)['userid' => 2, 'programid' => 7, 'program' => 'Nursing'];
            $DB->insert_record('local_academictranscript_student', $linked);
            $DB->insert_record('local_academictranscript_student', (object)['userid' => 3]);

            $this->upgrade_from(2026111606);

            $columns = $DB->get_columns('local_academictranscript_student', false);
            $this->assertArrayNotHasKey('program', $columns);
            $this->assertArrayNotHasKey('admissiondate', $columns);
            $this->assertSame(2, $DB->count_records('local_academictranscript_student'));
        } finally {
            $this->drop_old_student_columns();
        }
    }

    /**
     * A program name with no linked program, or an admission date, exists nowhere else, so that column stays.
     */
    public function test_upgrade_keeps_an_old_student_column_that_holds_something(): void {
        global $DB;
        $this->add_old_student_columns();
        try {
            $DB->insert_record('local_academictranscript_student', (object)['userid' => 2, 'program' => 'Nursing']);
            $DB->insert_record('local_academictranscript_student', (object)['userid' => 3, 'admissiondate' => 1700000000]);

            $this->upgrade_from(2026111606);

            $columns = $DB->get_columns('local_academictranscript_student', false);
            $this->assertArrayHasKey('program', $columns);
            $this->assertArrayHasKey('admissiondate', $columns);
            $this->assertSame('Nursing', $DB->get_field('local_academictranscript_student', 'program', ['userid' => 2]));
        } finally {
            $this->drop_old_student_columns();
        }
    }

    /**
     * A new site never had the columns, and the step leaves it alone.
     */
    public function test_upgrade_needs_no_old_student_columns(): void {
        global $DB;
        $columns = array_keys($DB->get_columns('local_academictranscript_student', false));

        $this->upgrade_from(2026111606);

        $this->assertSame($columns, array_keys($DB->get_columns('local_academictranscript_student', false)));
    }

    /**
     * Run the upgrade from a version.
     *
     * @param int $version
     */
    private function upgrade_from(int $version): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
        $this->resetAfterTest();
        set_config('version', $version, 'local_academictranscript');
        ob_start();
        $result = xmldb_local_academictranscript_upgrade($version);
        ob_end_clean();
        $this->assertTrue($result);
    }

    /**
     * Give the student table the two columns of the old builds.
     */
    private function add_old_student_columns(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/ddllib.php');
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_academictranscript_student');
        $dbman->add_field($table, new \xmldb_field('program', XMLDB_TYPE_CHAR, '255'));
        $dbman->add_field($table, new \xmldb_field('admissiondate', XMLDB_TYPE_INTEGER, '10'));
    }

    /**
     * Take out what is left of the two columns, so the next test finds the table as it was.
     */
    private function drop_old_student_columns(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_academictranscript_student');
        foreach (['program', 'admissiondate'] as $name) {
            $field = new \xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }
    }
}
