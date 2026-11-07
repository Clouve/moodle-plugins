<?php

namespace local_studentrecords;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once(__DIR__ . '/../db/upgrade.php');

#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_studentrecords_upgrade')]
/**
 * Tests for the upgrade steps.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     ::xmldb_local_studentrecords_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * A site on the version before gets the same indexes as a fresh install, and a second run of the step does no harm.
     */
    public function test_last_step_adds_the_indexes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $wanted = ['local_studentrecords_history' => ['userid'],
            'local_studentrecords_txn' => ['reverses', 'chargeid', 'aidid', 'paymentid']];
        foreach ($wanted as $tablename => $fields) {
            foreach ($fields as $field) {
                $table = new \xmldb_table($tablename);
                $index = new \xmldb_index($field, XMLDB_INDEX_NOTUNIQUE, [$field]);
                if ($dbman->index_exists($table, $index)) {
                    $dbman->drop_index($table, $index);
                }
            }
        }
        set_config('version', 2026111606, 'local_studentrecords');
        unset_config('recoverfrom', 'local_studentrecords');
        $before = time();

        $this->assertTrue(xmldb_local_studentrecords_upgrade(2026111606));

        foreach ($wanted as $tablename => $fields) {
            foreach ($fields as $field) {
                $index = new \xmldb_index($field, XMLDB_INDEX_NOTUNIQUE, [$field]);
                $this->assertTrue($dbman->index_exists(new \xmldb_table($tablename), $index), $field);
            }
        }
        $this->assertEquals(2026111607, get_config('local_studentrecords', 'version'));
        // The recovery of lost payments starts at the upgrade, so it never looks at the site's history. A second run keeps the
        // time.
        $this->assertGreaterThanOrEqual($before, (int)get_config('local_studentrecords', 'recoverfrom'));
        set_config('recoverfrom', 12345, 'local_studentrecords');
        set_config('version', 2026111606, 'local_studentrecords');
        $this->assertTrue(xmldb_local_studentrecords_upgrade(2026111606));
        $this->assertEquals(12345, get_config('local_studentrecords', 'recoverfrom'));
    }

    /**
     * A new site starts the recovery of lost payments when it is installed.
     */
    public function test_install_sets_the_start_of_the_recovery(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/studentrecords/db/install.php');
        $this->resetAfterTest();
        unset_config('recoverfrom', 'local_studentrecords');
        $before = time();

        $this->assertTrue(xmldb_local_studentrecords_install());

        $this->assertGreaterThanOrEqual($before, (int)get_config('local_studentrecords', 'recoverfrom'));
    }

    /**
     * The versions in the upgrade steps and in version.php agree.
     */
    public function test_version_reaches_the_last_step(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $this->assertSame(2026111607, $plugin->version);
        preg_match_all('/upgrade_plugin_savepoint\(true, (\d+),/', file_get_contents(__DIR__ . '/../db/upgrade.php'), $m);
        $this->assertSame($plugin->version, (int)max($m[1]));
    }
}
