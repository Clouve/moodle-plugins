<?php

namespace local_studentrecords\local;

/**
 * Degree audit: a student's courses and transfer credit against the program outline (required groups, one track,
 * electives), the program's total hours and minimum GPA. A program with no outline is audited on its hours and GPA alone,
 * and with neither of those either it has nothing to audit. The comparison needs no database (evaluate()).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class degreeaudit {
    /**
     * Hours a group asks for, from its name ("General Education Courses (33 credits)"); null when not stated.
     *
     * @param string $groupname
     * @return float|null
     */
    public static function group_hours(string $groupname): ?float {
        $found = preg_match('/(\d+(?:\.\d+)?)\s*(?:credits?|credit hours|hours|clock hours)\b/i', $groupname, $m);
        return $found ? (float)$m[1] : null;
    }

    /**
     * Compare results with the outline.
     *
     * @param array $outline rows: courseid, code, title, hours, groupname, requirement (required, track, elective)
     * @param array $results courseid => [state (met, inprogress, notpassed), letter, term, source (course, transfer)]
     * @param float $extrahours hours that fit no course of the outline: transfer credit toward the program without an
     *     equivalent course and, with no outline, the credits earned at the school
     * @param array $program totalhours, mingpa
     * @param float|null $gpa cumulative GPA
     * @return array groups, track (chosen group name or ''), earned, inprogress, totalhours, gpa, gpaok, hoursok,
     *     complete, missing (list of language string keys with values)
     */
    public static function evaluate(array $outline, array $results, float $extrahours, array $program, ?float $gpa): array {
        $groups = [];
        foreach ($outline as $row) {
            $row = (array)$row;
            $g = $row['groupname'];
            if (!isset($groups[$g])) {
                $groups[$g] = ['name' => $g, 'requirement' => $row['requirement'], 'rows' => [], 'met' => 0.0, 'inprogress' => 0.0,
                    'listed' => 0.0, 'needed' => null, 'complete' => false];
            }
            $r = $results[$row['courseid']] ?? null;
            $state = $r['state'] ?? 'missing';
            $groups[$g]['rows'][] = $row + ['state' => $state, 'letter' => $r['letter'] ?? '', 'term' => $r['term'] ?? '',
                'source' => $r['source'] ?? ''];
            $groups[$g]['listed'] += (float)$row['hours'];
            if ($state === 'met') {
                $groups[$g]['met'] += (float)$row['hours'];
            } else if ($state === 'inprogress') {
                $groups[$g]['inprogress'] += (float)$row['hours'];
            }
        }
        foreach ($groups as $g => $group) {
            $stated = self::group_hours($g);
            if ($group['requirement'] === 'elective') {
                $groups[$g]['needed'] = $stated;
                $groups[$g]['complete'] = $stated === null ? true : $group['met'] + 0.001 >= $stated;
            } else {
                // Required and track groups: every course.
                $groups[$g]['needed'] = $group['listed'];
                $groups[$g]['complete'] = !array_filter($group['rows'], fn($r) => $r['state'] !== 'met');
            }
        }
        // Track: the one track group with the most hours met (a complete one first).
        $track = '';
        $best = [-1, -1.0];
        foreach ($groups as $g => $group) {
            if ($group['requirement'] !== 'track') {
                continue;
            }
            $rank = [$group['complete'] ? 1 : 0, $group['met'] + $group['inprogress']];
            if ($rank > $best) {
                $best = $rank;
                $track = $g;
            }
        }
        $earned = $extrahours;
        $inprogress = 0.0;
        $missing = [];
        foreach ($groups as $g => $group) {
            if ($group['requirement'] === 'track' && $g !== $track) {
                continue;
            }
            $earned += $group['met'];
            $inprogress += $group['inprogress'];
            if (!$group['complete']) {
                $missing[] = ['audit_groupnotmet', $g];
            }
        }
        $total = isset($program['totalhours']) && $program['totalhours'] !== null ? (float)$program['totalhours'] : null;
        $hoursok = $total === null || $earned + 0.001 >= $total;
        if (!$hoursok) {
            $missing[] = ['audit_hours', ['earned' => $earned, 'total' => $total]];
        }
        $mingpa = isset($program['mingpa']) && $program['mingpa'] !== null ? (float)$program['mingpa'] : null;
        $gpaok = $mingpa === null || ($gpa !== null && $gpa + 0.00001 >= $mingpa);
        if (!$gpaok) {
            $missing[] = ['audit_gpa', ['gpa' => $gpa, 'min' => $mingpa]];
        }
        return ['groups' => array_values($groups), 'track' => $track, 'earned' => $earned, 'inprogress' => $inprogress,
            'totalhours' => $total, 'gpa' => $gpa, 'mingpa' => $mingpa, 'gpaok' => $gpaok, 'hoursok' => $hoursok,
            'complete' => !$missing, 'missing' => $missing];
    }

    /**
     * Credits earned at the school against the program minimum (null = no minimum).
     *
     * @param float $atschool
     * @param float $inprogress
     * @param float|null $needed
     * @return array ok, okifpassed
     */
    public static function resident_check(float $atschool, float $inprogress, ?float $needed): array {
        if ($needed === null || $needed <= 0) {
            return ['ok' => true, 'okifpassed' => true];
        }
        return ['ok' => $atschool + 0.001 >= $needed, 'okifpassed' => $atschool + $inprogress + 0.001 >= $needed];
    }

    /**
     * Approved course substitutions of a student, with course codes.
     *
     * @param int $studentid
     * @return array
     */
    public static function substitutions(int $studentid): array {
        global $DB;
        return $DB->get_records_sql("SELECT s.*, r.code AS reqcode, c.code AS subcode
                                       FROM {local_studentrecords_subst} s
                                       JOIN {local_studentrecords_course} r ON r.id = s.reqcourseid
                                       JOIN {local_studentrecords_course} c ON c.id = s.subcourseid
                                      WHERE s.studentid = ?
                                   ORDER BY s.id", [$studentid]);
    }

    /**
     * Approve a course substitution (records staff): the substitute course counts for the required one in the audit.
     *
     * @param \stdClass $student
     * @param int $reqcourseid
     * @param int $subcourseid
     * @param string $note
     * @return int
     */
    public static function add_substitution(\stdClass $student, int $reqcourseid, int $subcourseid, string $note): int {
        global $DB, $USER;
        if (access::is_own($student)) {
            throw new \moodle_exception('err_ownrecord', 'local_studentrecords');
        }
        if (
            $reqcourseid === $subcourseid || trim($note) === '' ||
                !$DB->record_exists('local_studentrecords_course', ['id' => $reqcourseid]) ||
                !$DB->record_exists('local_studentrecords_course', ['id' => $subcourseid])
        ) {
            throw new \moodle_exception('err_substitution', 'local_studentrecords');
        }
        $id = (int)$DB->insert_record('local_studentrecords_subst', (object)['studentid' => $student->id,
            'reqcourseid' => $reqcourseid, 'subcourseid' => $subcourseid, 'note' => \core_text::substr(trim($note), 0, 255),
            'approvedby' => $USER->id, 'timecreated' => time()]);
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'substitution ' . $subcourseid . ' for ' .
            $reqcourseid);
        return $id;
    }

    /**
     * The degree audit of a student in their program (null when no program).
     *
     * @param \stdClass $student
     * @return array|null result of evaluate() plus auditable, atschool, residentneeded, completeifpassed, program code and name
     */
    public static function run(\stdClass $student): ?array {
        global $DB;
        if (!$student->programid) {
            return null;
        }
        $program = $DB->get_record('local_studentrecords_program', ['id' => $student->programid], '*', MUST_EXIST);
        $outline = array_values($DB->get_records_sql("SELECT pc.id, pc.courseid, c.code, c.title, c.hours, pc.groupname,
                                                             pc.requirement
                                                        FROM {local_studentrecords_progcrs} pc
                                                        JOIN {local_studentrecords_course} c ON c.id = pc.courseid
                                                       WHERE pc.programid = ?
                                                    ORDER BY pc.sortorder, pc.id", [$program->id]));
        $history = registration::history($student);
        $rank = ['met' => 3, 'inprogress' => 2, 'notpassed' => 1];
        $results = [];
        $put = function (int $courseid, array $r) use (&$results, $rank) {
            if (!isset($results[$courseid]) || $rank[$r['state']] > $rank[$results[$courseid]['state']]) {
                $results[$courseid] = $r;
            }
        };
        foreach ($history['terms'] as $t) {
            foreach ($t['rows'] as $r) {
                if (!empty($r->repeatexcluded)) {
                    continue;
                }
                if ($r->status === 'completed' && $r->grade !== null) {
                    $put((int)$r->courseid, ['state' => $r->passed === false ? 'notpassed' : 'met', 'letter' => $r->letter,
                        'term' => $t['term'], 'source' => 'course']);
                } else if ($r->status === 'registered') {
                    $put((int)$r->courseid, ['state' => 'inprogress', 'letter' => '', 'term' => $t['term'], 'source' => 'course',
                        'hours' => (float)$r->hours, 'unittype' => $r->unittype]);
                }
            }
        }
        $incoutline = array_flip(array_map(fn($o) => (int)$o->courseid, $outline));
        // Approved course substitutions: another course counts for a required course.
        foreach (self::substitutions((int)$student->id) as $sub) {
            if (isset($results[(int)$sub->subcourseid]) && isset($incoutline[(int)$sub->reqcourseid])) {
                $put((int)$sub->reqcourseid, ['source' => 'subst'] + $results[(int)$sub->subcourseid]);
            }
        }
        // With no outline there is no course to match: courses in the program's unit count, each of them once.
        $unitrows = array_filter(
            array_merge(...array_column($history['terms'], 'rows')),
            fn($r) => $r->unittype === $program->unittype
        );
        $counted = [];
        foreach ($unitrows as $r) {
            if (($results[(int)$r->courseid]['state'] ?? '') === 'met') {
                $counted[(int)$r->courseid] = (float)$r->hours;
            }
        }
        // Credits earned at the school (not transfer credit), for a program minimum and a program with no outline.
        $atschool = $outline ? round((float)$history['cumulative']['earned'] - (float)$history['transfertotals']['earned'], 2) :
            round(array_sum($counted), 2);
        $extra = 0.0;
        foreach ($history['transfers'] as $x) {
            if (!$x->countsprogram) {
                continue;
            }
            if ($x->equivcourseid && isset($incoutline[(int)$x->equivcourseid])) {
                $put((int)$x->equivcourseid, ['state' => 'met', 'letter' => $x->letter, 'term' => '', 'source' => 'transfer']);
            } else if ($outline || !$x->equivcourseid || !isset($counted[(int)$x->equivcourseid])) {
                $extra += (float)$x->credits;
                $counted[(int)$x->equivcourseid] = (float)$x->credits;
            }
        }
        $pendinghours = 0.0;
        if (!$outline) {
            $extra = round($extra + $atschool, 2);
            $pending = array_filter(
                $results,
                fn($r, $id) => $r['state'] === 'inprogress' && $r['unittype'] === $program->unittype && !isset($counted[$id]),
                ARRAY_FILTER_USE_BOTH
            );
            $pendinghours = (float)array_sum(array_column($pending, 'hours'));
        }
        $result = self::evaluate($outline, $results, $extra, (array)$program, $history['cumulative']['gpa']);
        $result['inprogress'] += $pendinghours;
        // If the classes in progress are passed: for a conditional graduation approval.
        $passed = array_map(fn($r) => $r['state'] === 'inprogress' ? ['state' => 'met'] + $r : $r, $results);
        $ifpassed = self::evaluate($outline, $passed, $extra + $pendinghours, (array)$program, $history['cumulative']['gpa']);
        $resident = self::resident_check($atschool, $result['inprogress'], $program->residenthours !== null ?
            (float)$program->residenthours : null);
        if (!$resident['ok']) {
            $result['missing'][] = ['audit_resident', ['have' => $atschool, 'need' => (float)$program->residenthours]];
            $result['complete'] = false;
        }
        // No course list, no total hours and no minimum GPA: nothing says what the student has to do.
        $auditable = $outline || (float)$program->totalhours > 0 || $program->mingpa !== null;
        if (!$auditable) {
            $result['missing'][] = ['audit_nothingtocheck', ''];
            $result['complete'] = false;
        }
        $result['auditable'] = (bool)$auditable;
        $result['atschool'] = $atschool;
        $result['residentneeded'] = $program->residenthours !== null ? (float)$program->residenthours : null;
        $result['completeifpassed'] = $auditable && $ifpassed['complete'] && $resident['okifpassed'];
        return $result + ['programcode' => $program->code, 'programname' => format_string($program->name),
            'credential' => (string)$program->credential, 'unittype' => $program->unittype];
    }
}
