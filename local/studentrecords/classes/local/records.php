<?php

namespace local_studentrecords\local;

/**
 * Saves records of this plugin and keeps the change history: for every changed field, who, when, the old
 * value and the new value. All writes to the plugin's record tables go through here.
 *
 * Nothing is ever deleted by this class.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class records {
    /** @var bool Whether the last save() changed anything. */
    public static $lastchanged = false;

    /** @var string[] Fields that are bookkeeping, not record content. */
    const SKIP = ['id', 'usermodified', 'timemodified', 'timecreated'];

    /**
     * Insert or update a record and write the change history.
     *
     * @param string $table table name without prefix, for example local_studentrecords_student
     * @param array|\stdClass $data fields to set; with 'id' to update
     * @param string $source form, import or privacy
     * @param int|null $relateduserid the Moodle user the record is about, if any
     * @return int record id
     */
    public static function save(string $table, $data, string $source = 'form', ?int $relateduserid = null): int {
        global $DB, $USER;
        $data = (array)$data;
        $now = time();
        $columns = $DB->get_columns($table);
        $data = array_intersect_key($data, $columns);
        $changes = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            if (!empty($data['id'])) {
                $old = (array)$DB->get_record($table, ['id' => $data['id']], '*', MUST_EXIST);
                foreach ($data as $field => $value) {
                    if (!in_array($field, self::SKIP) && !self::same($old[$field] ?? null, $value)) {
                        $changes[$field] = [$old[$field] ?? null, $value];
                    }
                }
                if ($changes) {
                    $data['usermodified'] = $USER->id ?? 0;
                    $data['timemodified'] = $now;
                    $DB->update_record($table, (object)array_intersect_key($data, $columns));
                }
                $id = (int)$data['id'];
            } else {
                foreach (['timecreated', 'timemodified'] as $f) {
                    if (isset($columns[$f]) && empty($data[$f])) {
                        $data[$f] = $now;
                    }
                }
                if (isset($columns['usermodified'])) {
                    $data['usermodified'] = $USER->id ?? 0;
                }
                $id = (int)$DB->insert_record($table, (object)$data);
                foreach ($data as $field => $value) {
                    if (!in_array($field, self::SKIP) && $value !== null && $value !== '') {
                        $changes[$field] = [null, $value];
                    }
                }
            }
            self::$lastchanged = (bool)$changes;
            foreach ($changes as $field => [$oldvalue, $newvalue]) {
                self::history($table, $id, $field, $oldvalue, $newvalue, $source, $relateduserid);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Callers that go on after a failed save (the import does) must not be left in an open transaction.
            $transaction->rollback($e);
        }
        return $id;
    }

    /**
     * Change a plugin setting and record it in the history.
     *
     * @param string $name
     * @param string $value
     * @param string $source
     */
    public static function set_setting(string $name, string $value, string $source = 'form'): void {
        $old = get_config('local_studentrecords', $name);
        if ($old !== false && (string)$old === $value) {
            return;
        }
        set_config($name, $value, 'local_studentrecords');
        self::history('config', 0, $name, $old === false ? null : $old, $value, $source, null);
    }

    /**
     * One history row.
     *
     * @param string $table
     * @param int $recordid
     * @param string $field
     * @param mixed $oldvalue
     * @param mixed $newvalue
     * @param string $source
     * @param int|null $relateduserid
     */
    public static function history(
        string $table,
        int $recordid,
        string $field,
        $oldvalue,
        $newvalue,
        string $source,
        ?int $relateduserid
    ): void {
        global $DB, $USER;
        $DB->insert_record('local_studentrecords_history', (object)[
            'tablename' => \core_text::substr($table, 0, 40), 'recordid' => $recordid, 'fieldname' => $field,
            'oldvalue' => $oldvalue === null ? null : (string)$oldvalue,
            'newvalue' => $newvalue === null ? null : (string)$newvalue,
            'relateduserid' => $relateduserid, 'userid' => $USER->id ?? 0, 'source' => $source, 'timecreated' => time(),
        ]);
    }

    /**
     * History of one record, newest first.
     *
     * @param string $table
     * @param int $recordid
     * @return array
     */
    public static function get_history(string $table, int $recordid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_studentrecords_history',
            ['tablename' => $table, 'recordid' => $recordid],
            'timecreated DESC, id DESC'
        ));
    }

    /**
     * Whether a stored value and a new value are the same (numbers compared as numbers, null as empty). Text that only
     * looks like a number stays text: student ID 007 is not student ID 7.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    public static function same($a, $b): bool {
        $a = $a === null ? '' : (string)$a;
        $b = $b === null ? '' : (string)$b;
        $number = '/^-?(0|[1-9]\d{0,14})(\.\d+)?$/';
        if (preg_match($number, $a) && preg_match($number, $b)) {
            return abs((float)$a - (float)$b) < 0.00001;
        }
        return $a === $b;
    }
}
