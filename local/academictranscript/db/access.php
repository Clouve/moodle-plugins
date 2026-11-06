<?php

/**
 * Capabilities.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
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
