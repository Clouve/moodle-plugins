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
 * Capabilities.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Students: view their own transcript and download an Unofficial copy.
    'local/academictranscript:viewown' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['user' => CAP_ALLOW],
    ],
    // Teachers (for students in their own courses) and admins: issue Official transcripts.
    'local/academictranscript:issueofficial' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    // Hand-entered marks (P, NP, I, NC, W and custom marks) and footnotes.
    'local/academictranscript:entercodes' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    // Admins: setup wizard, grading scale, marks, programs, catalog.
    'local/academictranscript:configure' => [
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
    // Teachers (own courses) and admins: post final grades; request changes to posted grades.
    'local/academictranscript:postgrades' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    // Admins: approve or reject changes to posted grades.
    'local/academictranscript:approvechanges' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
    // Admins: student details, terms, transfer credit, term overrides.
    'local/academictranscript:managerecords' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
    // Admins: log of issued Official transcripts and the course check page.
    'local/academictranscript:viewissued' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
];
