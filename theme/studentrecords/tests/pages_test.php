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

use theme_studentrecords\local\records;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests that render whole pages through every layout of the theme, and every template from its example.
 *
 * A debugging message fails a test, so these also catch a string, a picture or a template that this Moodle lacks.
 *
 * @package    theme_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class pages_test extends \advanced_testcase {
    /**
     * Start each test with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Forget the admin tree and the upgrade flag: a test reset does not clear them.
     */
    protected function tearDown(): void {
        global $ADMIN, $CFG;
        $ADMIN = null;
        unset($CFG->upgraderunning);
        parent::tearDown();
    }

    /**
     * Render a page the way the web does, with the theme's renderer and not the command line one.
     *
     * @param string $layout
     * @param \stdClass|null $course
     * @return string HTML
     */
    private function render_page(string $layout, ?\stdClass $course = null): string {
        $renderer = $this->theme_renderer($course ? '/course/view.php?id=' . $course->id : '/index.php', $layout, $course);

        $html = $renderer->header() . '<p>The page.</p>' . $renderer->footer();

        $this->assertDebuggingNotCalled();
        $this->assertStringContainsString('<p>The page.</p>', $html);
        $this->assertStringNotContainsString('[[', $html);
        return $html;
    }

    /**
     * Set up the page and get the theme's renderer, not the command line one.
     *
     * @param string $url
     * @param string $layout
     * @param \stdClass|null $course
     * @return \theme_studentrecords\output\core_renderer
     */
    private function theme_renderer(string $url, string $layout, ?\stdClass $course = null): \renderer_base {
        global $PAGE, $SITE;

        $PAGE->set_course($course ?? $SITE);
        if (!$course) {
            $PAGE->set_context(\context_system::instance());
        }
        $PAGE->set_url(new \moodle_url($url));
        $PAGE->set_pagelayout($layout);
        $PAGE->force_theme('studentrecords');
        return $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL);
    }

    /**
     * Every layout in the theme's config renders for a visitor who is not logged in.
     *
     * @dataProvider layout_provider
     * @param string $layout
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('layout_provider')]
    public function test_layout_renders_for_a_visitor(string $layout): void {
        $this->setUser(null);

        $this->render_page($layout);
    }

    /**
     * Every layout renders for a guest.
     *
     * @dataProvider layout_provider
     * @param string $layout
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('layout_provider')]
    public function test_layout_renders_for_a_guest(string $layout): void {
        $this->setGuestUser();

        $this->render_page($layout);
    }

    /**
     * Every layout renders for an admin.
     *
     * @dataProvider layout_provider
     * @param string $layout
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('layout_provider')]
    public function test_layout_renders_for_an_admin(string $layout): void {
        $this->setAdminUser();

        $this->render_page($layout);
    }

    /**
     * The layouts that Moodle pages can ask for.
     *
     * @return array
     */
    public static function layout_provider(): array {
        $layouts = ['base', 'standard', 'course', 'coursecategory', 'incourse', 'frontpage', 'admin', 'mycourses', 'mydashboard',
            'mypublic', 'login', 'popup', 'frametop', 'embedded', 'maintenance', 'print', 'redirect', 'report', 'secure'];
        return array_combine($layouts, array_map(fn(string $layout): array => [$layout], $layouts));
    }

    /**
     * The theme lists the same layouts as Boost, and every file it names exists in this Moodle.
     */
    public function test_layouts_match_boost(): void {
        $theme = \theme_config::load('studentrecords');
        $boost = \theme_config::load('boost');

        $this->assertEqualsCanonicalizing(array_keys($boost->layouts), array_keys($theme->layouts));
        $this->assertEqualsCanonicalizing(array_keys($boost->layouts), array_keys(self::layout_provider()));
        foreach ($theme->layouts as $name => $layout) {
            $this->assertEquals($boost->layouts[$name], $layout, $name);
            $this->assertFileExists($theme->layout_file($name));
        }
    }

    /**
     * A course page has the course index with its controls on every Moodle, and the parts of its own version.
     *
     * Moodle 5.3 moved the controls to a new drawer block, and only 5.2 and later have the heading and sidebar icons.
     */
    public function test_course_page_keeps_the_course_index(): void {
        global $CFG;

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cell Biology & Labs']);
        $this->setAdminUser();

        $html = $this->render_page('course', $course);

        $this->assertStringContainsString('id="theme_boost-drawers-courseindex"', $html);
        $this->assertSame(1, substr_count($html, 'id="courseindexdrawercontrols"'));
        $this->assertStringContainsString('class="sr-only visually-hidden">' . get_string('opendrawerindex'), $html);
        if ($CFG->branch >= 502) {
            $this->assertStringContainsString('id="courseindexdrawerheading"', $html);
            $this->assertStringContainsString('Cell Biology &amp; Labs', $html);
            $this->assertStringContainsString('btn btn-icon icon-size-3', $html);
        } else {
            $this->assertStringNotContainsString('courseindexdrawerheading', $html);
            $this->assertStringContainsString('btn icon-no-margin', $html);
        }
    }

    /**
     * The login card carries Boost's class, because Boost scopes the styles of its login form to that class.
     */
    public function test_login_page_uses_the_styles_of_this_moodle(): void {
        $this->setUser(null);

        $html = $this->render_page('login');

        $this->assertStringContainsString('<div id="region-main" class="sr-login-card login-container">', $html);
        $this->assertStringContainsString('sr-topbar-login', $html);
    }

    /**
     * The top bar has the school, the user menu of this Moodle with the user's name, and the bar's own background.
     */
    public function test_top_bar(): void {
        global $CFG;

        set_config('schoolname', 'Hill & Dale College', 'theme_studentrecords');
        $this->setUser($this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']));

        $html = $this->render_page('standard');

        $this->assertStringContainsString('<span class="sr-brand-name">Hill &amp; Dale College</span>', $html);
        $this->assertStringContainsString('sr-username d-none d-lg-inline', $html);
        $this->assertStringContainsString('>Ana Ruiz</span>', $html);
        $this->assertStringContainsString('class="navbar navbar-light sr-topbar fixed-top navbar-expand"', $html);
        $this->assertStringNotContainsString('userfirstname', $html);
        // Bootstrap 4 opens a dropdown with data-toggle and Bootstrap 5 with data-bs-toggle.
        $toggle = $CFG->branch >= 500 ? 'data-bs-toggle="dropdown"' : 'data-toggle="dropdown"';
        $this->assertMatchesRegularExpression('/id="user-menu-toggle"[^>]*' . $toggle . '/', $html);
    }

    /**
     * Moodle 5.3's colour mode menu shows in the top bar when the site turns it on.
     */
    public function test_top_bar_has_the_colour_mode_menu(): void {
        global $CFG;

        if ($CFG->branch < 503) {
            $this->markTestSkipped('Colour modes came in Moodle 5.3.');
        }
        set_config('enablecolourmodes', 1, 'theme_boost');
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertStringContainsString('data-region="colourmode-menu"', $this->render_page('standard'));
    }

    /**
     * During an upgrade Moodle shows the maintenance layout, which asks nothing of the database or of Student Records.
     */
    public function test_maintenance_layout_renders_during_an_upgrade(): void {
        global $CFG;

        $this->setAdminUser();
        $CFG->upgraderunning = time();

        $html = $this->render_page('maintenance');

        $this->assertStringNotContainsString('sr-topbar', $html);
    }

    /**
     * Student Records fills the left drawer on its own pages, with the theme's labels. Skipped without the plugin.
     */
    public function test_student_records_menu_goes_in_the_drawer(): void {
        if (\core_component::get_component_directory(records::COMPONENT) === null) {
            $this->markTestSkipped('Student Records is not installed.');
        }
        $this->setAdminUser();
        $renderer = $this->theme_renderer('/local/studentrecords/index.php', 'standard');

        $html = $renderer->header() . $renderer->footer();

        $this->assertDebuggingNotCalled();
        $this->assertStringContainsString('class="sr-drawer-heading"', $html);
        $this->assertStringContainsString(get_string('openmenu', 'theme_studentrecords'), $html);
        $this->assertStringContainsString(get_string('closemenu', 'theme_studentrecords'), $html);
        $this->assertStringNotContainsString('id="courseindexdrawercontrols"', $html);
    }

    /**
     * Every template renders from the example in its docblock, with the real helpers of this Moodle.
     *
     * @dataProvider template_provider
     * @param string $name The template name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('template_provider')]
    public function test_template_renders_from_its_example(string $name): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/theme/studentrecords/templates/' . $name . '.mustache');
        $this->assertSame(1, preg_match('/Example context \(json\):\s*(\{.*?\n    \})\n\}\}/s', $source, $matches));
        $context = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        $html = $this->theme_renderer('/index.php', 'standard')->render_from_template('theme_studentrecords/' . $name, $context);

        $this->assertDebuggingNotCalled();
        $this->assertNotSame('', trim($html));
    }

    /**
     * The templates of the theme.
     *
     * @return array
     */
    public static function template_provider(): array {
        $names = ['brand', 'drawers', 'login', 'loginform', 'navbar'];
        return array_combine($names, array_map(fn(string $name): array => [$name], $names));
    }

    /**
     * The values in the top bar and the drawer are printed as text, escaped once.
     */
    public function test_templates_escape_the_top_bar_text(): void {
        $renderer = $this->theme_renderer('/index.php', 'standard');
        $brand = ['schoolname' => '"><script>alert(1)</script> & Co', 'initials' => '<b>', 'logourl' => '', 'homeurl' => '#'];

        $html = $renderer->render_from_template('theme_studentrecords/navbar', [
            'srbrand' => $brand,
            'srnote' => '<script>alert(2)</script> & more',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt; &amp; more', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('aria-label="&quot;&gt;&lt;script&gt;', $html);
    }
}
