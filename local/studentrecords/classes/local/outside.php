<?php

namespace local_studentrecords\local;

/**
 * Outside reports for any state: saved exports (the school picks the students and the fields its state agency or
 * accreditor asks for, and names the columns), headcount and completions counts by IPEDS race/ethnicity and sex, and a
 * National Student Clearinghouse enrollment file as a starting file. No Social Security number is stored, so that column
 * is blank. Nothing is sent anywhere by the plugin.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class outside {
    /** @var string Saved exports table. */
    const EXPORTS = 'local_studentrecords_export';

    /** @var string[] Who an export is for. */
    const PURPOSES = ['state', 'accreditor', 'other'];

    /** @var string[] Which students an export lists. */
    const POPULATIONS = ['all', 'active', 'term', 'admitted', 'graduated', 'withdrawn'];

    /** @var string[] Fields a school can pick, in the order offered. */
    const FIELDS = ['studentid', 'legalfirstname', 'legalmiddlename', 'legallastname', 'preferredname', 'dob', 'email', 'phone',
        'usaddress', 'homeaddress', 'homecountry', 'programcode', 'programname', 'cipcode', 'credential', 'credentialtype',
        'level', 'isdegree', 'unittype', 'delivery', 'admitterm', 'catalogyear', 'status', 'loadtype', 'isf1', 'sex',
        'hispanic', 'races', 'ipeds', 'termhours', 'attempted', 'earned', 'gpa', 'awardname', 'awarddate', 'withdrawdate',
        'lastattended'];

    /** @var string[] Fields that show a student's sex, ethnicity or race: they need the view demographics capability. */
    const DEMOGRAPHICS = ['sex', 'hispanic', 'races', 'ipeds'];

    /** @var array Starter exports: name string => [purpose, population, fields]. */
    const STARTERS = [
        'starter_stateenroll' => ['state', 'term', ['studentid', 'legallastname', 'legalfirstname', 'dob', 'programcode',
            'cipcode', 'credentialtype', 'unittype', 'termhours', 'loadtype', 'status', 'sex', 'ipeds', 'isf1']],
        'starter_statecompleters' => ['state', 'graduated', ['studentid', 'legallastname', 'legalfirstname', 'programcode',
            'cipcode', 'credential', 'awardname', 'awarddate', 'admitterm', 'sex', 'ipeds']],
        'starter_accreditor' => ['accreditor', 'all', ['studentid', 'programcode', 'level', 'delivery', 'admitterm', 'status',
            'attempted', 'earned', 'gpa', 'awarddate', 'withdrawdate', 'lastattended', 'ipeds', 'sex']],
    ];

    /** @var string[] Clearinghouse enrollment statuses. */
    const NSC = ['F', 'H', 'L', 'W', 'G', 'A'];

    // Rules that need no database.

    /**
     * Clearinghouse enrollment status of a student in a term: G graduated (award by the term end), A on leave, W withdrawn
     * (status withdrawn, or every course of the term dropped or withdrawn), F full-time (on the record or at least the
     * full-time hours), H half-time, L less than half-time.
     *
     * @param array $s status, loadtype, awarded (bool: award by the term end), hours (active hours in the term),
     *     allended (bool: every registration of the term dropped or withdrawn)
     * @param float $fulltime
     * @param float $halftime
     * @return string one of NSC
     */
    public static function nsc_status(array $s, float $fulltime, float $halftime): string {
        if (!empty($s['awarded']) || $s['status'] === 'graduated') {
            return 'G';
        }
        if ($s['status'] === 'onleave') {
            return 'A';
        }
        if ($s['status'] === 'withdrawn' || !empty($s['allended'])) {
            return 'W';
        }
        if ($s['loadtype'] === 'fulltime' || (float)$s['hours'] >= $fulltime) {
            return 'F';
        }
        return (float)$s['hours'] >= $halftime ? 'H' : 'L';
    }

    /**
     * Column names for an export: the school's own names (one per line, in field order), else the default labels.
     *
     * @param string[] $fields
     * @param string $own one name per line
     * @param array $labels field => default label
     * @return string[]
     */
    public static function headers(array $fields, string $own, array $labels): array {
        $names = array_map('trim', preg_split('/\R/', trim($own)) ?: []);
        $out = [];
        foreach (array_values($fields) as $i => $f) {
            $out[] = isset($names[$i]) && $names[$i] !== '' ? $names[$i] : ($labels[$f] ?? $f);
        }
        return $out;
    }

    // Saved exports.

    /**
     * Whether a saved export lists a student's sex, ethnicity or race.
     *
     * @param \stdClass $export
     * @return bool
     */
    public static function has_demographics(\stdClass $export): bool {
        return (bool)array_intersect(self::DEMOGRAPHICS, explode(',', (string)$export->fields));
    }

    /**
     * Default label of each field.
     *
     * @return array field => label
     */
    public static function labels(): array {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = get_string('xf_' . $f, 'local_studentrecords');
        }
        return $out;
    }

    /**
     * Saved exports.
     *
     * @return array
     */
    public static function exports(): array {
        global $DB;
        return $DB->get_records(self::EXPORTS, null, 'purpose, name, id');
    }

    /**
     * Add or change a saved export (change history kept).
     *
     * @param array $data name, purpose, population, termid, fromdate, todate, fields (array), headers, note, id
     * @return int id
     */
    public static function save_export(array $data): int {
        $fields = array_values(array_intersect((array)$data['fields'], self::FIELDS));
        $record = ['name' => trim($data['name']), 'purpose' => $data['purpose'], 'population' => $data['population'],
            'termid' => empty($data['termid']) ? null : (int)$data['termid'],
            'fromdate' => empty($data['fromdate']) ? null : (int)$data['fromdate'],
            'todate' => empty($data['todate']) ? null : (int)$data['todate'], 'fields' => implode(',', $fields),
            'headers' => trim((string)($data['headers'] ?? '')), 'note' => trim((string)($data['note'] ?? ''))];
        if (!empty($data['id'])) {
            $record['id'] = (int)$data['id'];
        }
        $id = records::save(self::EXPORTS, $record);
        audit::log(empty($data['id']) ? 'create' : 'update', 'export', $id, null, $record['name']);
        return $id;
    }

    /**
     * Add the starter exports that are not there yet (by name).
     *
     * @return int added
     */
    public static function add_starters(): int {
        global $DB;
        $n = 0;
        foreach (self::STARTERS as $string => [$purpose, $population, $fields]) {
            $name = get_string($string, 'local_studentrecords');
            if ($DB->record_exists(self::EXPORTS, ['name' => $name])) {
                continue;
            }
            self::save_export(['name' => $name, 'purpose' => $purpose, 'population' => $population, 'fields' => $fields,
                'note' => get_string($string . '_note', 'local_studentrecords')]);
            $n++;
        }
        return $n;
    }

    /**
     * Students of a population.
     *
     * @param string $population
     * @param int $termid for term and admitted
     * @param int $from for graduated and withdrawn (0 = no lower limit)
     * @param int $to for graduated and withdrawn (0 = no upper limit)
     * @return array student records
     */
    public static function population(string $population, int $termid = 0, int $from = 0, int $to = 0): array {
        global $DB;
        $range = function (string $field) use ($from, $to): array {
            $where = ["s.$field IS NOT NULL"];
            $params = [];
            if ($from) {
                $where[] = "s.$field >= ?";
                $params[] = $from;
            }
            if ($to) {
                $where[] = "s.$field < ?";
                $params[] = $to + DAYSECS;
            }
            return [implode(' AND ', $where), $params];
        };
        switch ($population) {
            case 'active':
                [$where, $params] = ["s.status IN ('enrolled', 'active')", []];
                break;
            case 'term':
                [$where, $params] = ["EXISTS (SELECT 1 FROM {" . registration::REG . "} r JOIN {" . registration::OFFER . "} o
                    ON o.id = r.offerid WHERE r.studentid = s.id AND o.termid = ?
                    AND r.status IN ('registered', 'completed', 'withdrawn'))", [$termid]];
                break;
            case 'admitted':
                [$where, $params] = ['s.admittermid = ?', [$termid]];
                break;
            case 'graduated':
                [$where, $params] = $range('awarddate');
                break;
            case 'withdrawn':
                [$where, $params] = $range('withdrawdate');
                break;
            default:
                [$where, $params] = ['1 = 1', []];
        }
        return $DB->get_records_sql("SELECT s.* FROM {local_studentrecords_student} s WHERE $where
                                   ORDER BY s.legallastname, s.legalfirstname, s.id", $params);
    }

    /**
     * Active hours (registered, completed or withdrawn) of a student in a term, and whether every course ended.
     *
     * @param int $studentid
     * @param int $termid
     * @return array hours, allended, any
     */
    public static function term_load(int $studentid, int $termid): array {
        global $DB;
        $regs = $DB->get_records_sql("SELECT r.id, r.status, c.hours FROM {" . registration::REG . "} r
                                        JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                        JOIN {local_studentrecords_course} c ON c.id = o.courseid
                                       WHERE r.studentid = ? AND o.termid = ?", [$studentid, $termid]);
        $hours = 0.0;
        $ended = true;
        foreach ($regs as $r) {
            if (in_array($r->status, ['registered', 'completed'], true)) {
                $hours += (float)$r->hours;
                $ended = false;
            }
        }
        return ['hours' => $hours, 'allended' => $regs && $ended, 'any' => (bool)$regs];
    }

    /**
     * The values of the chosen fields for one student.
     *
     * @param \stdClass $s student
     * @param string[] $fields
     * @param int $termid for term hours
     * @return array
     */
    public static function row(\stdClass $s, array $fields, int $termid = 0): array {
        global $DB;
        $p = 'local_studentrecords';
        $prog = $s->programid ? $DB->get_record('local_studentrecords_program', ['id' => $s->programid]) : null;
        $demog = $DB->get_record('local_studentrecords_demog', ['studentid' => $s->id]);
        $date = fn($t) => $t ? catalog::time_to_date((int)$t) : '';
        $history = null;
        $out = [];
        foreach ($fields as $f) {
            switch ($f) {
                case 'dob':
                case 'awarddate':
                case 'withdrawdate':
                case 'lastattended':
                    $out[] = $date($s->$f);
                    break;
                case 'programcode':
                    $out[] = $prog->code ?? '';
                    break;
                case 'programname':
                    $out[] = $prog ? reports::plain($prog->name) : '';
                    break;
                case 'cipcode':
                case 'credential':
                case 'credentialtype':
                case 'unittype':
                    $out[] = (string)($prog->$f ?? '');
                    break;
                case 'level':
                    $out[] = $prog && $prog->levelid ?
                        (string)$DB->get_field('local_studentrecords_level', 'name', ['id' => $prog->levelid]) : '';
                    break;
                case 'isdegree':
                    $out[] = $prog ? ($prog->isdegree ? 'Y' : 'N') : '';
                    break;
                case 'delivery':
                    $out[] = (string)($prog->deliverymode ?? '');
                    break;
                case 'admitterm':
                    $out[] = $s->admittermid ? (string)$DB->get_field(
                        'local_studentrecords_term',
                        'name',
                        ['id' => $s->admittermid]
                    ) : '';
                    break;
                case 'isf1':
                    $out[] = $s->isf1 ? 'Y' : 'N';
                    break;
                case 'sex':
                case 'hispanic':
                case 'races':
                    $out[] = (string)($demog->$f ?? '');
                    break;
                case 'ipeds':
                    $out[] = admissions::ipeds_category($demog->hispanic ?? null, $demog->races ?? null, (bool)$s->isf1);
                    break;
                case 'termhours':
                    $out[] = $termid ? format_float(self::term_load((int)$s->id, $termid)['hours'], -1) : '';
                    break;
                case 'attempted':
                case 'earned':
                case 'gpa':
                    $history = $history ?? registration::history($s)['cumulative'];
                    $out[] = $history[$f] === null ? '' : ($f === 'gpa' ? format_float($history[$f], 2) :
                        format_float($history[$f], -1));
                    break;
                case 'status':
                    $out[] = get_string('status_' . $s->status, $p);
                    break;
                case 'loadtype':
                    $out[] = $s->loadtype ? get_string('load_' . $s->loadtype, $p) : '';
                    break;
                default:
                    $out[] = (string)($s->$f ?? '');
            }
        }
        return $out;
    }

    /**
     * Rows of a saved export.
     *
     * @param \stdClass $export
     * @return array ['head' => names, 'rows' => rows]
     */
    public static function run(\stdClass $export): array {
        $fields = array_values(array_filter(explode(',', (string)$export->fields)));
        $rows = [];
        foreach (self::population($export->population, (int)$export->termid, (int)$export->fromdate, (int)$export->todate) as $s) {
            $rows[] = self::row($s, $fields, (int)$export->termid);
        }
        return ['head' => self::headers($fields, (string)$export->headers, self::labels()), 'rows' => $rows];
    }

    // Counts.

    /**
     * Unduplicated headcount of students registered in terms starting between two dates, by level, IPEDS race/ethnicity
     * category and sex.
     *
     * @param int $from
     * @param int $to
     * @return array level => category => sex => count
     */
    public static function headcount(int $from, int $to): array {
        global $DB;
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT r.studentid FROM {" . registration::REG . "} r
                                        JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                        JOIN {local_studentrecords_term} t ON t.id = o.termid
                                       WHERE t.startdate >= ? AND t.startdate < ?
                                         AND r.status IN ('registered', 'completed', 'withdrawn')", [$from, $to + DAYSECS]);
        return self::tally($ids);
    }

    /**
     * Completions: awards conferred between two dates, by program; and the same students by level, category and sex.
     *
     * @param int $from
     * @param int $to
     * @return array ['programs' => rows (code, name, cip, credential, count), 'grid' => see headcount]
     */
    public static function completions(int $from, int $to): array {
        global $DB;
        $students = $DB->get_records_select(
            'local_studentrecords_student',
            'awarddate >= ? AND awarddate < ?',
            [$from, $to + DAYSECS]
        );
        $programs = [];
        foreach ($students as $s) {
            $prog = $s->programid ? $DB->get_record('local_studentrecords_program', ['id' => $s->programid]) : null;
            $key = $prog->id ?? 0;
            $programs[$key] = $programs[$key] ?? ['code' => $prog->code ?? '', 'name' => $prog ? reports::plain($prog->name) : '',
                'cip' => (string)($prog->cipcode ?? ''), 'credential' => (string)($prog->credential ?? ''), 'count' => 0];
            $programs[$key]['count']++;
        }
        return ['programs' => array_values($programs), 'grid' => self::tally(array_keys($students))];
    }

    /**
     * Count students by level, IPEDS category and sex.
     *
     * @param array $ids student ids
     * @return array
     */
    protected static function tally(array $ids): array {
        global $DB;
        $levels = catalog::levels();
        $grid = [];
        foreach ($ids as $id) {
            $s = $DB->get_record('local_studentrecords_student', ['id' => $id]);
            $levelid = $s->programid ? (int)$DB->get_field('local_studentrecords_program', 'levelid', ['id' => $s->programid]) : 0;
            $level = $levelid ? (string)($levels[$levelid] ?? '') : '';
            $d = $DB->get_record('local_studentrecords_demog', ['studentid' => $id]);
            $cat = admissions::ipeds_category($d->hispanic ?? null, $d->races ?? null, (bool)$s->isf1);
            $sex = in_array($d->sex ?? '', ['female', 'male', 'another'], true) ? $d->sex : 'notreported';
            $grid[$level][$cat][$sex] = ($grid[$level][$cat][$sex] ?? 0) + 1;
        }
        return $grid;
    }

    // Clearinghouse.

    /**
     * Clearinghouse enrollment rows of a term: every student with a registration in the term.
     *
     * @param int $termid
     * @return array rows (see nsc_head)
     */
    public static function nsc_rows(int $termid): array {
        global $DB;
        $term = $DB->get_record('local_studentrecords_term', ['id' => $termid], '*', MUST_EXIST);
        $fulltime = (float)(catalog::setting('fulltimehours') !== '' ? catalog::setting('fulltimehours') : 12);
        $halftime = (float)(catalog::setting('halftime') !== '' ? catalog::setting('halftime') : 6);
        $rows = [];
        $students = $DB->get_records_sql("SELECT s.* FROM {local_studentrecords_student} s
                                           WHERE EXISTS (SELECT 1 FROM {" . registration::REG . "} r
                                                           JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                                          WHERE r.studentid = s.id AND o.termid = ?)
                                        ORDER BY s.legallastname, s.legalfirstname", [$termid]);
        foreach ($students as $s) {
            $load = self::term_load((int)$s->id, $termid);
            $status = self::nsc_status(
                ['status' => $s->status, 'loadtype' => $s->loadtype, 'hours' => $load['hours'],
                'allended' => $load['allended'], 'awarded' => $s->awarddate && $s->awarddate <= $term->enddate],
                $fulltime,
                $halftime
            );
            $statusdate = $status === 'G' ? $s->awarddate : ($status === 'W' ? ($s->withdrawdate ?: $s->lastattended) : null);
            $prog = $s->programid ? $DB->get_record('local_studentrecords_program', ['id' => $s->programid]) : null;
            $rows[] = ['', $s->legalfirstname, (string)$s->legalmiddlename, $s->legallastname, $status,
                catalog::time_to_date((int)($statusdate ?: $term->startdate)), trim((string)($s->usaddress ?: $s->homeaddress)),
                catalog::time_to_date($s->dob ? (int)$s->dob : null), catalog::time_to_date((int)$term->startdate),
                catalog::time_to_date((int)$term->enddate), $s->studentid, (string)$s->email, (string)($prog->cipcode ?? ''),
                (string)($prog->credentialtype ?? ''), format_float($load['hours'], -1)];
        }
        return $rows;
    }

    /**
     * Clearinghouse rows that need fixing before the file is sent: missing first or last name, date of birth, address,
     * student ID, CIP code or credential level (the SSN column is always empty: not stored).
     *
     * @param array $rows from nsc_rows()
     * @return array row index => list of column keys to fix
     */
    public static function nsc_problems(array $rows): array {
        $need = [1 => 'first', 3 => 'last', 6 => 'address', 7 => 'dob', 10 => 'studentid', 12 => 'cip', 13 => 'credential'];
        $out = [];
        foreach ($rows as $i => $r) {
            foreach ($need as $col => $key) {
                if (trim((string)($r[$col] ?? '')) === '') {
                    $out[$i][] = $key;
                }
            }
        }
        return $out;
    }

    /**
     * Clearinghouse column names.
     *
     * @return string[]
     */
    public static function nsc_head(): array {
        $p = 'local_studentrecords';
        return array_map(fn($k) => get_string('nsc_' . $k, $p), ['ssn', 'first', 'middle', 'last', 'status', 'statusdate',
            'address', 'dob', 'termstart', 'termend', 'studentid', 'email', 'cip', 'credential', 'hours']);
    }
}
