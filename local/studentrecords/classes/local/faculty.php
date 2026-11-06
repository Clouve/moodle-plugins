<?php

namespace local_studentrecords\local;

/**
 * Faculty and staff records: one file per person, linked to a Moodle account when they have one.
 * Degrees, licenses and the courses each teacher is qualified to teach. No pay or HR data.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class faculty {
    /** @var string Table. */
    const TABLE = 'local_studentrecords_faculty';

    /** @var string[] Optional personal data, removed on a privacy deletion request. */
    const PERSONAL = ['email', 'phone', 'officehours', 'notes'];

    /** @var string[] Credential types. */
    const CREDTYPES = ['degree', 'transcript', 'license', 'certification', 'other'];

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
     * Create or update a faculty or staff record.
     *
     * @param array|\stdClass $data
     * @return int
     */
    public static function save($data): int {
        $data = (array)$data;
        $isnew = empty($data['id']);
        $userid = !empty($data['userid']) ? (int)$data['userid'] : (!$isnew ? (int)self::get($data['id'])->userid : null);
        $id = records::save(self::TABLE, $data, 'form', $userid ?: null);
        home::reset_cache();
        if ($isnew || records::$lastchanged) {
            audit::log($isnew ? 'create' : 'update', 'faculty', $id, $userid ?: null);
        }
        return $id;
    }

    /**
     * Credentials on file, newest first.
     *
     * @param int $facultyid
     * @return array
     */
    public static function credentials(int $facultyid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_studentrecords_faccred',
            ['facultyid' => $facultyid],
            'yearawarded DESC, id'
        ));
    }

    /**
     * Save a credential.
     *
     * @param array|\stdClass $data
     * @return int
     */
    public static function save_credential($data): int {
        $data = (array)$data;
        $f = self::get((int)$data['facultyid']);
        $id = records::save('local_studentrecords_faccred', $data, 'form', $f->userid ? (int)$f->userid : null);
        audit::log('update', 'faculty', (int)$f->id, $f->userid ? (int)$f->userid : null, 'credential ' . $id);
        return $id;
    }

    /**
     * Catalog courses the person is qualified to teach, id => "CODE Title" as HTML.
     *
     * @param int $facultyid
     * @return array
     */
    public static function qualified_courses(int $facultyid): array {
        global $DB;
        $out = [];
        $sql = "SELECT c.id, c.code, c.title
                  FROM {local_studentrecords_facqual} q
                  JOIN {local_studentrecords_course} c ON c.id = q.courseid
                 WHERE q.facultyid = ? AND q.active = 1
              ORDER BY c.code";
        foreach ($DB->get_records_sql($sql, [$facultyid]) as $c) {
            $out[$c->id] = s($c->code) . ' ' . format_string($c->title);
        }
        return $out;
    }

    /**
     * Codes of the catalog courses the person is qualified to teach, as plain text.
     *
     * @param int $facultyid
     * @return string[]
     */
    public static function qualified_codes(int $facultyid): array {
        global $DB;
        return $DB->get_fieldset_sql("SELECT c.code
                                        FROM {local_studentrecords_facqual} q
                                        JOIN {local_studentrecords_course} c ON c.id = q.courseid
                                       WHERE q.facultyid = ? AND q.active = 1
                                    ORDER BY c.code", [$facultyid]);
    }

    /**
     * Qualifications that were removed, newest first, as rows with code, title, timeremoved and removedby.
     *
     * @param int $facultyid
     * @return array
     */
    public static function removed_qualifications(int $facultyid): array {
        global $DB;
        $sql = "SELECT q.id, q.courseid, q.timeremoved, q.removedby, c.code, c.title
                  FROM {local_studentrecords_facqual} q
                  JOIN {local_studentrecords_course} c ON c.id = q.courseid
                 WHERE q.facultyid = ? AND q.active = 0
              ORDER BY q.timeremoved DESC, c.code";
        return array_values($DB->get_records_sql($sql, [$facultyid]));
    }

    /**
     * Mark the person as qualified to teach courses (adds, or brings back a removed qualification).
     *
     * @param int $facultyid
     * @param int[] $courseids
     * @param string $source form or import
     * @return int number added
     */
    public static function add_qualifications(int $facultyid, array $courseids, string $source = 'form'): int {
        global $DB;
        $f = self::get($facultyid);
        $n = 0;
        foreach (array_unique($courseids) as $cid) {
            if (!$DB->record_exists('local_studentrecords_course', ['id' => $cid])) {
                continue;
            }
            $row = $DB->get_record('local_studentrecords_facqual', ['facultyid' => $facultyid, 'courseid' => $cid]);
            if ($row && $row->active) {
                continue;
            }
            $record = ['facultyid' => $facultyid, 'courseid' => $cid, 'active' => 1, 'timeremoved' => null, 'removedby' => null];
            if ($row) {
                $record['id'] = $row->id;
            }
            records::save('local_studentrecords_facqual', $record, $source, $f->userid ? (int)$f->userid : null);
            $n++;
        }
        if ($n) {
            audit::log('update', 'faculty', $facultyid, $f->userid ? (int)$f->userid : null, 'qualified +' . $n);
        }
        return $n;
    }

    /**
     * Remove qualifications: the rows are kept, marked inactive with the date and who removed them.
     *
     * @param int $facultyid
     * @param int[] $courseids
     * @return int number removed
     */
    public static function remove_qualifications(int $facultyid, array $courseids): int {
        global $DB, $USER;
        $f = self::get($facultyid);
        $n = 0;
        foreach (array_unique($courseids) as $cid) {
            $row = $DB->get_record('local_studentrecords_facqual', ['facultyid' => $facultyid, 'courseid' => $cid,
                'active' => 1]);
            if (!$row) {
                continue;
            }
            records::save('local_studentrecords_facqual', ['id' => $row->id, 'active' => 0, 'timeremoved' => time(),
                'removedby' => (int)$USER->id], 'form', $f->userid ? (int)$f->userid : null);
            $n++;
        }
        if ($n) {
            audit::log('update', 'faculty', $facultyid, $f->userid ? (int)$f->userid : null, 'qualified -' . $n);
        }
        return $n;
    }

    /**
     * Whether the person is marked qualified to teach a catalog course.
     *
     * @param int $facultyid
     * @param int $courseid
     * @return bool
     */
    public static function is_qualified(int $facultyid, int $courseid): bool {
        global $DB;
        return $DB->record_exists('local_studentrecords_facqual', ['facultyid' => $facultyid, 'courseid' => $courseid,
            'active' => 1]);
    }

    /**
     * Students this person advises.
     *
     * @param int $facultyid
     * @return array
     */
    public static function advisees(int $facultyid): array {
        global $DB;
        return array_values($DB->get_records(students::TABLE, ['advisorid' => $facultyid], 'legallastname, legalfirstname'));
    }

    /**
     * Roster rows for the faculty roster report (state or accreditor review).
     *
     * @param bool $activeonly
     * @return array list of arrays
     */
    public static function roster(bool $activeonly = true): array {
        global $DB;
        $rows = [];
        $params = ['stafftype' => 'faculty'] + ($activeonly ? ['active' => 1] : []);
        foreach ($DB->get_records(self::TABLE, $params, 'lastname, firstname') as $f) {
            $creds = array_map(
                fn($c) => trim($c->title . ($c->subject ? ', ' . $c->subject : '') .
                ($c->institution ? ', ' . $c->institution : '') . ($c->yearawarded ? ', ' . $c->yearawarded : '') .
                ($c->expires ? ' (' . get_string('expires', 'local_studentrecords') . ' ' .
                userdate($c->expires, get_string('strftimedatefullshort', 'langconfig'), catalog::timezone()) . ')' : '')),
                self::credentials($f->id)
            );
            $rows[] = ['id' => $f->id, 'name' => catalog::faculty_name($f), 'department' => (string)$f->department,
                'jobtitle' => (string)$f->jobtitle,
                'employment' => $f->employment ? get_string('load_' . $f->employment, 'local_studentrecords') : '',
                'active' => $f->active, 'credentials' => implode('; ', $creds),
                'courses' => implode('; ', self::qualified_codes((int)$f->id)),
                'account' => $f->userid ? 1 : 0];
        }
        return $rows;
    }

    /**
     * Privacy deletion request for a person with a Moodle account: remove optional contact data, keep the
     * name, credentials and qualifications as the school's staffing record.
     *
     * @param int $userid
     */
    public static function erase_personal(int $userid): void {
        global $DB;
        foreach ($DB->get_records(self::TABLE, ['userid' => $userid]) as $f) {
            $update = ['id' => $f->id];
            foreach (self::PERSONAL as $field) {
                $update[$field] = null;
            }
            $DB->update_record(self::TABLE, (object)$update);
            [$in, $params] = $DB->get_in_or_equal(self::PERSONAL);
            $DB->execute("UPDATE {local_studentrecords_history} SET oldvalue = NULL, newvalue = NULL
                           WHERE tablename = ? AND recordid = ? AND fieldname $in", array_merge([self::TABLE, $f->id], $params));
            records::history(self::TABLE, (int)$f->id, '*', null, null, 'privacy', $userid);
            audit::log('privacyerase', 'faculty', (int)$f->id, $userid);
        }
    }
}
