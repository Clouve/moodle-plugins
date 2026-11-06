<?php

namespace local_studentrecords;

use local_studentrecords\local\aid;
use local_studentrecords\local\billing;
use local_studentrecords\local\home;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../db/uninstall.php');

#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_studentrecords_uninstall')]
/**
 * Tests for the uninstall hook.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     ::xmldb_local_studentrecords_uninstall
 */
final class uninstall_test extends \advanced_testcase {
    /**
     * Start each test as an admin on a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A start page that points at the plugin goes back to Moodle's default.
     */
    public function test_start_page_goes_back(): void {
        global $CFG;
        set_config('defaulthomepage', home::URL);
        $this->assertTrue(xmldb_local_studentrecords_uninstall());
        $this->assertSame((string)get_default_home_page(), (string)$CFG->defaulthomepage);
    }

    /**
     * Another start page stays as it is.
     */
    public function test_other_start_page_stays(): void {
        global $CFG;
        set_config('defaulthomepage', HOMEPAGE_SITE);
        xmldb_local_studentrecords_uninstall();
        $this->assertSame((string)HOMEPAGE_SITE, (string)$CFG->defaulthomepage);
    }

    /**
     * A user's own choice of the plugin's page is forgotten, and other users' choices stay.
     */
    public function test_user_preferences(): void {
        $chose = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        set_user_preference('user_home_page_preference', home::URL, $chose);
        set_user_preference('user_home_page_preference', HOMEPAGE_MY, $other);

        xmldb_local_studentrecords_uninstall();

        $this->assertNull(get_user_preferences('user_home_page_preference', null, $chose->id));
        $this->assertEquals(HOMEPAGE_MY, get_user_preferences('user_home_page_preference', null, $other->id));
    }

    /**
     * The roles made by the plugin go, and so do their assignments.
     */
    public function test_plugin_roles_are_removed(): void {
        global $DB;
        $roleid = billing::create_finance_role();
        aid::create_role();
        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, $user->id, SYSCONTEXTID);

        xmldb_local_studentrecords_uninstall();

        $this->assertFalse($DB->record_exists('role', ['shortname' => billing::FINANCEROLE]));
        $this->assertFalse($DB->record_exists('role', ['shortname' => aid::ROLE]));
        $this->assertFalse($DB->record_exists('role_assignments', ['roleid' => $roleid]));
    }

    /**
     * A role the school gave other capabilities has a use of its own, so it stays.
     */
    public function test_role_with_other_capabilities_stays(): void {
        global $DB;
        $roleid = billing::create_finance_role();
        assign_capability('moodle/site:viewreports', CAP_ALLOW, $roleid, SYSCONTEXTID);

        xmldb_local_studentrecords_uninstall();

        $this->assertTrue($DB->record_exists('role', ['id' => $roleid]));
    }

    /**
     * The hook can run twice.
     */
    public function test_runs_twice(): void {
        $this->assertTrue(xmldb_local_studentrecords_uninstall());
        $this->assertTrue(xmldb_local_studentrecords_uninstall());
    }
}
