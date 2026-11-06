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

#[\PHPUnit\Framework\Attributes\CoversClass(brand::class)]
/**
 * Tests for the school brand helper.
 *
 * @package    theme_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_studentrecords\local\brand
 */
final class brand_test extends \advanced_testcase {
    /**
     * Start each test with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Initials skip small words and fall back to two letters of a one-word name.
     */
    public function test_initials(): void {
        $this->assertSame('EC', brand::initials('Example College'));
        $this->assertSame('SC', brand::initials('The School of Cooking'));
        $this->assertSame('NI', brand::initials('North-Imperial Institute'));
        $this->assertSame('AC', brand::initials('Academy'));
        $this->assertSame('', brand::initials(''));
    }

    /**
     * Contrast ratios match the WCAG formula.
     */
    public function test_contrast(): void {
        $this->assertEqualsWithDelta(21.0, brand::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, brand::contrast('#416180', '#416180'), 0.01);
        $this->assertGreaterThanOrEqual(brand::MIN_CONTRAST, brand::contrast(brand::DEFAULT_ACCENT, '#ffffff'));
        $this->assertLessThan(brand::MIN_CONTRAST, brand::contrast('#94bce3', '#ffffff'));
    }

    /**
     * A good saved colour is used, in lower case.
     */
    public function test_accent_uses_a_good_saved_colour(): void {
        set_config('accentcolor', ' #1A2B3C ', 'theme_studentrecords');

        $this->assertSame('#1a2b3c', brand::accent());
    }

    /**
     * Anything else in the setting falls back to the default, so it can never reach the SCSS.
     *
     * @dataProvider bad_accent_provider
     * @param string $saved The value in the database
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_accent_provider')]
    public function test_accent_refuses_a_bad_saved_colour(string $saved): void {
        set_config('accentcolor', $saved, 'theme_studentrecords');

        $this->assertSame(brand::DEFAULT_ACCENT, brand::accent());
    }

    /**
     * Data for test_accent_refuses_a_bad_saved_colour.
     *
     * @return array
     */
    public static function bad_accent_provider(): array {
        return [
            'empty' => [''],
            'colour name' => ['red'],
            'too light' => ['#94bce3'],
            'three digits' => ['#abc'],
            'scss injection' => ['#000000; } body { display: none; } $x: 1'],
            'scss import' => ['#000000;@import "x"'],
            'second line' => ["#000000\n#ffffff"],
        ];
    }

    /**
     * The school name comes from the setting, then from the site's full name.
     */
    public function test_school_name(): void {
        global $SITE;

        $this->assertSame(format_string($SITE->fullname, true, ['escape' => false]), brand::school_name());

        set_config('schoolname', '  Hill & Dale College  ', 'theme_studentrecords');
        $this->assertSame('Hill & Dale College', brand::school_name());
    }

    /**
     * Template data: the name is plain text for Mustache to escape, and the logo is empty on a new site.
     */
    public function test_export(): void {
        set_config('schoolname', 'Hill & Dale College', 'theme_studentrecords');

        $brand = brand::export();

        $this->assertSame('Hill & Dale College', $brand['schoolname']);
        $this->assertSame('HD', $brand['initials']);
        $this->assertEmpty($brand['logourl']);
        $this->assertSame((new \moodle_url('/'))->out(false), $brand['homeurl']);
    }

    /**
     * A logo saved in the settings gives a link to pluginfile.php.
     */
    public function test_logo_url_uses_the_saved_logo(): void {
        global $PAGE;

        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'theme_studentrecords',
            'filearea' => 'logo',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'logo.png',
        ], 'picture');
        set_config('logo', '/logo.png', 'theme_studentrecords');
        $PAGE->force_theme('studentrecords');
        $PAGE->reload_theme();

        $url = brand::logo_url();

        $this->assertStringContainsString('/pluginfile.php/', $url);
        $this->assertStringContainsString('/theme_studentrecords/logo/', $url);
        $this->assertStringEndsWith('/logo.png', $url);
    }

    /**
     * The user's name goes in Moodle's user menu as escaped metadata, once, and the first name of Moodle 5.3 goes away.
     */
    public function test_user_menu_adds_the_name_escaped_once(): void {
        $menu = brand::user_menu([
            'userfullname' => 'Ana <b>& Co',
            'userfirstname' => 'Ana',
            'metadata' => [['content' => 'Viewing as', 'classes' => 'viewingas']],
        ]);

        $this->assertArrayNotHasKey('userfirstname', $menu);
        $this->assertCount(2, $menu['metadata']);
        $this->assertSame('viewingas', $menu['metadata'][0]['classes']);
        $this->assertStringContainsString('>Ana &lt;b&gt;&amp; Co<', $menu['metadata'][1]['content']);
        $this->assertStringContainsString('aria-hidden="true"', $menu['metadata'][1]['content']);
        $this->assertStringContainsString('d-none d-lg-inline', $menu['metadata'][1]['content']);
    }

    /**
     * A visitor who is not logged in has no name to show, so the menu is left as it is.
     */
    public function test_user_menu_leaves_a_visitor_alone(): void {
        $menu = ['unauthenticateduser' => ['content' => 'You are not logged in']];

        $this->assertSame($menu, brand::user_menu($menu));
    }
}
