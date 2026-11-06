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

namespace theme_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(records::class)]
/**
 * Tests for the calls to the Student Records plugin. Theme Boost stands in for the plugin, so these run without it.
 *
 * @package    theme_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_studentrecords\local\records
 */
final class records_test extends \advanced_testcase {
    /** A plugin and callback that exist on every site. */
    private const WORKING = ['user_preferences', [], 'nothing', 'theme_boost'];

    /**
     * Start each test with a clean site and an ordinary user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Put the upgrade flag back: a test reset does not clear it.
     */
    protected function tearDown(): void {
        global $CFG;
        unset($CFG->upgraderunning);
        parent::tearDown();
    }

    /**
     * A logged-in user gets the answer of the plugin.
     */
    public function test_call_gives_the_answer_of_the_plugin(): void {
        $this->assertIsArray(records::call(...self::WORKING));
    }

    /**
     * A site without the plugin gets the default, with no exception and no debugging message.
     */
    public function test_call_without_the_plugin_gives_the_default(): void {
        $this->assertSame('nothing', records::call('theme_card', [], 'nothing', 'local_notinstalled'));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Files on disk are not enough: the plugin may not be installed yet, so its tables may not exist.
     */
    public function test_call_with_a_plugin_not_installed_gives_the_default(): void {
        unset_config('version', 'theme_boost');

        $this->assertSame('nothing', records::call(...self::WORKING));
    }

    /**
     * A callback that fails leaves the page standing. A developer gets a message.
     */
    public function test_call_with_a_failing_callback_gives_the_default(): void {
        $this->assertSame('nothing', records::call('get_main_scss_content', [], 'nothing', 'theme_boost'));
        $this->assertDebuggingCalled();
    }

    /**
     * Visitors and guests have no records, so the plugin is not called.
     */
    public function test_call_skips_visitors_and_guests(): void {
        $this->setUser(null);
        $this->assertSame('nothing', records::call(...self::WORKING));

        $this->setGuestUser();
        $this->assertSame('nothing', records::call(...self::WORKING));
    }

    /**
     * During an upgrade the tables of the plugin may be half changed, so it is left alone.
     */
    public function test_call_skips_an_upgrade(): void {
        global $CFG;

        $CFG->upgraderunning = time();

        $this->assertSame('nothing', records::call(...self::WORKING));
    }
}
