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
        'request_cancelled', 'after_saving'];

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

    /**
     * Commit a transaction, and log a failure that comes after the data is saved.
     *
     * Moodle sends what a transaction held back, such as a message, as part of the commit, so a send that fails ends in an
     * exception with the work already saved. The work stands and the log says what happened. Call this after the try block,
     * so the catch never tries to roll back a finished transaction. A commit that really failed still throws.
     *
     * @param \moodle_transaction $transaction
     * @param int $studentid
     */
    public static function commit(\moodle_transaction $transaction, int $studentid = 0): void {
        global $DB;
        try {
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($DB->is_transaction_started() || $e instanceof \dml_transaction_exception) {
                throw $e;
            }
            self::log('after_saving', $studentid, 0, $e->getMessage());
        }
    }
}
