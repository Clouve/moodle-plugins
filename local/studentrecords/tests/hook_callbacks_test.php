<?php

namespace local_studentrecords;

#[\PHPUnit\Framework\Attributes\CoversClass(hook_callbacks::class)]
/**
 * Tests for the hooks: each one exists on this Moodle version, and the callbacks work.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Every hook in db/hooks.php is a real class here, and every callback is a real method.
     */
    public function test_declared_hooks_exist(): void {
        $callbacks = [];
        require(__DIR__ . '/../db/hooks.php');
        $this->assertNotEmpty($callbacks);
        foreach ($callbacks as $callback) {
            $this->assertTrue(class_exists($callback['hook']), $callback['hook']);
            $this->assertTrue(is_callable($callback['callback']));
        }
    }

    /**
     * The newer core classes that the callbacks name exist on this Moodle version.
     */
    public function test_core_classes_exist(): void {
        $this->assertTrue(class_exists(\core\url::class));
        $this->assertTrue(class_exists(\core\lang_string::class));
    }

    /**
     * The Student Records home page is offered as a start page.
     */
    public function test_extend_default_homepage(): void {
        $hook = new \core_user\hook\extend_default_homepage();
        hook_callbacks::extend_default_homepage($hook);
        $this->assertArrayHasKey(local\home::URL, $hook->get_options());
    }

    /**
     * The check after setup does nothing from a command line script, so cron and upgrades are safe. Tests run as one.
     */
    public function test_after_config_leaves_scripts_alone(): void {
        global $SCRIPT;
        $this->resetAfterTest();
        $SCRIPT = '/course/view.php';
        hook_callbacks::after_config(new \core\hook\after_config());
        $this->assertTrue(CLI_SCRIPT);
    }
}
