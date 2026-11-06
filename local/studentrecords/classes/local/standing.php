<?php

namespace local_studentrecords\local;

/**
 * Academic standing and satisfactory academic progress (SAP): rules per level (minimum cumulative GPA, completion
 * rate, maximum time in years or in attempted credits), a run per term that proposes each student's standing, and
 * staff confirmation. Below standard the student moves one step: good, warning, probation, dismissal. Exceeding the
 * maximum time means dismissal. History is kept per term.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class standing {
    /** @var string Rules table. */
    const RULES = 'local_studentrecords_saprule';

    /** @var string Standing table. */
    const TABLE = 'local_studentrecords_stand';

    /** @var string[] Standings, best first (the steps below standard). */
    const LADDER = ['good', 'warning', 'probation', 'dismissal'];

    // Rules that need no database.

    /**
     * The standing for a term from the student's measures, the rule and the previous standing.
     *
     * @param array $m gpa (null = no GPA yet), completion (% or null), years, timeframe (% of program hours or null)
     * @param array|null $rule mingpa, mincompletion, maxyears, maxtimeframe (null or '' = not used)
     * @param string|null $previous the last confirmed standing
     * @return array [standing, list of reasons: gpa, completion, maxtime]
     */
    public static function evaluate(array $m, ?array $rule, ?string $previous): array {
        $set = fn($k) => $rule && isset($rule[$k]) && $rule[$k] !== '' && $rule[$k] !== null;
        $reasons = [];
        if ($set('mingpa') && $m['gpa'] !== null && (float)$m['gpa'] + 0.00001 < (float)$rule['mingpa']) {
            $reasons[] = 'gpa';
        }
        $completion = $m['completion'];
        if ($set('mincompletion') && $completion !== null && (float)$completion + 0.00001 < (float)$rule['mincompletion']) {
            $reasons[] = 'completion';
        }
        if (
            ($set('maxyears') && (float)$m['years'] > (float)$rule['maxyears']) ||
                ($set('maxtimeframe') && $m['timeframe'] !== null && (float)$m['timeframe'] > (float)$rule['maxtimeframe'])
        ) {
            $reasons[] = 'maxtime';
        }
        if (!$reasons) {
            return ['good', []];
        }
        if (in_array('maxtime', $reasons, true)) {
            return ['dismissal', $reasons];
        }
        $pos = array_search($previous ?: 'good', self::LADDER, true);
        $pos = $pos === false ? 0 : $pos;
        return [self::LADDER[min($pos + 1, count(self::LADDER) - 1)], $reasons];
    }

    /**
     * Measures: completion = earned / attempted, timeframe = attempted / program hours, years since the first term.
     * Both percentages come from whole hundredths, so every PHP version gives the same result.
     *
     * @param array $totals attempted, earned, gpa (from registration::totals)
     * @param float|null $programhours
     * @param int $firststart start of the first term (timestamp)
     * @param int $termend end of the evaluated term (timestamp)
     * @return array gpa, completion, years, timeframe
     */
    public static function measures(array $totals, ?float $programhours, int $firststart, int $termend): array {
        $attempted = (int)round((float)$totals['attempted'] * 100);
        $earned = (int)round((float)$totals['earned'] * 100);
        $program = (int)round((float)$programhours * 100);
        return [
            'gpa' => $totals['gpa'],
            'completion' => $attempted > 0 ? grading::divide_rounded($earned * 10000, $attempted) / 100.0 : null,
            'years' => $firststart && $termend > $firststart ? round(($termend - $firststart) / (365.25 * 86400), 2) : 0.0,
            'timeframe' => $program > 0 ? grading::divide_rounded($attempted * 10000, $program) / 100.0 : null,
        ];
    }

    /**
     * Suggested rule values from academic rules as the catalog words them (first GPA number, a % completion rate,
     * maximum time in years, maximum transfer %).
     *
     * @param array $policies rulekey => text
     * @return array mingpa, mincompletion, maxyears, maxtransfer (null when not stated)
     */
    public static function rule_from_policies(array $policies): array {
        $out = ['mingpa' => null, 'mincompletion' => null, 'maxyears' => null, 'maxtransfer' => null];
        foreach ($policies as $key => $text) {
            $text = (string)$text;
            $gpakey = preg_match('/good_standing.*gpa|minimum_gpa|gpa_standing/', $key);
            if ($gpakey && preg_match('/^\s*(\d(?:\.\d+)?)\b/', $text, $m)) {
                $out['mingpa'] = (float)$m[1];
            } else if (
                preg_match('/completion|pace/', $key) && preg_match('/(\d{1,3}(?:\.\d+)?)\s*%\s*of attempted/i', $text, $m)
            ) {
                $out['mincompletion'] = (float)$m[1];
            } else if (preg_match('/maximum_time|max_time/', $key) && preg_match('/(\d+(?:\.\d+)?)\s*years?/i', $text, $m)) {
                $out['maxyears'] = (float)$m[1];
            } else if (preg_match('/transfer_credit_max|transfer_max/', $key) && preg_match('/(\d{1,3})\s*%/', $text, $m)) {
                $out['maxtransfer'] = (float)$m[1];
            }
        }
        return $out;
    }

    // Rules.

    /**
     * The rule for a level, else the school-wide rule (no level), else null.
     *
     * @param int|null $levelid
     * @return array|null
     */
    public static function rule(?int $levelid): ?array {
        global $DB;
        $r = $levelid ? $DB->get_record(self::RULES, ['levelid' => $levelid, 'active' => 1]) : null;
        $r = $r ?: $DB->get_record_select(self::RULES, 'levelid IS NULL AND active = 1');
        return $r ? (array)$r : null;
    }

    /**
     * Suggested rules per level from the academic rules saved in the setup wizard.
     *
     * @return array levelid => suggestion
     */
    public static function suggestions(): array {
        global $DB;
        $bylevel = [];
        foreach ($DB->get_records('local_studentrecords_policy') as $p) {
            $bylevel[(int)$p->levelid][$p->rulekey] = $p->value;
        }
        $out = [];
        foreach ($bylevel as $levelid => $policies) {
            $out[$levelid] = self::rule_from_policies($policies);
        }
        return $out;
    }

    // The standing run.

    /**
     * Measures and proposed standing of one student at the end of a term.
     *
     * @param \stdClass $student
     * @param \stdClass $term
     * @return array measures + standing, reasons, previous
     */
    public static function compute(\stdClass $student, \stdClass $term): array {
        global $DB;
        $history = registration::history($student);
        $rows = [];
        $firststart = 0;
        foreach ($history['terms'] as $t) {
            $start = (int)$DB->get_field('local_studentrecords_term', 'startdate', ['id' => $t['termid']]);
            if ($start > (int)$term->startdate) {
                continue;
            }
            $firststart = $firststart ? min($firststart, $start) : $start;
            foreach ($t['rows'] as $r) {
                // Courses still in progress are not measured.
                if (in_array($r->status, ['completed', 'withdrawn'], true)) {
                    $rows[] = $r;
                }
            }
        }
        // A retake after this term doesn't change what counted at the end of it.
        registration::mark_repeats($rows);
        $rows = array_merge($rows, $history['transfers']);
        $program = $student->programid ? $DB->get_record('local_studentrecords_program', ['id' => $student->programid]) : null;
        $m = self::measures(
            registration::totals($rows),
            $program && $program->totalhours ? (float)$program->totalhours : null,
            $firststart ?: (int)$term->startdate,
            (int)$term->enddate
        );
        $previous = $DB->get_field_sql("SELECT s.standing FROM {" . self::TABLE . "} s
                                          JOIN {local_studentrecords_term} t ON t.id = s.termid
                                         WHERE s.studentid = ? AND s.state = 'confirmed' AND t.startdate < ?
                                      ORDER BY t.startdate DESC", [$student->id, $term->startdate], IGNORE_MULTIPLE) ?: null;
        [$standing, $reasons] = self::evaluate($m, self::rule($program ? (int)$program->levelid : null), $previous);
        return $m + ['standing' => $standing, 'reasons' => $reasons, 'previous' => $previous];
    }

    /**
     * Run the standing for a term: every student with a registration in the term gets a proposed standing.
     * Confirmed standings are left as they are.
     *
     * @param int $termid
     * @return array [proposed, kept confirmed]
     */
    public static function run(int $termid): array {
        global $DB, $USER;
        $term = $DB->get_record('local_studentrecords_term', ['id' => $termid], '*', MUST_EXIST);
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT r.studentid FROM {" . registration::REG . "} r
                                        JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                       WHERE o.termid = ? AND r.status <> 'dropped'", [$termid]);
        $counts = [0, 0];
        foreach ($ids as $sid) {
            $existing = $DB->get_record(self::TABLE, ['studentid' => $sid, 'termid' => $termid]);
            if ($existing && $existing->state === 'confirmed') {
                $counts[1]++;
                continue;
            }
            $student = students::get((int)$sid);
            $c = self::compute($student, $term);
            $record = ['studentid' => $sid, 'termid' => $termid, 'standing' => $c['standing'], 'previous' => $c['previous'],
                'gpa' => $c['gpa'], 'completion' => $c['completion'], 'years' => $c['years'], 'timeframe' => $c['timeframe'],
                'reasons' => implode(',', $c['reasons']), 'state' => 'proposed', 'computedby' => $USER->id,
                'timecomputed' => time()];
            if ($existing) {
                $record['id'] = $existing->id;
            }
            records::save(self::TABLE, $record, 'form', (int)$student->userid);
            $counts[0]++;
        }
        audit::log('update', 'standing', $termid, null, 'run: ' . $counts[0] . ' proposed');
        return $counts;
    }

    /**
     * Confirm proposed standings (optionally with another standing chosen by staff and a note).
     *
     * @param array $ids standing ids
     * @param array $changes id => standing chosen by staff
     * @param string $note
     * @return int number confirmed
     */
    public static function confirm(array $ids, array $changes = [], string $note = ''): int {
        global $DB, $USER;
        $n = 0;
        foreach ($DB->get_records_list(self::TABLE, 'id', $ids) as $s) {
            if ($s->state !== 'proposed') {
                continue;
            }
            $standing = isset($changes[$s->id]) && in_array($changes[$s->id], self::LADDER, true) ? $changes[$s->id] : $s->standing;
            $userid = (int)$DB->get_field(students::TABLE, 'userid', ['id' => $s->studentid]);
            records::save(self::TABLE, ['id' => $s->id, 'standing' => $standing, 'state' => 'confirmed',
                'confirmedby' => $USER->id, 'timeconfirmed' => time(), 'note' => trim($note) ?: $s->note], 'form', $userid);
            audit::log('update', 'student', (int)$s->studentid, $userid, 'standing ' . $standing);
            $n++;
        }
        return $n;
    }

    /**
     * Standing history of a student, newest term first.
     *
     * @param int $studentid
     * @return array
     */
    public static function history(int $studentid): array {
        global $DB;
        return array_values($DB->get_records_sql("SELECT s.*, t.name AS termname FROM {" . self::TABLE . "} s
                                                    JOIN {local_studentrecords_term} t ON t.id = s.termid
                                                   WHERE s.studentid = ? ORDER BY t.startdate DESC", [$studentid]));
    }
}
