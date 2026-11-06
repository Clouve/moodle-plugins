<?php

namespace local_studentrecords\local;

/**
 * Money reports: who owes money (by age), payments by day, refunds, late plan payments, and the yearly 1098-T data as a
 * spreadsheet for the school's tax software or tax service. No taxpayer ID is stored, so that column stays blank;
 * nothing is sent to the IRS. The school's tax adviser checks the file.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class moneyreports {
    /** @var string[] Kinds of credit that are scholarships or grants (1098-T box 5). */
    const SCHOLARSHIPS = ['scholarship', 'grant', 'fedgrant'];

    /** @var string[] Kinds of credit that are money paid by someone (count as payments for box 1). */
    const PAIDCREDITS = ['scholarship', 'grant', 'sponsor', 'fedgrant', 'fedloan'];

    // Rules that need no database.

    /**
     * What is owed, by age: payments and credits pay the oldest charges first; what is left of each charge is aged
     * from its date.
     *
     * @param array $lines amount, txndate
     * @param int $now
     * @return array total, d30 (0-30 days), d60 (31-60), d90 (61-90), older (over 90)
     */
    public static function aging(array $lines, int $now): array {
        $charges = [];
        $credit = 0.0;
        foreach ($lines as $l) {
            $l = (array)$l;
            if ((float)$l['amount'] > 0) {
                $charges[] = ['amount' => (float)$l['amount'], 'date' => (int)$l['txndate']];
            } else {
                $credit += -(float)$l['amount'];
            }
        }
        usort($charges, fn($a, $b) => $a['date'] <=> $b['date']);
        $out = ['total' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'older' => 0.0];
        foreach ($charges as $c) {
            $use = min($credit, $c['amount']);
            $credit -= $use;
            $left = round($c['amount'] - $use, 2);
            if ($left <= 0) {
                continue;
            }
            $days = (int)floor(($now - $c['date']) / DAYSECS);
            $bucket = $days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'older'));
            $out[$bucket] = round($out[$bucket] + $left, 2);
            $out['total'] = round($out['total'] + $left, 2);
        }
        return $out;
    }

    /**
     * 1098-T amounts of one student for a calendar year. Box 1: qualified tuition and related expenses paid in the year
     * (payments from any source, scholarships and sponsors included, less money paid back), counting only what was
     * billed; a drop below last year's figure goes to box 4. Box 5: scholarships and grants of the year; box 6:
     * scholarships and grants of an earlier year reversed this year. Box 7: box 1 includes charges for a term starting
     * in January to March of the next year.
     *
     * @param array $lines txntype, amount, txndate, qualified, method, origdate (date of the line a reversal reverses),
     *     termstart (start of the line's term, or null)
     * @param int $start first second of the year
     * @param int $end first second of the next year
     * @param int $nextq1end first second of April of the next year
     * @return array box1, box4, box5, box6, box7 (bool), billed, paid, paidback
     */
    public static function tax_amounts(array $lines, int $start, int $end, int $nextq1end): array {
        $covered = function (int $until) use ($lines): float {
            $qtre = 0.0;
            $paid = 0.0;
            foreach ($lines as $l) {
                $l = (array)$l;
                if ((int)$l['txndate'] >= $until) {
                    continue;
                }
                if (!empty($l['qualified']) && in_array($l['txntype'], ['charge', 'refund'], true)) {
                    $qtre += (float)$l['amount'];
                }
                if (
                    in_array($l['txntype'], ['payment', 'payout'], true) ||
                        ($l['txntype'] === 'credit' && in_array($l['method'], self::PAIDCREDITS, true))
                ) {
                    $paid -= (float)$l['amount'];
                }
            }
            return max(0.0, min(round($qtre, 2), round($paid, 2)));
        };
        $delta = round($covered($end) - $covered($start), 2);
        $out = ['box1' => max(0.0, $delta), 'box4' => max(0.0, -$delta), 'box5' => 0.0, 'box6' => 0.0, 'box7' => false,
            'billed' => 0.0, 'paid' => 0.0, 'paidback' => 0.0];
        foreach ($lines as $l) {
            $l = (array)$l;
            $date = (int)$l['txndate'];
            if ($date < $start || $date >= $end) {
                continue;
            }
            if ($l['txntype'] === 'credit' && in_array($l['method'], self::SCHOLARSHIPS, true)) {
                if (!empty($l['origdate']) && (int)$l['origdate'] < $start) {
                    $out['box6'] = round($out['box6'] + (float)$l['amount'], 2);
                } else {
                    $out['box5'] = round($out['box5'] - (float)$l['amount'], 2);
                }
            }
            if (!empty($l['qualified']) && in_array($l['txntype'], ['charge', 'refund'], true)) {
                $out['billed'] = round($out['billed'] + (float)$l['amount'], 2);
                if (!empty($l['termstart']) && (int)$l['termstart'] >= $end && (int)$l['termstart'] < $nextq1end) {
                    $out['box7'] = true;
                }
            }
            if ($l['txntype'] === 'payment') {
                $out['paid'] = round($out['paid'] - (float)$l['amount'], 2);
            }
            if ($l['txntype'] === 'payout') {
                $out['paidback'] = round($out['paidback'] + (float)$l['amount'], 2);
            }
        }
        $out['box7'] = $out['box7'] && $out['box1'] > 0;
        return $out;
    }

    // Reports.

    /**
     * First second of a day (Y-m-d) in the school's time zone.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @return int
     */
    protected static function day_start(int $year, int $month, int $day): int {
        return make_timestamp($year, $month, $day, 0, 0, 0, catalog::timezone());
    }

    /**
     * Students who owe money, with the amount by age.
     *
     * @return array rows: student, studentid, total, d30, d60, d90, older
     */
    public static function owed(): array {
        global $DB;
        $out = [];
        $now = time();
        foreach (billing::accounts('', 'owing') as $s) {
            $a = self::aging($DB->get_records(billing::TXN, ['studentid' => $s->id], '', 'id, amount, txndate'), $now);
            $out[] = ['studentid' => (int)$s->id, 'name' => students::display_name($s), 'number' => $s->studentid] + $a;
        }
        return $out;
    }

    /**
     * Payments by day and way of paying, between two days (school time zone, both included).
     *
     * @param string $fromymd
     * @param string $toymd
     * @return array rows: day, method, count, total
     */
    public static function payments(string $fromymd, string $toymd): array {
        global $DB;
        [$fy, $fm, $fd] = array_map('intval', explode('-', $fromymd));
        [$ty, $tm, $td] = array_map('intval', explode('-', $toymd));
        $lines = $DB->get_records_select(
            billing::TXN,
            "txntype = 'payment' AND txndate >= ? AND txndate < ?",
            [self::day_start($fy, $fm, $fd), self::day_start($ty, $tm, $td + 1)],
            'txndate, id',
            'id, amount, txndate, method'
        );
        $rows = [];
        foreach ($lines as $l) {
            $key = catalog::time_to_date((int)$l->txndate) . '|' . $l->method;
            $rows[$key] = $rows[$key] ?? ['day' => catalog::time_to_date((int)$l->txndate), 'method' => (string)$l->method,
                'count' => 0, 'total' => 0.0];
            $rows[$key]['count']++;
            $rows[$key]['total'] = round($rows[$key]['total'] - (float)$l->amount, 2);
        }
        return array_values($rows);
    }

    /**
     * Refunds figured between two days (school time zone, both included).
     *
     * @param string $fromymd
     * @param string $toymd
     * @return array refunds (see refunds::listing)
     */
    public static function refunds(string $fromymd, string $toymd): array {
        [$fy, $fm, $fd] = array_map('intval', explode('-', $fromymd));
        [$ty, $tm, $td] = array_map('intval', explode('-', $toymd));
        $from = self::day_start($fy, $fm, $fd);
        $to = self::day_start($ty, $tm, $td + 1);
        return array_values(array_filter(refunds::listing('all'), fn($r) => (int)$r->eventdate >= $from &&
            (int)$r->eventdate < $to));
    }

    /**
     * Late plan payments.
     *
     * @return array rows with student, plan, payment number, due date, amount left, late fee line, hold
     */
    public static function late(): array {
        global $DB;
        return array_values($DB->get_records_sql("SELECT i.*, p.name AS planname, p.studentid AS sid, s.studentid AS studentno,
                                                         s.legalfirstname, s.legalmiddlename, s.legallastname, s.preferredname
                                                    FROM {" . plans::INSTS . "} i
                                                    JOIN {" . plans::STUPLANS . "} p ON p.id = i.stuplanid
                                                    JOIN {local_studentrecords_student} s ON s.id = p.studentid
                                                   WHERE i.status = 'late' AND p.status = 'active'
                                                ORDER BY i.duedate, s.legallastname"));
    }

    /**
     * 1098-T rows of a calendar year: students with an amount in box 1, 4, 5 or 6.
     *
     * @param int $year
     * @return array rows
     */
    public static function tax1098(int $year): array {
        global $DB;
        $start = self::day_start($year, 1, 1);
        $end = self::day_start($year + 1, 1, 1);
        $nextq1end = self::day_start($year + 1, 4, 1);
        $halftime = (float)(catalog::setting('halftime') !== '' ? catalog::setting('halftime') : 6);
        $gradlevels = array_filter(array_map('intval', explode(',', catalog::setting('gradlevels'))));
        $sql = "SELECT t.id, t.studentid, t.txntype, t.amount, t.txndate, t.qualified, t.method, o.txndate AS origdate,
                       tm.startdate AS termstart
                  FROM {" . billing::TXN . "} t
             LEFT JOIN {" . billing::TXN . "} o ON o.id = t.reverses
             LEFT JOIN {local_studentrecords_term} tm ON tm.id = t.termid
                 WHERE t.txndate < ?
              ORDER BY t.studentid, t.txndate, t.id";
        $bystudent = [];
        foreach ($DB->get_records_sql($sql, [$end]) as $l) {
            $bystudent[$l->studentid][] = $l;
        }
        $rows = [];
        foreach ($bystudent as $studentid => $lines) {
            $a = self::tax_amounts($lines, $start, $end, $nextq1end);
            if ($a['box1'] <= 0 && $a['box4'] <= 0 && $a['box5'] <= 0 && $a['box6'] <= 0) {
                continue;
            }
            $s = students::get((int)$studentid);
            $levelid = $s->programid ? (int)$DB->get_field('local_studentrecords_program', 'levelid', ['id' => $s->programid]) : 0;
            $rows[] = [
                'studentid' => (int)$s->id, 'number' => (string)$s->studentid, 'first' => (string)$s->legalfirstname,
                'middle' => (string)$s->legalmiddlename, 'last' => (string)$s->legallastname,
                'address' => trim((string)($s->usaddress ?: $s->homeaddress)), 'email' => (string)$s->email,
                'box8' => self::half_time($s, $start, $end, $halftime), 'box9' => $levelid && in_array($levelid, $gradlevels, true),
            ] + $a;
        }
        return $rows;
    }

    /**
     * Whether a student was at least half-time in a term starting in the year: full-time on the record, or registered
     * for at least the half-time number of credit hours in one term.
     *
     * @param \stdClass $s student
     * @param int $start
     * @param int $end
     * @param float $halftime credit hours
     * @return bool
     */
    protected static function half_time(\stdClass $s, int $start, int $end, float $halftime): bool {
        global $DB;
        if ($s->loadtype === 'fulltime') {
            return true;
        }
        $sql = "SELECT o.termid, SUM(c.hours) AS hours
                  FROM {" . registration::REG . "} r
                  JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                  JOIN {local_studentrecords_course} c ON c.id = o.courseid
                  JOIN {local_studentrecords_term} t ON t.id = o.termid
                 WHERE r.studentid = ? AND r.status IN ('registered', 'completed', 'withdrawn') AND c.unittype = 'credit'
                       AND t.startdate >= ? AND t.startdate < ?
              GROUP BY o.termid";
        foreach ($DB->get_records_sql($sql, [$s->id, $start, $end]) as $t) {
            if ((float)$t->hours >= $halftime) {
                return true;
            }
        }
        return false;
    }
}
