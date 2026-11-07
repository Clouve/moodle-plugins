<?php

namespace local_studentrecords\local;

/**
 * Real locks that note the state of a test each time one is taken and released. Set $CFG->lock_factory to this class.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class recording_lock_factory extends \core\lock\db_record_lock_factory {
    /** @var callable Gives the state of the test. */
    public static $state;

    /** @var array What was noted, in order: [taken, resource, state] or [released, state]. */
    public static $notes = [];

    /** @var string A resource someone else holds: asking for its lock gets nothing. */
    public static $busy = '';

    /** @var string[] Resources of the locks that are open, by key. */
    private static $open = [];

    /**
     * Take a lock.
     *
     * @param string $resource
     * @param int $timeout
     * @param int $maxlifetime
     * @return \core\lock\lock|false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        if ($resource === self::$busy) {
            return false;
        }
        $lock = parent::get_lock($resource, $timeout, $maxlifetime);
        if ($lock) {
            self::$open[$lock->get_key()] = $resource;
            self::$notes[] = ['taken', $resource, (self::$state)()];
        }
        return $lock;
    }

    /**
     * Release a lock.
     *
     * @param \core\lock\lock $lock
     * @return bool
     */
    public function release_lock(\core\lock\lock $lock) {
        self::$notes[] = ['released', self::$open[$lock->get_key()], (self::$state)()];
        return parent::release_lock($lock);
    }
}
