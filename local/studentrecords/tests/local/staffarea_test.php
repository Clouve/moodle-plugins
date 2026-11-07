<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(staffarea::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(shell::class)]
/**
 * Tests for who opens which staff page and what the menu shows them.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\staffarea
 * @covers     \local_studentrecords\local\shell
 */
final class staffarea_test extends \advanced_testcase {
    /**
     * Start with a clean site and no menu of an earlier test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        shell::reset();
    }

    /**
     * Forget the menu of this test.
     */
    protected function tearDown(): void {
        shell::reset();
        parent::tearDown();
    }

    /**
     * Sign in a person who holds these Student Records capabilities at system level.
     *
     * @param string[] $capabilities names after local/studentrecords:
     */
    private function sign_in_with(array $capabilities): void {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Staff', 'staffrole', '');
        foreach ($capabilities as $capability) {
            assign_capability('local/studentrecords:' . $capability, CAP_ALLOW, $role, SYSCONTEXTID);
        }
        role_assign($role, $user->id, SYSCONTEXTID);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
    }

    /**
     * The approve capability opens the grade changes page, and no other staff page.
     */
    public function test_an_approver_opens_only_grade_changes(): void {
        $this->sign_in_with(['approvegradechange']);

        $this->assertTrue(staffarea::can_open('gradechanges'));
        $this->assertTrue(staffarea::can_enter());
        $this->assertSame(['gradechanges'], array_keys(staffarea::pages()));
    }

    /**
     * The menu shows an approver that page alone: no records staff home, which is more than deciding needs.
     */
    public function test_the_menu_shows_an_approver_only_grade_changes(): void {
        $this->sign_in_with(['approvegradechange']);

        $groups = shell::groups();

        $this->assertCount(1, $groups);
        $this->assertSame(['gradechanges'], array_keys($groups[0]['items']));
        $this->assertSame([], homes::views());
        $url = new \moodle_url('/local/studentrecords/gradechange.php');
        $this->assertStringContainsString('aria-current="page"', shell::menu($url));
        $this->assertStringContainsString($url->out(), shell::menu($url));
    }

    /**
     * Someone who sees every student has the page in the records menu, once, with the approve capability or manage students.
     *
     * @dataProvider records_staff_provider
     * @param string[] $capabilities
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('records_staff_provider')]
    public function test_records_staff_find_grade_changes_in_their_menu(array $capabilities): void {
        $this->sign_in_with($capabilities);

        $groups = shell::groups();

        $this->assertSame(['records'], array_column($groups, 'key'));
        $this->assertArrayHasKey('gradechanges', $groups[0]['items']);
        $this->assertArrayHasKey('students', $groups[0]['items']);
    }

    /**
     * Data for test_records_staff_find_grade_changes_in_their_menu.
     *
     * @return array
     */
    public static function records_staff_provider(): array {
        return [
            'manages students' => [['managestudents']],
            'manages students and approves' => [['managestudents', 'approvegradechange']],
            'views students and approves' => [['viewstudents', 'approvegradechange']],
        ];
    }

    /**
     * Without manage students or the approve capability the page stays closed, also to someone who sees students.
     */
    public function test_grade_changes_stay_closed_to_everyone_else(): void {
        $this->sign_in_with(['viewstudents']);

        $this->assertFalse(staffarea::can_open('gradechanges'));
        $this->assertArrayNotHasKey('gradechanges', shell::groups()[0]['items']);

        $this->setUser($this->getDataGenerator()->create_user());
        shell::reset();

        $this->assertFalse(staffarea::can_open('gradechanges'));
        $this->assertSame([], shell::groups());
    }
}
