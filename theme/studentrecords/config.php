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

/**
 * Student Records theme - a small child theme of Boost.
 *
 * @package    theme_studentrecords
 * @copyright  2016 Frédéric Massart - FMCorz.net
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

$THEME->name = 'studentrecords';
$THEME->parents = ['boost'];
$THEME->sheets = [];
$THEME->editor_sheets = [];
$THEME->usefallback = true;
$THEME->scss = function ($theme) {
    return theme_studentrecords_get_main_scss_content($theme);
};
$THEME->prescsscallback = 'theme_studentrecords_get_pre_scss';
$THEME->rendererfactory = 'theme_overridden_renderer_factory';
$THEME->requiredblocks = '';
$THEME->addblockposition = BLOCK_ADDBLOCK_POSITION_FLATNAV;
$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;
$THEME->haseditswitch = true;
$THEME->usescourseindex = true;
$THEME->enable_dock = false;
$THEME->yuicssmodules = [];
$THEME->activityheaderconfig = ['notitle' => true];

// Same layouts as Boost. Layout files are looked up in this theme first, then in Boost: this theme has its own
// drawers.php (top bar, Student Records cards) and login.php (school login card).
$sidepre = ['file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'];
$THEME->layouts = [
    'base' => ['file' => 'drawers.php', 'regions' => []],
    'standard' => $sidepre,
    'course' => $sidepre + ['options' => ['langmenu' => true]],
    'coursecategory' => $sidepre,
    'incourse' => $sidepre,
    'frontpage' => $sidepre + ['options' => ['nonavbar' => true]],
    'admin' => $sidepre,
    'mycourses' => $sidepre + ['options' => ['nonavbar' => true]],
    'mydashboard' => $sidepre + ['options' => ['nonavbar' => true, 'langmenu' => true]],
    'mypublic' => $sidepre,
    'login' => ['file' => 'login.php', 'regions' => [], 'options' => ['langmenu' => true]],
    'popup' => [
        'file' => 'columns1.php',
        'regions' => [],
        'options' => [
            'nofooter' => true,
            'nonavbar' => true,
            'activityheader' => ['notitle' => true, 'nocompletion' => true, 'nodescription' => true],
        ],
    ],
    'frametop' => [
        'file' => 'columns1.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nocoursefooter' => true, 'activityheader' => ['nocompletion' => true]],
    ],
    'embedded' => ['file' => 'embedded.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'maintenance' => ['file' => 'maintenance.php', 'regions' => []],
    'print' => [
        'file' => 'columns1.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nonavbar' => false, 'noactivityheader' => true],
    ],
    'redirect' => ['file' => 'embedded.php', 'regions' => []],
    'report' => $sidepre,
    'secure' => [
        'file' => 'secure.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options' => ['activityheader' => ['notitle' => false]],
    ],
];
