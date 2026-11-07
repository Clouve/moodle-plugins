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

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests that build the theme's real CSS with the SCSS of this Moodle. Boost's SCSS changes between versions, so this
 * is the check that the theme still compiles.
 *
 * @package    theme_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class scss_test extends \advanced_testcase {
    /**
     * Start each test with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The whole sheet compiles with the school's colour. A failed build would give Boost's CSS, without the theme's.
     */
    public function test_css_compiles_with_the_accent(): void {
        set_config('accentcolor', '#1a2b3c', 'theme_studentrecords');
        $theme = \theme_config::load('studentrecords');

        $css = $theme->get_css_content();

        $this->assertDebuggingNotCalled();
        $this->assertStringContainsString('.sr-topbar', $css);
        $this->assertStringContainsString('.sr-login-card', $css);
        $this->assertStringContainsString('--sr-accent:#1a2b3c', $css);
        // Moodle 5.3's dark mode swaps the theme's own colours.
        $this->assertMatchesRegularExpression('/\[data-bs-theme="?dark"?\]\s*\{\s*--sr-border:/', $css);
        // Written out, so a phone means the same width on Bootstrap 4 and Bootstrap 5.
        $this->assertMatchesRegularExpression('/@media \(max-width: ?575\.98px\)\s*\{\s*\.sr-brand\s*\{\s*min-width:\s*0/', $css);
    }

    /**
     * Boost clears the frame of #region-main on login pages with a selector that has an id. The login card outranks it
     * with the theme's own colours, which dark mode swaps, and the phone rule outranks it too, so a phone keeps no frame.
     */
    public function test_login_card_frame_outranks_boost(): void {
        $css = \theme_config::load('studentrecords')->get_css_content();

        $this->assertDebuggingNotCalled();
        $card = '\.pagelayout-login #region-main\.sr-login-card\s*\{\s*';
        $this->assertMatchesRegularExpression(
            '/' . $card . 'background:\s*var\(--sr-surface\);\s*border:\s*1px solid var\(--sr-border\);?\s*\}/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: ?575\.98px\)\s*\{[^@]*' . $card . 'background:\s*(transparent|#fff0);\s*border:\s*0;?\s*\}/',
            $css
        );
    }

    /**
     * The editor's styles come from Boost. The theme has no editor.scss of its own.
     */
    public function test_editor_css_comes_from_boost(): void {
        $css = \theme_config::load('studentrecords')->editor_scss_to_css();

        $this->assertDebuggingNotCalled();
        $this->assertSame(\theme_config::load('boost')->editor_scss_to_css(), $css);
        $this->assertNotSame('', $css);
    }

    /**
     * The CSS for when a build fails is Boost's, once.
     */
    public function test_precompiled_css_is_boost_css_once(): void {
        $theme = \theme_config::load('studentrecords');

        $this->assertSame(theme_boost_get_precompiled_css(), $theme->get_precompiled_css_content());
    }
}
