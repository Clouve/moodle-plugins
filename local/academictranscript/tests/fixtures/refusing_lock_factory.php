<?php

namespace local_academictranscript\tests;

/**
 * A lock factory that gives no lock, to make a write fail part way through in a test.
 *
 * A lock taken in the test itself is no good for that: MySQL and MariaDB give the same session the same lock again.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class refusing_lock_factory implements \core\lock\lock_factory {
    /**
     * Constructor.
     *
     * @param string $type
     */
    public function __construct($type) {
    }

    /**
     * Whether a timeout is supported.
     *
     * @return bool
     */
    public function supports_timeout() {
        return true;
    }

    /**
     * Whether a lock is released on its own.
     *
     * @return bool
     */
    public function supports_auto_release() {
        return true;
    }

    /**
     * Whether this factory can be used.
     *
     * @return bool
     */
    public function is_available() {
        return true;
    }

    /**
     * Give no lock.
     *
     * @param string $resource
     * @param int $timeout
     * @param int $maxlifetime
     * @return false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        return false;
    }

    /**
     * Release a lock.
     *
     * @param \core\lock\lock $lock
     * @return bool
     */
    public function release_lock(\core\lock\lock $lock) {
        return true;
    }
}
