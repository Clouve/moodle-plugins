<?php

/**
 * Scheduled tasks.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_studentrecords\task\billing_daily',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\local_studentrecords\task\waitlist_daily',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
