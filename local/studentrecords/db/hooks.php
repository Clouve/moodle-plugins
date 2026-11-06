<?php

/**
 * Hook callbacks: the Student Records home page as a start page option, and the top menu for No LMS staff.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core_user\hook\extend_default_homepage::class,
        'callback' => [\local_studentrecords\hook_callbacks::class, 'extend_default_homepage'],
    ],
    [
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_studentrecords\hook_callbacks::class, 'primary_extend'],
    ],
    [
        'hook' => \core\hook\navigation\secondary_extend::class,
        'callback' => [\local_studentrecords\hook_callbacks::class, 'secondary_extend'],
    ],
    [
        'hook' => \core\hook\after_config::class,
        'callback' => [\local_studentrecords\hook_callbacks::class, 'after_config'],
    ],
];
