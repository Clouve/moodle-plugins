<?php

namespace local_studentrecords;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Language file checks: every capability has a name; strings stay in A-Z order, one per line.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @coversNothing
 */
final class lang_test extends \basic_testcase {
    /**
     * Every capability in db/access.php has its name in the language file.
     */
    public function test_capability_names(): void {
        $dir = dirname(__DIR__);
        preg_match_all("/'local\/studentrecords:([a-z]+)'\s*=>/", file_get_contents($dir . '/db/access.php'), $m);
        $lang = file_get_contents($dir . '/lang/en/local_studentrecords.php');
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $cap) {
            $this->assertStringContainsString("\$string['studentrecords:$cap']", $lang, "capability $cap has a name");
        }
    }

    /**
     * Strings are in A-Z order (one per line), so new ones are easy to find and none is there twice.
     */
    public function test_order(): void {
        $file = file_get_contents(dirname(__DIR__) . '/lang/en/local_studentrecords.php');
        preg_match_all('/^\$string\[\'([^\']+)\'\]/m', $file, $m);
        $sorted = $m[1];
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $m[1]);
        $this->assertSame(count($m[1]), count(array_unique($m[1])));
    }
}
