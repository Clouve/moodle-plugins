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

namespace theme_studentrecords;

use theme_studentrecords\local\brand;

#[\PHPUnit\Framework\Attributes\CoversFunction('theme_studentrecords_get_main_scss_content')]
#[\PHPUnit\Framework\Attributes\CoversFunction('theme_studentrecords_get_pre_scss')]
#[\PHPUnit\Framework\Attributes\CoversFunction('theme_studentrecords_pluginfile')]
/**
 * Tests for the theme callbacks.
 *
 * @package    theme_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::theme_studentrecords_get_main_scss_content
 * @covers     ::theme_studentrecords_get_pre_scss
 * @covers     ::theme_studentrecords_pluginfile
 */
final class lib_test extends \advanced_testcase {
    /**
     * Load the callbacks and start with a clean site.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/theme/studentrecords/lib.php');
        $this->resetAfterTest();
    }

    /**
     * Save a logo in the settings.
     *
     * @param string $filearea
     */
    private function save_logo(string $filearea = 'logo'): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'theme_studentrecords',
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'logo.png',
        ], 'picture of a logo');
    }

    /**
     * The SCSS variables hold the saved colour.
     */
    public function test_get_pre_scss_uses_the_accent(): void {
        set_config('accentcolor', '#1a2b3c', 'theme_studentrecords');

        $scss = theme_studentrecords_get_pre_scss(\theme_config::load('studentrecords'));

        $this->assertSame('$primary: #1a2b3c;' . "\n" . '$sr-accent: #1a2b3c;' . "\n", $scss);
    }

    /**
     * A value that went round the setting (the database, config.php) cannot add SCSS.
     */
    public function test_get_pre_scss_is_safe_with_a_hostile_value(): void {
        set_config('accentcolor', '#000000; } body { display: none; } $x: 1', 'theme_studentrecords');

        $scss = theme_studentrecords_get_pre_scss(\theme_config::load('studentrecords'));

        $accent = brand::DEFAULT_ACCENT;
        $this->assertSame('$primary: ' . $accent . ';' . "\n" . '$sr-accent: ' . $accent . ';' . "\n", $scss);
    }

    /**
     * The main SCSS is Boost's preset for this Moodle, then the theme's styles.
     */
    public function test_get_main_scss_content(): void {
        global $CFG;

        $scss = theme_studentrecords_get_main_scss_content(\theme_config::load('studentrecords'));

        $this->assertStringStartsWith(file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/default.scss'), $scss);
        $this->assertStringContainsString('.sr-topbar', $scss);
    }

    /**
     * Call the file callback. Sending a file sets headers, which PHP warns about here because PHPUnit has printed.
     *
     * @param \context $context
     * @param string $filearea
     * @param string $filename
     * @return mixed What the callback gives.
     */
    private function serve(\context $context, string $filearea, string $filename): mixed {
        $level = error_reporting(0);
        try {
            return theme_studentrecords_pluginfile(null, null, $context, $filearea, [1, $filename], false, ['dontdie' => true]);
        } finally {
            error_reporting($level);
        }
    }

    /**
     * The logo is served to anybody, because the login page shows it. A browser that has the file gets "not modified",
     * which stops before the file is sent: sending it would close the output buffers of the test.
     */
    public function test_pluginfile_serves_the_logo(): void {
        $this->save_logo();
        $file = get_file_storage()->get_file(\context_system::instance()->id, 'theme_studentrecords', 'logo', 0, '/', 'logo.png');
        $this->setUser(null);
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $file->get_contenthash() . '"';

        try {
            $this->assertTrue($this->serve(\context_system::instance(), 'logo', 'logo.png'));
        } finally {
            unset($_SERVER['HTTP_IF_NONE_MATCH']);
        }
    }

    /**
     * A file that does not exist is not found.
     */
    public function test_pluginfile_refuses_a_missing_file(): void {
        $this->save_logo();

        $this->expectException(\moodle_exception::class);
        $this->serve(\context_system::instance(), 'logo', 'other.png');
    }

    /**
     * Only the logo area is served, even when a file is stored in another one.
     */
    public function test_pluginfile_refuses_another_area(): void {
        $this->save_logo('private');

        $this->expectException(\moodle_exception::class);
        $this->serve(\context_system::instance(), 'private', 'logo.png');
    }

    /**
     * Only the system context is served.
     */
    public function test_pluginfile_refuses_another_context(): void {
        $this->save_logo();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\moodle_exception::class);
        $this->serve(\context_course::instance($course->id), 'logo', 'logo.png');
    }
}
