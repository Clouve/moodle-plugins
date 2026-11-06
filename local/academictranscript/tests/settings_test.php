<?php

namespace local_academictranscript;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the admin pages that settings.php puts in the admin tree.
 *
 * Moodle loads the settings.php of a local plugin for every user, not only for site administrators, so these tests
 * build the admin tree as an ordinary user who holds one capability at site level.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @coversNothing
 */
final class settings_test extends \advanced_testcase {
    /**
     * Load the admin library and start each test with a clean site.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');
    }

    /**
     * Forget the admin tree that the test built for a user who is not an administrator.
     */
    protected function tearDown(): void {
        global $ADMIN;
        $ADMIN = null;
        parent::tearDown();
    }

    /**
     * A user who may issue Official transcripts at site level, but may not edit student details, can open the
     * Students' transcripts page. The page itself already lets this user in.
     */
    public function test_students_page_accepts_issue_capability(): void {
        $this->setUser($this->user_with_capability('local/academictranscript:issueofficial'));

        $this->assertTrue($this->students_page()->check_access());
    }

    /**
     * A user who may edit student details, but may not issue Official transcripts, can open the page too.
     */
    public function test_students_page_accepts_manage_records_capability(): void {
        $this->setUser($this->user_with_capability('local/academictranscript:managerecords'));

        $this->assertTrue($this->students_page()->check_access());
    }

    /**
     * A user with another capability of the plugin, but neither of those two, cannot open the page.
     */
    public function test_students_page_refuses_other_capabilities(): void {
        $this->setUser($this->user_with_capability('local/academictranscript:viewissued'));

        $this->assertFalse($this->students_page()->check_access());
    }

    /**
     * A user who holds one capability, in the system context, through a role of their own.
     *
     * @param string $capability
     * @return \stdClass
     */
    private function user_with_capability(string $capability): \stdClass {
        $context = \context_system::instance();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        $this->getDataGenerator()->role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * The Students' transcripts page in the admin tree of the current user.
     *
     * @return \admin_externalpage
     */
    private function students_page() {
        $page = admin_get_root(true, false)->locate('local_academictranscript_students');
        $this->assertNotNull($page);
        return $page;
    }
}
