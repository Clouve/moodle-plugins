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

namespace local_academictranscript\local;

/**
 * Activity log: who viewed, downloaded, issued, posted, changed or verified a transcript.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logger {
    /** @var string[] Known actions. */
    const ACTIONS = ['view', 'unofficial', 'issue', 'redownload', 'verify_ok', 'verify_fail', 'post',
        'change_request', 'change_approve', 'change_reject', 'transfer', 'termoverride'];

    /**
     * Write a log entry.
     *
     * @param string $action
     * @param int $studentid
     * @param int $issueid
     * @param string $info
     */
    public static function log(string $action, int $studentid = 0, int $issueid = 0, string $info = ''): void {
        global $DB, $USER;
        $DB->insert_record('local_academictranscript_log', (object)[
            'action' => $action,
            'userid' => isloggedin() ? (int)$USER->id : 0,
            'studentid' => $studentid,
            'issueid' => $issueid,
            'ip' => getremoteaddr(''),
            'info' => \core_text::substr($info, 0, 255),
            'timecreated' => time(),
        ]);
    }
}
