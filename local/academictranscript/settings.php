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
 * Admin settings and admin pages.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_academictranscript\local\settings_menus;

// Moodle 4.5 adds the Local plugins category only for users who can configure the site (Moodle 5.3 adds it for
// everyone). Staff who hold just the capabilities of this plugin still need its pages, so for them the plugin sits
// under Plugins.
$ADMIN->add($ADMIN->locate('localplugins') ? 'localplugins' : 'modules', new admin_category(
    'local_academictranscript_category',
    get_string('pluginname', 'local_academictranscript')
));

$pages = [
    'setup' => ['setup.php', 'setupwizard', 'local/academictranscript:configure'],
];
foreach ($pages as $name => [$file, $string, $capability]) {
    $ADMIN->add('local_academictranscript_category', new admin_externalpage(
        'local_academictranscript_' . $name,
        get_string($string, 'local_academictranscript'),
        new moodle_url('/local/academictranscript/' . $file),
        $capability
    ));
}

$settings = new admin_settingpage('local_academictranscript', get_string('settings_header', 'local_academictranscript'));
if ($ADMIN->fulltree) {
    $p = 'local_academictranscript';
    $str = fn($key) => get_string($key, $p);

    // School and registrar (blank until the school fills them in).
    $settings->add(new admin_setting_heading("$p/schoolheading", $str('settings_school'), ''));
    $settings->add(new admin_setting_configtext("$p/schoolname", $str('schoolname'), $str('schoolname_desc'), '', PARAM_TEXT, 60));
    $settings->add(new admin_setting_configtextarea("$p/address", $str('address'), $str('address_desc'), '', PARAM_TEXT, 50, 3));
    $settings->add(new admin_setting_configtext("$p/phone", $str('phone'), $str('phone_desc'), '', PARAM_TEXT, 30));
    $settings->add(new admin_setting_configtext(
        "$p/email",
        $str('registraremail'),
        $str('registraremail_desc'),
        '',
        PARAM_TEXT,
        40
    ));
    $settings->add(new admin_setting_configtext("$p/website", $str('website'), $str('website_desc'), '', PARAM_TEXT, 60));
    $settings->add(new admin_setting_configstoredfile(
        "$p/logo",
        $str('logo'),
        $str('logo_desc'),
        'logo',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
    ));
    $settings->add(new admin_setting_configtext(
        "$p/registrarname",
        $str('registrarname'),
        $str('registrarname_desc'),
        '',
        PARAM_TEXT,
        40
    ));
    $settings->add(new admin_setting_configtext(
        "$p/registrartitle",
        $str('registrartitle'),
        $str('registrartitle_desc'),
        '',
        PARAM_TEXT,
        40
    ));

    // Grades and hours.
    $settings->add(new admin_setting_heading("$p/gradesheading", $str('settings_grades'), $str('settings_grades_desc')));
    $settings->add(new admin_setting_configcheckbox("$p/roundpercent", $str('roundpercent'), $str('roundpercent_desc'), 1));
    $settings->add(new admin_setting_configselect(
        "$p/repeatrule",
        $str('repeatrule'),
        $str('repeatrule_desc'),
        'latest',
        settings_menus::repeatrules()
    ));
    $settings->add(new admin_setting_configtext(
        "$p/creditlabel",
        $str('creditlabel'),
        $str('creditlabel_desc'),
        $str('creditlabel_default'),
        PARAM_TEXT,
        30
    ));
    $settings->add(new admin_setting_configtext(
        "$p/clocklabel",
        $str('clocklabel'),
        $str('clocklabel_desc'),
        $str('clocklabel_default'),
        PARAM_TEXT,
        30
    ));

    // Look.
    $settings->add(new admin_setting_heading("$p/lookheading", $str('settings_look'), ''));
    $settings->add(new admin_setting_configselect("$p/pagesize", $str('pagesize'), '', 'LETTER', settings_menus::pagesizes()));
    $settings->add(new admin_setting_configcolourpicker(
        "$p/accentcolor",
        $str('accentcolor'),
        $str('accentcolor_help'),
        '#1F3A5F'
    ));
    $settings->add(new admin_setting_configtextarea(
        "$p/footer",
        $str('footer'),
        $str('footer_desc'),
        $str('footer_default'),
        PARAM_TEXT,
        80,
        3
    ));

    // Signature and seal.
    $settings->add(new admin_setting_heading("$p/sigheading", $str('settings_signature'), ''));
    $settings->add(new admin_setting_configselect(
        "$p/signaturemode",
        $str('signaturemode'),
        $str('signaturemode_desc'),
        'lines',
        settings_menus::signaturemodes()
    ));
    $settings->add(new admin_setting_configstoredfile(
        "$p/signatureimage",
        $str('signatureimage'),
        $str('signatureimage_desc'),
        'signatureimage',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
    ));
    $settings->add(new admin_setting_configstoredfile(
        "$p/sealimage",
        $str('sealimage'),
        $str('sealimage_desc'),
        'sealimage',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
    ));
    $settings->add(new admin_setting_configstoredfile(
        "$p/signingcert",
        $str('signingcert'),
        $str('signingcert_desc'),
        'signingcert',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.p12', '.pfx']]
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        "$p/signingcertpassword",
        $str('signingcertpassword'),
        $str('signingcertpassword_desc'),
        ''
    ));

    // Transcript Key (last page).
    $settings->add(new admin_setting_heading("$p/keyheading", $str('settings_key'), $str('settings_key_desc')));
    $settings->add(new admin_setting_configtext(
        "$p/key_scalenote",
        $str('key_scalenote'),
        $str('key_scalenote_desc'),
        '',
        PARAM_TEXT,
        80
    ));
    foreach (['key_gpa', 'key_units', 'key_coursenumbers', 'key_accreditation', 'key_real', 'key_privacy', 'key_contact'] as $k) {
        if ($k === 'key_units') {
            $settings->add(new admin_setting_configtext(
                "$p/key_units_title",
                $str('key_units_titlesetting'),
                '',
                $str('key_units_title'),
                PARAM_TEXT,
                40
            ));
        }
        $settings->add(new admin_setting_configtextarea(
            "$p/$k",
            $str($k . '_title'),
            '',
            $str($k . '_default'),
            PARAM_TEXT,
            80,
            4
        ));
    }
}
$ADMIN->add('local_academictranscript_category', $settings);

$pages = [
    'grading' => ['grading.php', 'gradingscale_title', 'local/academictranscript:configure'],
    'marks' => ['marks.php', 'marks_title', 'local/academictranscript:configure'],
    'programs' => ['programs.php', 'programs', 'local/academictranscript:configure'],
    'catalog' => ['catalog.php', 'catalog', 'local/academictranscript:configure'],
    'drafts' => ['drafts.php', 'draftspage', 'local/academictranscript:configure'],
    'terms' => ['terms.php', 'terms', 'local/academictranscript:managerecords'],
    'students' => ['students.php', 'studentstranscripts',
        ['local/academictranscript:managerecords', 'local/academictranscript:issueofficial']],
    'approvals' => ['approvals.php', 'approvals', 'local/academictranscript:approvechanges'],
    'issued' => ['issued.php', 'issuedlog', 'local/academictranscript:viewissued'],
    'log' => ['log.php', 'activitylog', 'local/academictranscript:viewissued'],
    'coursecheck' => ['coursecheck.php', 'coursecheck', 'local/academictranscript:viewissued'],
];
foreach ($pages as $name => [$file, $string, $capability]) {
    $ADMIN->add('local_academictranscript_category', new admin_externalpage(
        'local_academictranscript_' . $name,
        get_string($string, 'local_academictranscript'),
        new moodle_url('/local/academictranscript/' . $file),
        $capability
    ));
}
