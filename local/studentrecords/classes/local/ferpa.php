<?php

namespace local_studentrecords\local;

/**
 * FERPA: the annual notice (text per school; acknowledgment per student and academic year, online by the student or
 * recorded by staff from paper) and release authorizations (who may receive which information, from / to dates),
 * shown to staff before information is released.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class ferpa {
    /** @var string Acknowledgments table. */
    const ACKS = 'local_studentrecords_ferpa';

    /** @var string Release authorizations table. */
    const RELEASES = 'local_studentrecords_relauth';

    /** @var string[] What a release may cover. */
    const SCOPES = ['enrollment', 'grades', 'financial', 'conduct', 'all'];

    /**
     * The academic year of a day, from the school's year start ("MM-DD", "8/1", "August 1"; default July 1).
     *
     * @param string $ymd Y-m-d
     * @param string $yearstart
     * @return string for example 2026-2027
     */
    public static function academic_year(string $ymd, string $yearstart): string {
        [$month, $day] = self::year_start($yearstart);
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        $start = $m > $month || ($m === $month && $d >= $day) ? $y : $y - 1;
        return $start . '-' . ($start + 1);
    }

    /**
     * Month and day the academic year starts.
     *
     * @param string $text
     * @return int[] [month, day]
     */
    public static function year_start(string $text): array {
        $text = trim(strtolower($text));
        $months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november',
            'december'];
        if (preg_match('/^(\d{1,2})\s*[-\/.]\s*(\d{1,2})$/', $text, $m) && checkdate((int)$m[1], (int)$m[2], 2001)) {
            return [(int)$m[1], (int)$m[2]];
        }
        foreach ($months as $i => $name) {
            if (str_starts_with($text, substr($name, 0, 3))) {
                $day = preg_match('/(\d{1,2})/', $text, $m) ? (int)$m[1] : 1;
                return [$i + 1, checkdate($i + 1, $day, 2001) ? $day : 1];
            }
        }
        return [7, 1];
    }

    /**
     * Whether a release authorization is in force on a day and covers a kind of information.
     *
     * @param \stdClass|array $r
     * @param int $now
     * @param string $scope one of SCOPES ('' = any)
     * @return bool
     */
    public static function release_active($r, int $now, string $scope = ''): bool {
        $r = (array)$r;
        if (!empty($r['timerevoked']) || (int)$r['fromdate'] > $now || (!empty($r['todate']) && (int)$r['todate'] < $now)) {
            return false;
        }
        $scopes = array_map('trim', explode(',', (string)$r['scope']));
        return $scope === '' || in_array('all', $scopes, true) || in_array($scope, $scopes, true);
    }

    /**
     * The academic year today.
     *
     * @return string
     */
    public static function current_year(): string {
        return self::academic_year(catalog::time_to_date(time()), catalog::setting('yearstart'));
    }

    /**
     * The acknowledgment of a student for an academic year, or null.
     *
     * @param int $studentid
     * @param string|null $year default: this academic year
     * @return \stdClass|null
     */
    public static function acknowledgment(int $studentid, ?string $year = null): ?\stdClass {
        global $DB;
        return $DB->get_record(self::ACKS, ['studentid' => $studentid, 'acadyear' => $year ?? self::current_year()]) ?: null;
    }

    /**
     * Record that the student has read this year's notice.
     *
     * @param \stdClass $student
     * @param string $method online or paper
     * @param int|null $when default now
     */
    public static function acknowledge(\stdClass $student, string $method, ?int $when = null): void {
        global $DB, $USER;
        $year = self::current_year();
        if ($DB->record_exists(self::ACKS, ['studentid' => $student->id, 'acadyear' => $year])) {
            return;
        }
        records::save(self::ACKS, ['studentid' => $student->id, 'acadyear' => $year, 'acknowledged' => $when ?? time(),
            'method' => $method === 'paper' ? 'paper' : 'online', 'recordedby' => $USER->id], 'form', (int)$student->userid);
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'FERPA notice ' . $year . ' ' . $method);
    }

    /**
     * Release authorizations of a student, newest first.
     *
     * @param int $studentid
     * @return array
     */
    public static function releases(int $studentid): array {
        global $DB;
        return array_values($DB->get_records(self::RELEASES, ['studentid' => $studentid], 'timecreated DESC, id DESC'));
    }

    /**
     * Add a release authorization.
     *
     * @param \stdClass $student
     * @param array $data recipient, relationship, scope (list), details, fromdate, todate
     * @return int
     */
    public static function add_release(\stdClass $student, array $data): int {
        global $USER;
        $scope = array_values(array_intersect(self::SCOPES, (array)$data['scope']));
        $id = records::save(
            self::RELEASES,
            ['studentid' => $student->id, 'recipient' => trim($data['recipient']),
            'relationship' => trim((string)$data['relationship']), 'scope' => implode(',', $scope ?: ['enrollment']),
            'details' => trim((string)$data['details']), 'fromdate' => (int)$data['fromdate'] ?: time(),
            'todate' => empty($data['todate']) ? null : (int)$data['todate'], 'createdby' => $USER->id, 'timecreated' => time()],
            'form',
            (int)$student->userid
        );
        $info = 'release authorization ' . trim($data['recipient']);
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, $info);
        return $id;
    }

    /**
     * Revoke a release authorization (kept, with who and when).
     *
     * @param int $id
     */
    public static function revoke_release(int $id): void {
        global $DB, $USER;
        $r = $DB->get_record(self::RELEASES, ['id' => $id], '*', MUST_EXIST);
        if ($r->timerevoked) {
            return;
        }
        $student = students::get((int)$r->studentid);
        records::save(
            self::RELEASES,
            ['id' => $id, 'revokedby' => $USER->id, 'timerevoked' => time()],
            'form',
            (int)$student->userid
        );
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'release authorization revoked');
    }

    /**
     * What staff must see before releasing information: directory opt-out, this year's notice, authorizations in force.
     *
     * @param \stdClass $student
     * @return array optout (bool), acknowledged (object|null), releases (in force)
     */
    public static function release_check(\stdClass $student): array {
        return ['optout' => !empty($student->ferpaoptout), 'acknowledged' => self::acknowledgment((int)$student->id),
            'releases' => array_values(array_filter(
                self::releases((int)$student->id),
                fn($r) => self::release_active($r, time())
            ))];
    }
}
