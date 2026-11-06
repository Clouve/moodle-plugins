<?php

namespace local_studentrecords\local;

/**
 * Audit log: who viewed, changed, imported or exported which record. Field-level changes are in the
 * history (see records); this log records the actions.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class audit {
    /** @var string[] Actions that are written to the log. */
    const ACTIONS = ['view', 'create', 'update', 'import', 'approve', 'reject', 'export', 'privacyexport', 'privacyerase'];

    /**
     * Write one log row.
     *
     * @param string $action one of ACTIONS
     * @param string $objecttype student, faculty, program, course, term, setup, import, roster
     * @param int|null $objectid
     * @param int|null $relateduserid Moodle user the record is about
     * @param string $info short note
     */
    public static function log(
        string $action,
        string $objecttype,
        ?int $objectid = null,
        ?int $relateduserid = null,
        string $info = ''
    ): void {
        global $DB, $USER;
        $DB->insert_record('local_studentrecords_log', (object)[
            'action' => $action, 'objecttype' => $objecttype, 'objectid' => $objectid, 'relateduserid' => $relateduserid,
            'userid' => $USER->id ?? 0, 'info' => \core_text::substr($info, 0, 255),
            'ip' => \core_text::substr((string)getremoteaddr(''), 0, 45), 'timecreated' => time(),
        ]);
    }
}
