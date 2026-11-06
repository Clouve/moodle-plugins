<?php

/**
 * Academic Transcript - version information.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_academictranscript';
$plugin->version   = 2026111607;
$plugin->requires  = 2024100700; // Moodle 4.5.
$plugin->supported = [405, 503];
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.4.3';
