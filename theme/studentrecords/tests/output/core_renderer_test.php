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

namespace theme_studentrecords\output;

#[\PHPUnit\Framework\Attributes\CoversClass(core_renderer::class)]
/**
 * Tests for the login form of the theme.
 *
 * @package    theme_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_studentrecords\output\core_renderer
 */
final class core_renderer_test extends \advanced_testcase {
    /**
     * Start each test with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Render the login form the way login/index.php does.
     *
     * @return string HTML
     */
    private function render_login(): string {
        global $PAGE, $SITE;

        $PAGE->set_course($SITE);
        $PAGE->set_url('/login/index.php');
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('login');
        $PAGE->force_theme('studentrecords');
        $PAGE->initialise_theme_and_output();
        $renderer = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL);
        $this->assertInstanceOf(core_renderer::class, $renderer);

        $html = $renderer->render(new \core_auth\output\login(get_enabled_auth_plugins(), ''));
        $this->assertDebuggingNotCalled();
        return $html;
    }

    /**
     * The form is Moodle's own for this version, with every string found, inside the school's card.
     */
    public function test_render_login_wraps_the_form_of_this_moodle(): void {
        set_config('schoolname', 'Riverside College', 'theme_studentrecords');
        set_config('loginwelcome', 'Welcome back to class.', 'theme_studentrecords');
        set_config('loginhelp', 'Call the office for help.', 'theme_studentrecords');

        $html = $this->render_login();

        $this->assertStringContainsString('<h1 class="h2 mt-3 mb-2">Riverside College</h1>', $html);
        $this->assertStringContainsString('Welcome back to class.', $html);
        $this->assertStringContainsString('Call the office for help.', $html);
        $this->assertStringContainsString('id="username"', $html);
        $this->assertStringContainsString('id="password"', $html);
        $this->assertStringContainsString('name="logintoken"', $html);
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Without a welcome text the card says where the user is logging in.
     */
    public function test_render_login_without_welcome_text(): void {
        set_config('schoolname', 'Riverside College', 'theme_studentrecords');
        set_config('loginwelcome', '', 'theme_studentrecords');

        $html = $this->render_login();

        $this->assertStringContainsString(get_string('loginto', 'core', 'Riverside College'), $html);
    }

    /**
     * The settings are printed as text, escaped once.
     */
    public function test_render_login_escapes_the_settings(): void {
        set_config('schoolname', 'Hill & Dale', 'theme_studentrecords');
        set_config('loginwelcome', '<script>alert("welcome")</script> & more', 'theme_studentrecords');
        set_config('loginhelp', '<img src=x onerror=alert(1)> & more', 'theme_studentrecords');

        $html = $this->render_login();

        $this->assertStringContainsString('Hill &amp; Dale', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;welcome&quot;)&lt;/script&gt; &amp; more', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; &amp; more', $html);
        $this->assertStringNotContainsString('<script>alert(', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }
}
