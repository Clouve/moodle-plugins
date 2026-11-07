<?php

namespace local_academictranscript\tests;

/**
 * A lock factory that gives every lock and writes down when, to show in a test that a lock outlasts a commit.
 *
 * It can also play the other request. A test can't run two requests at once, so $first runs when the lock is asked for:
 * that is the request that got the lock first, and it has finished by the time this one gets it.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class recording_lock_factory implements \core\lock\lock_factory {
    /** @var array What happened, in order: [get or release, resource, whether a transaction was open]. */
    public static array $events = [];

    /** @var callable|null The request that gets there first. It runs once. */
    public static $first = null;

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
     * Give the lock, after the request that got there first.
     *
     * @param string $resource
     * @param int $timeout
     * @param int $maxlifetime
     * @return \core\lock\lock
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        global $DB;
        if (self::$first) {
            $first = self::$first;
            self::$first = null;
            $first();
        }
        self::$events[] = ['get', $resource, $DB->is_transaction_started()];
        return new \core\lock\lock($resource, $this);
    }

    /**
     * Release a lock.
     *
     * @param \core\lock\lock $lock
     * @return bool
     */
    public function release_lock(\core\lock\lock $lock) {
        global $DB;
        self::$events[] = ['release', $lock->get_key(), $DB->is_transaction_started()];
        return true;
    }

    /**
     * Start with nothing written down.
     */
    public static function reset(): void {
        self::$events = [];
        self::$first = null;
    }
}
