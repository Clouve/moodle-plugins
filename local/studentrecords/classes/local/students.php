<?php

namespace local_studentrecords\local;

/**
 * Student records: one official file per student, linked to the Moodle user.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class students {
    /** @var string Table. */
    const TABLE = 'local_studentrecords_student';

    /** @var string[] Directory information, hidden from teachers when the student opts out (FERPA). */
    const DIRECTORY = ['preferredname', 'dob', 'email', 'phone', 'usaddress', 'homeaddress', 'homecountry'];

    /** @var string[] Optional personal data, removed on a privacy deletion request. The academic record stays. */
    const PERSONAL = ['preferredname', 'email', 'phone', 'usaddress', 'homeaddress', 'homecountry', 'visatype', 'sevisid',
        'notes', 'highschool', 'hsyear', 'residency', 'citizenship', 'firstgen'];

    /** @var array Other tables with optional personal data: table => fields blanked on a privacy deletion request. */
    const PERSONALPARTS = [
        'local_studentrecords_emerg' => ['name', 'relationship', 'phone', 'email', 'notes'],
        'local_studentrecords_demog' => ['sex', 'hispanic', 'races'],
        'local_studentrecords_appdoc' => ['note'],
        'local_studentrecords_educ' => ['notes'],
    ];

    /** @var string[] Fields of the F-1 module, shown only when the module is on. */
    const F1 = ['isf1', 'visatype', 'sevisid'];

    /**
     * The record of a Moodle user, or null.
     *
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_by_user(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['userid' => $userid]) ?: null;
    }

    /**
     * Create or update a student record (change history and audit log are written).
     *
     * @param array|\stdClass $data
     * @return int record id
     */
    public static function save($data): int {
        global $DB;
        $data = (array)$data;
        $isnew = empty($data['id']);
        $current = null;
        if (!$isnew) {
            $current = self::get($data['id']);
            $data['userid'] = $current->userid;
        }
        $note = trim((string)($data['statusnote'] ?? ''));
        unset($data['statusnote']);
        $transaction = $DB->start_delegated_transaction();
        if (trim((string)($data['studentid'] ?? '')) === '' && admissions::auto_id()) {
            $data['studentid'] = $current ? $current->studentid : admissions::next_student_id();
        }
        $id = records::save(self::TABLE, $data, 'form', (int)$data['userid']);
        $changed = records::$lastchanged;
        $old = $current ? $current->status : null;
        if (isset($data['status']) && $data['status'] !== $old) {
            // The status history is dated by the withdrawal date for a withdrawal, else today.
            $effective = $data['status'] === 'withdrawn' && !empty($data['withdrawdate']) ? (int)$data['withdrawdate'] : time();
            admissions::add_status_history($id, $old, $data['status'], $effective, $note);
        }
        $transaction->allow_commit();
        if ($isnew || $changed) {
            audit::log($isnew ? 'create' : 'update', 'student', $id, (int)$data['userid']);
        }
        return $id;
    }

    /**
     * A record by id.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Name to show: legal name, with the preferred name when there is one. A preferred name is directory information,
     * so it is left out where access::hide_directory() says so.
     *
     * @param \stdClass $s
     * @param bool $hidepreferred
     * @return string
     */
    public static function display_name(\stdClass $s, bool $hidepreferred = false): string {
        $name = trim($s->legalfirstname . ' ' . ($s->legalmiddlename ? $s->legalmiddlename . ' ' : '') . $s->legallastname);
        return $s->preferredname && !$hidepreferred ? $name . ' (' . $s->preferredname . ')' : $name;
    }

    /**
     * Privacy deletion request: remove optional personal data and documents; keep the permanent academic
     * record (legal name, student ID, date of birth, program, terms, status, award). The values removed are
     * also blanked in the change history. The shopping cart, waitlist places and message log hold nothing the school must
     * keep, so those rows go. All of it happens or none of it.
     *
     * @param int $userid
     */
    public static function erase_personal(int $userid): void {
        global $DB;
        $s = self::get_by_user($userid);
        if (!$s) {
            return;
        }
        $transaction = $DB->start_delegated_transaction();
        $update = ['id' => $s->id];
        foreach (self::PERSONAL as $f) {
            $update[$f] = null;
        }
        $DB->update_record(self::TABLE, (object)$update);
        self::blank_history(self::TABLE, (int)$s->id, self::PERSONAL);
        records::history(self::TABLE, (int)$s->id, '*', null, null, 'privacy', $userid);
        foreach (self::PERSONALPARTS as $table => $fields) {
            foreach ($DB->get_records($table, ['studentid' => $s->id]) as $row) {
                $blank = ['id' => $row->id] + array_fill_keys($fields, null);
                if ($table === 'local_studentrecords_emerg') {
                    $blank['active'] = 0;
                }
                $DB->update_record($table, (object)$blank);
                self::blank_history($table, (int)$row->id, $fields);
            }
        }
        foreach (['local_studentrecords_cart', 'local_studentrecords_wait', 'local_studentrecords_msglog'] as $table) {
            $DB->delete_records($table, ['studentid' => $s->id]);
        }
        get_file_storage()->delete_area_files(\context_system::instance()->id, 'local_studentrecords', 'studentdocs', $s->id);
        audit::log('privacyerase', 'student', (int)$s->id, $userid);
        $transaction->allow_commit();
    }

    /**
     * Blank the old and new values of fields in the change history of one row.
     *
     * @param string $table
     * @param int $recordid
     * @param string[] $fields
     */
    protected static function blank_history(string $table, int $recordid, array $fields): void {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal($fields);
        $DB->execute("UPDATE {local_studentrecords_history} SET oldvalue = NULL, newvalue = NULL
                       WHERE tablename = ? AND recordid = ? AND fieldname $in", array_merge([$table, $recordid], $params));
    }
}
