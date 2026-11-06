<?php

namespace local_academictranscript\local;

/**
 * Activity log: who viewed, downloaded, issued, posted, changed or verified a transcript.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class logger {
    /** @var string[] Known actions. */
    const ACTIONS = ['view', 'unofficial', 'issue', 'redownload', 'verify_ok', 'verify_fail', 'post',
        'change_request', 'change_approve', 'change_reject', 'transfer', 'termoverride', 'request', 'request_issued',
        'request_cancelled'];

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
