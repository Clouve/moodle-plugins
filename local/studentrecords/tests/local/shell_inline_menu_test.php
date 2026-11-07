<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(shell::class)]
/**
 * Tests for the menu at the top of the page, on themes without the Student Records drawer.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\shell
 */
final class shell_inline_menu_test extends \advanced_testcase {
    /**
     * Start each test with a clean site and no menu kept from another test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        shell::reset();
    }

    /**
     * Leave no menu behind for the next test.
     */
    protected function tearDown(): void {
        shell::reset();
        parent::tearDown();
    }

    /**
     * Open a Student Records page with a theme, and get the menu for it.
     *
     * @param string $theme
     * @return string HTML
     */
    private function menu_on_a_page(string $theme): string {
        global $PAGE;

        $PAGE->set_url('/local/studentrecords/index.php', ['view' => 'staff']);
        $PAGE->set_context(\context_system::instance());
        $PAGE->force_theme($theme);
        return shell::inline_menu();
    }

    /**
     * Read the menu as a document.
     *
     * @param string $html
     * @return \DOMXPath
     */
    private function parse(string $html): \DOMXPath {
        $dom = new \DOMDocument();
        $dom->loadHTML('<body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    /**
     * The menu starts open on wide screens and folded on small ones, and each is a details element, which opens and closes
     * by mouse, touch and keyboard with no script.
     */
    public function test_menu_is_open_on_wide_screens_and_folded_on_small_ones(): void {
        $this->setAdminUser();

        $html = $this->menu_on_a_page('boost');

        $copies = $this->parse($html)->query('//body/*');
        $this->assertCount(2, $copies);
        [$wide, $small] = iterator_to_array($copies);
        $this->assertSame('details', $wide->nodeName);
        $this->assertSame('sr-inline-menu sr-inline-menu-wide mb-3', $wide->getAttribute('class'));
        $this->assertTrue($wide->hasAttribute('open'));
        $this->assertSame('details', $small->nodeName);
        $this->assertSame('sr-inline-menu sr-inline-menu-small mb-3', $small->getAttribute('class'));
        $this->assertFalse($small->hasAttribute('open'));
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * Each copy has a summary and the whole menu, with the page marked in both, and no id comes twice.
     */
    public function test_each_copy_has_the_whole_menu(): void {
        $this->setAdminUser();

        $xpath = $this->parse($this->menu_on_a_page('boost'));

        $links = [];
        foreach ($xpath->query('//details') as $copy) {
            $summary = $xpath->query('summary', $copy);
            $this->assertCount(1, $summary);
            $this->assertSame(get_string('nav_menu', 'local_studentrecords'), $summary->item(0)->textContent);
            $hrefs = [];
            foreach ($xpath->query('.//a[contains(@class, "sr-nav-link")]', $copy) as $link) {
                $hrefs[] = $link->getAttribute('href');
            }
            $links[] = $hrefs;
            $this->assertCount(1, $xpath->query('.//a[@aria-current="page"]', $copy));
        }
        $this->assertCount(2, $links);
        $this->assertNotEmpty($links[0]);
        $this->assertSame($links[0], $links[1]);

        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertNotEmpty($ids);
        $this->assertSame(array_unique($ids), $ids);
    }

    /**
     * The styles show one copy on each kind of screen, and paper gets the open copy. Nothing else folds the menu, so a page
     * test cannot see this.
     */
    public function test_styles_show_one_copy_for_each_screen_size(): void {
        global $CFG;

        $css = file_get_contents($CFG->dirroot . '/local/studentrecords/styles.css');

        $this->assertMatchesRegularExpression('/\n\.sr-inline-menu-small\s*\{\s*display:\s*none;/', $css);
        $this->assertMatchesRegularExpression(
            '/@media screen and \(max-width: 767\.98px\)\s*\{\s*\.sr-inline-menu-wide\s*\{\s*display:\s*none;\s*\}\s*' .
                '\.sr-inline-menu-small\s*\{\s*display:\s*block;/',
            $css
        );
    }

    /**
     * The Student Records theme has the menu in its drawer, so the page has no menu at the top.
     */
    public function test_student_records_theme_gets_no_menu(): void {
        if (\core_component::get_component_directory('theme_studentrecords') === null) {
            $this->markTestSkipped('The Student Records theme is not installed.');
        }
        $this->setAdminUser();

        $this->assertSame('', $this->menu_on_a_page(shell::THEME));
    }

    /**
     * Somebody with no Student Records pages gets no menu.
     */
    public function test_a_user_without_pages_gets_no_menu(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame('', $this->menu_on_a_page('boost'));
    }
}
