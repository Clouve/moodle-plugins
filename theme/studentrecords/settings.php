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
 * Student Records theme - settings: school name, logo, accent colour and login page text.
 *
 * @package    theme_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings = new admin_settingpage('themesettingstudentrecords', get_string('configtitle', 'theme_studentrecords'));

    $setting = new admin_setting_configtext(
        'theme_studentrecords/schoolname',
        get_string('schoolname', 'theme_studentrecords'),
        get_string('schoolname_desc', 'theme_studentrecords'),
        '',
        PARAM_TEXT
    );
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    $setting = new admin_setting_configstoredfile(
        'theme_studentrecords/logo',
        get_string('logo', 'theme_studentrecords'),
        get_string('logo_desc', 'theme_studentrecords'),
        'logo',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg', '.svg', '.webp']]
    );
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    $setting = new \theme_studentrecords\admin\setting_accentcolour(
        'theme_studentrecords/accentcolor',
        get_string('accentcolor', 'theme_studentrecords'),
        get_string('accentcolor_desc', 'theme_studentrecords'),
        \theme_studentrecords\local\brand::DEFAULT_ACCENT
    );
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    $settings->add(new admin_setting_configtextarea(
        'theme_studentrecords/loginwelcome',
        get_string('loginwelcome', 'theme_studentrecords'),
        get_string('loginwelcome_desc', 'theme_studentrecords'),
        get_string('loginwelcome_default', 'theme_studentrecords'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'theme_studentrecords/loginhelp',
        get_string('loginhelp', 'theme_studentrecords'),
        get_string('loginhelp_desc', 'theme_studentrecords'),
        '',
        PARAM_TEXT
    ));
}
