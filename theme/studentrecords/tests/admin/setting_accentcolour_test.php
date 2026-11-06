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

namespace theme_studentrecords\admin;

use theme_studentrecords\local\brand;

#[\PHPUnit\Framework\Attributes\CoversClass(setting_accentcolour::class)]
/**
 * Tests for the accent colour setting.
 *
 * @package    theme_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_studentrecords\admin\setting_accentcolour
 */
final class setting_accentcolour_test extends \advanced_testcase {
    /**
     * Load the admin classes and start with a clean site.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        set_config('accentcolor', '#123456', 'theme_studentrecords');
    }

    /**
     * Save a value through the setting.
     *
     * @param string $value
     * @return string The message of the setting, empty when it saved.
     */
    private function write(string $value): string {
        $setting = new setting_accentcolour('theme_studentrecords/accentcolor', 'Colour', '', brand::DEFAULT_ACCENT);
        return $setting->write_setting($value);
    }

    /**
     * Good colours are saved as #rrggbb in lower case.
     *
     * @dataProvider good_colour_provider
     * @param string $value What the admin typed
     * @param string $expected What is saved
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('good_colour_provider')]
    public function test_write_setting_saves_a_good_colour(string $value, string $expected): void {
        $this->assertSame('', $this->write($value));
        $this->assertSame($expected, get_config('theme_studentrecords', 'accentcolor'));
    }

    /**
     * Data for test_write_setting_saves_a_good_colour.
     *
     * @return array
     */
    public static function good_colour_provider(): array {
        return [
            'six digits' => ['#1a2b3c', '#1a2b3c'],
            'upper case' => ['#1A2B3C', '#1a2b3c'],
            'no hash' => ['1a2b3c', '#1a2b3c'],
            'three digits' => ['#123', '#112233'],
            'three digits, no hash' => ['123', '#112233'],
            'spaces' => ['  #1a2b3c  ', '#1a2b3c'],
            'empty gives the default' => ['', brand::DEFAULT_ACCENT],
        ];
    }

    /**
     * Anything that is not a colour is refused and the saved value stays, so it can never reach the SCSS.
     *
     * @dataProvider bad_format_provider
     * @param string $value What the admin typed
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_format_provider')]
    public function test_write_setting_refuses_a_bad_format(string $value): void {
        $this->assertSame(get_string('accentcolor_format', 'theme_studentrecords'), $this->write($value));
        $this->assertSame('#123456', get_config('theme_studentrecords', 'accentcolor'));
    }

    /**
     * Data for test_write_setting_refuses_a_bad_format.
     *
     * @return array
     */
    public static function bad_format_provider(): array {
        return [
            'colour name' => ['red'],
            'scss injection' => ['#000000; } body { display: none; } $x: 1'],
            'scss function' => ['darken(#000, 1%)'],
            'html' => ['<b>#000000</b>'],
            'eight digits' => ['#00000000'],
            'not hexadecimal' => ['#00000g'],
            'second line' => ["#000000\n#111111"],
        ];
    }

    /**
     * A colour too light for white text is refused and the saved value stays.
     */
    public function test_write_setting_refuses_a_light_colour(): void {
        $message = $this->write('#94bce3');

        $this->assertStringContainsString('too light', $message);
        $this->assertSame('#123456', get_config('theme_studentrecords', 'accentcolor'));
    }

    /**
     * A colour just under the limit never reads as if it met the limit: 4.46 to 1 must not be shown as 4.5 to 1.
     */
    public function test_write_setting_rounds_the_ratio_down(): void {
        $colour = '#74787a';
        $this->assertGreaterThan(4.4, brand::contrast($colour, '#ffffff'));
        $this->assertLessThan(brand::MIN_CONTRAST, brand::contrast($colour, '#ffffff'));

        $message = $this->write($colour);

        $this->assertStringContainsString(format_float(4.4, 1), $message);
        $this->assertStringNotContainsString('is ' . format_float(4.5, 2) . ' to 1', $message);
    }
}
