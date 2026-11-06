<?php

namespace local_studentrecords\local;

/**
 * Financial Aid helper math (federal aid module): need, a yearly Direct Loan limit warning, disbursement runs with
 * hold reasons, and a Return of aid (R2T4) helper worksheet. Numbers are entered by staff; nothing is sent to federal
 * systems. The helpers do not replace the official federal worksheets: staff check them.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class aidhelp {
    /** @var string Aid year details table. */
    const YEARS = 'local_studentrecords_aidyear';

    /**
     * Yearly undergraduate Direct Loan limits (subsidized and unsubsidized together, and the subsidized part) by dependency
     * and grade level (3 = third year and up). Helper values to check against the current federal handbook. A dependent
     * student whose parents are refused a PLUS Loan has the independent limits. A parent at the PLUS limit doesn't count.
     */
    const LIMITS = [
        'dependent' => [1 => [5500, 3500], 2 => [6500, 4500], 3 => [7500, 5500]],
        'independent' => [1 => [9500, 3500], 2 => [10500, 4500], 3 => [12500, 5500]],
    ];

    /** @var string[] Order in which a school returns unearned aid (loans first, then grants). */
    const RETURNORDER = ['dlunsub', 'dlsub', 'dlplus', 'pell', 'fseog', 'otherfed'];

    /** @var int First award year in which loans shrink for less than full time (34 CFR 685.203(m)). */
    const REDUCTIONYEAR = 2026;

    /** @var int In cents: a grant overpayment of this much or less per grant program is not paid back. */
    const GRANTMINIMUM = 5000;

    /** @var int Days the school has to return its share, from the date it determined the student withdrew. */
    const RETURNDAYS = 45;

    /** @var string[] What staff can fill in on the worksheet. */
    const WORKSHEETFIELDS = ['withdrew', 'determined', 'charges', 'couldgrants', 'couldloans', 'breakdays', 'breaksbefore',
        'hoursdone', 'hoursperiod'];

    // Rules that need no database.

    /**
     * Financial need: cost of attendance minus Student Aid Index (never below 0).
     *
     * @param float $coa
     * @param float $sai
     * @return float
     */
    public static function need(float $coa, float $sai): float {
        return round(max(0.0, $coa - max(0.0, $sai)), 2);
    }

    /**
     * Yearly Direct Loan limits for a student: the most in Direct Loans and the most of that subsidized.
     *
     * @param string $dependency dependent or independent
     * @param int $gradelevel 1, 2 or 3 (and up)
     * @param int $percent share of full time, see enrolled_percent()
     * @return int[]|null limit, sublimit; null for an unknown dependency
     */
    public static function limits(string $dependency, int $gradelevel, int $percent = 100): ?array {
        if (!isset(self::LIMITS[$dependency])) {
            return null;
        }
        [$limit, $sublimit] = self::LIMITS[$dependency][max(1, min(3, $gradelevel))];
        return [billing::divide($limit * $percent, 100), billing::divide($sublimit * $percent, 100)];
    }

    /**
     * Yearly Direct Loan check.
     *
     * @param array $loans fund => amount for the year (dlsub, dlunsub; others ignored)
     * @param string $dependency dependent or independent
     * @param int $gradelevel 1, 2 or 3 (and up)
     * @param int $percent share of full time, see enrolled_percent()
     * @return array|null null when within limits; else total, limit, sublimit, sub, lowerunsubto, and fund, the loan to lower
     */
    public static function loan_check(array $loans, string $dependency, int $gradelevel, int $percent = 100): ?array {
        $limits = self::limits($dependency, $gradelevel, $percent);
        if ($limits === null) {
            return null;
        }
        [$limit, $sublimit] = $limits;
        $sub = round((float)($loans['dlsub'] ?? 0), 2);
        $unsub = round((float)($loans['dlunsub'] ?? 0), 2);
        $total = round($sub + $unsub, 2);
        if ($total <= $limit + 0.001 && $sub <= $sublimit + 0.001) {
            return null;
        }
        return ['total' => $total, 'limit' => (float)$limit, 'sublimit' => (float)$sublimit, 'sub' => $sub,
            'lowerunsubto' => max(0.0, round($limit - min($sub, $sublimit), 2)),
            'fund' => $total > $limit + 0.001 ? 'dlunsub' : 'dlsub'];
    }

    /**
     * Return of aid (R2T4) helper worksheet, step by step as on the federal form: 34 CFR 668.22 and volume 5 of the Federal
     * Student Aid Handbook. Money is in whole cents and percents in tenths, so the arithmetic is exact on every PHP version.
     *
     * @param array $paid fund => federal aid paid out for the term
     * @param float $done calendar days completed (to the withdrawal date), or scheduled clock hours
     * @param float $total calendar days in the term, or clock hours in the period
     * @param float $charges institutional charges for the term
     * @param float $couldgrants grants that could have been disbursed
     * @param float $couldloans loans that could have been disbursed
     * @return array the steps: paid, couldhave, pct, earnedpct, earned, pwd, unearned, charges, schoolshare, returns
     *     (fund => amount), studentshare, loansleft, grantdue, grantprotected, grantowed and grantreturns (fund => amount)
     */
    public static function r2t4(
        array $paid,
        float $done,
        float $total,
        float $charges,
        float $couldgrants = 0.0,
        float $couldloans = 0.0
    ): array {
        $net = array_map(fn($amount) => billing::cents((float)$amount), $paid);
        $disbursed = array_sum($net);
        $grants = array_sum(array_diff_key($net, array_flip(aid::LOANS))) + billing::cents($couldgrants);
        $could = billing::cents($couldgrants) + billing::cents($couldloans);

        // Steps 2 and 3: what the student earned, from everything paid out or that could have been.
        $units = billing::cents(max(0.0, min($done, $total)));
        $period = billing::cents($total);
        $pct = $period > 0 ? aid::share_percent($units, $period) : 100.0;
        $earnedpct = $period <= 0 || aid::past_sixty($units, $period) ? 100.0 : $pct;
        $tenths = (int)round($earnedpct * 10);
        $earned = billing::divide(($disbursed + $could) * $tenths, 1000);

        // Step 4: earned more than was paid out means a post-withdrawal disbursement, and nothing goes back.
        $pwd = max(0, $earned - $disbursed);
        $unearned = max(0, $disbursed - $earned);

        // Steps 5 and 6: the school returns the smaller of the unearned aid and the unearned part of its charges.
        $schoolshare = min($unearned, billing::divide(billing::cents($charges) * (1000 - $tenths), 1000));
        $returns = self::shares($net, $schoolshare, self::RETURNORDER);

        // Steps 7 to 9: the rest is the student's. Loans stay on their terms, and half of the grants are protected.
        $studentshare = $unearned - $schoolshare;
        $loansleft = array_sum(array_intersect_key($net, array_flip(aid::LOANS))) -
            array_sum(array_intersect_key($returns, array_flip(aid::LOANS)));
        $grantdue = max(0, $studentshare - $loansleft);
        $grantprotected = billing::divide($grants * 50, 100);

        // Step 10: the grant money left in each program, in order. $50 or less in a program isn't paid back.
        $left = $net;
        foreach ($returns as $fund => $amount) {
            $left[$fund] -= $amount;
        }
        $grantorder = array_diff(self::RETURNORDER, aid::LOANS);
        $grantreturns = array_filter(
            self::shares($left, max(0, $grantdue - $grantprotected), $grantorder),
            fn($amount) => $amount > self::GRANTMINIMUM
        );

        $money = fn(int $cents) => $cents / 100.0;
        return ['paid' => $money($disbursed), 'couldhave' => $money($could), 'pct' => $pct, 'earnedpct' => $earnedpct,
            'earned' => $money($earned), 'pwd' => $money($pwd), 'unearned' => $money($unearned),
            'charges' => $money(billing::cents($charges)), 'schoolshare' => $money($schoolshare),
            'returns' => array_map($money, $returns), 'studentshare' => $money($studentshare),
            'loansleft' => $money($loansleft), 'grantdue' => $money($grantdue), 'grantprotected' => $money($grantprotected),
            'grantowed' => $money(array_sum($grantreturns)), 'grantreturns' => array_map($money, $grantreturns)];
    }

    /**
     * Split an amount over funds in order, each up to what it has.
     *
     * @param int[] $funds fund => cents it has
     * @param int $amount cents
     * @param string[] $order
     * @return int[] fund => cents, without the funds that get nothing
     */
    protected static function shares(array $funds, int $amount, array $order): array {
        $out = [];
        foreach ($order as $fund) {
            $take = min($amount, $funds[$fund] ?? 0);
            if ($take > 0) {
                $out[$fund] = $take;
                $amount -= $take;
            }
        }
        return $out;
    }

    /**
     * Whether a student's program counts clock hours.
     *
     * @param \stdClass $student
     * @return bool
     */
    protected static function clock_hours(\stdClass $student): bool {
        global $DB;
        return $student->programid &&
            $DB->get_field('local_studentrecords_program', 'unittype', ['id' => $student->programid]) === 'clock';
    }

    /**
     * Whether a student studies in a program level the school marks as graduate. The undergraduate limits don't apply there.
     *
     * @param \stdClass $student
     * @return bool
     */
    public static function is_graduate(\stdClass $student): bool {
        global $DB;
        $levels = array_filter(array_map('intval', explode(',', catalog::setting('gradlevels'))));
        if (!$levels || !$student->programid) {
            return false;
        }
        $level = (int)$DB->get_field('local_studentrecords_program', 'levelid', ['id' => $student->programid]);
        return in_array($level, $levels, true);
    }

    /**
     * A student's enrollment as a whole percent of full time: their credits over full-time credits, across the terms of their
     * loans. From the 2026-27 award year a Direct Loan shrinks with it (34 CFR 685.203(m)). The rule is for term programs, so a
     * clock-hour program stays at 100. A term the student hasn't registered for yet, or has left, says nothing about their load
     * and isn't counted.
     *
     * @param \stdClass $student
     * @param string $awardyear such as 2026-27
     * @param int[] $termids the terms of the loans
     * @return int 100 for full time or more
     */
    public static function enrolled_percent(\stdClass $student, string $awardyear, array $termids): int {
        $fulltime = billing::cents((float)(catalog::setting('fulltimehours') !== '' ? catalog::setting('fulltimehours') : 12));
        if ((int)substr($awardyear, 0, 4) < self::REDUCTIONYEAR || $fulltime <= 0) {
            return 100;
        }
        if (self::clock_hours($student)) {
            return 100;
        }
        $enrolled = 0;
        $terms = 0;
        foreach ($termids as $termid) {
            $load = outside::term_load((int)$student->id, (int)$termid);
            if ($load['any'] && !$load['allended']) {
                $enrolled += billing::cents($load['hours']);
                $terms++;
            }
        }
        return $terms ? min(100, billing::divide($enrolled * 100, $fulltime * $terms)) : 100;
    }

    /**
     * Hold reasons for a disbursement (empty = ready).
     *
     * @param string $fund
     * @param array $state verification (none, selected, complete), counseling (bool), withdrawn (bool), hours (float, none means
     *     no class to pay aid for),
     *     halftime (float)
     * @return string[] reason keys
     */
    public static function hold_reasons(string $fund, array $state): array {
        if (!empty($state['withdrawn'])) {
            return ['stopped_withdrawn'];
        }
        $out = [];
        if (isset($state['hours']) && (float)$state['hours'] <= 0) {
            return ['held_noclasses'];
        }
        if (($state['verification'] ?? 'none') === 'selected') {
            $out[] = 'held_verification';
        }
        if (in_array($fund, aid::LOANS, true) && empty($state['counseling'])) {
            $out[] = 'held_counseling';
        }
        if (in_array($fund, aid::LOANS, true) && (float)($state['hours'] ?? 0) < (float)($state['halftime'] ?? 6)) {
            $out[] = 'held_halftime';
        }
        return $out;
    }

    // Database.

    /**
     * Aid year details of a student (empty defaults when none saved).
     *
     * @param int $studentid
     * @param string $awardyear
     * @return \stdClass
     */
    public static function year(int $studentid, string $awardyear): \stdClass {
        global $DB;
        $rec = $DB->get_record(self::YEARS, ['studentid' => $studentid, 'awardyear' => $awardyear]);
        return $rec ?: (object)['id' => 0, 'studentid' => $studentid, 'awardyear' => $awardyear, 'coa' => null, 'sai' => null,
            'dependency' => 'dependent', 'gradelevel' => 1, 'verification' => 'none', 'counseling' => 0, 'note' => ''];
    }

    /**
     * Save aid year details (Financial Aid staff; change history kept).
     *
     * @param \stdClass $student
     * @param array $data awardyear, coa, sai, dependency, gradelevel, verification, counseling, note
     * @return int
     */
    public static function save_year(\stdClass $student, array $data): int {
        require_capability('local/studentrecords:manageaid', \context_system::instance());
        $current = self::year((int)$student->id, $data['awardyear']);
        $record = ['studentid' => $student->id, 'awardyear' => $data['awardyear'],
            'coa' => $data['coa'] === null ? null : round((float)$data['coa'], 2),
            'sai' => $data['sai'] === null ? null : round((float)$data['sai'], 2),
            'dependency' => in_array($data['dependency'], ['dependent', 'independent'], true) ? $data['dependency'] : 'dependent',
            'gradelevel' => max(1, min(3, (int)$data['gradelevel'])),
            'verification' => in_array($data['verification'], ['none', 'selected', 'complete'], true) ? $data['verification'] :
                'none', 'counseling' => empty($data['counseling']) ? 0 : 1, 'note' => trim((string)($data['note'] ?? ''))];
        if ($current->id) {
            $record['id'] = $current->id;
        }
        $id = records::save(self::YEARS, $record, 'form', (int)$student->userid);
        audit::log('update', 'aid', (int)$student->id, (int)$student->userid, 'aid year ' . $data['awardyear']);
        return $id;
    }

    /**
     * Award year of a time (July to June), e.g. 2026-27.
     *
     * @param int $time
     * @return string
     */
    public static function award_year(int $time): string {
        $ymd = catalog::time_to_date($time);
        $y = (int)substr($ymd, 0, 4);
        $start = (int)substr($ymd, 5, 2) >= 7 ? $y : $y - 1;
        return $start . '-' . substr((string)($start + 1), 2);
    }

    /**
     * Disbursement run of a term: each accepted award of the term with money left to disburse, ready or held.
     *
     * @param int $termid
     * @return array rows: award, student, left, reasons (empty = ready), payroll (work-study)
     */
    public static function run(int $termid): array {
        global $DB;
        $halftime = (float)(catalog::setting('halftime') !== '' ? catalog::setting('halftime') : 6);
        $out = [];
        $awards = $DB->get_records(aid::AWARDS, ['termid' => $termid, 'status' => 'accepted'], 'studentid, fund');
        foreach ($awards as $a) {
            $disbursed = -(float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0) FROM {" . billing::TXN . "}
                WHERE aidid = ?", [$a->id]);
            $left = round((float)$a->amount - $disbursed, 2);
            if ($left <= 0) {
                continue;
            }
            $student = students::get((int)$a->studentid);
            $year = self::year((int)$student->id, $a->awardyear);
            $load = outside::term_load((int)$student->id, $termid);
            $reasons = aid::credit_kind($a->fund) === null ? [] : self::hold_reasons($a->fund, [
                'verification' => $year->verification, 'counseling' => (bool)$year->counseling,
                'withdrawn' => $student->status === 'withdrawn', 'hours' => (float)$load['hours'], 'halftime' => $halftime]);
            $out[] = ['award' => $a, 'student' => $student, 'left' => $left, 'reasons' => $reasons,
                'payroll' => aid::credit_kind($a->fund) === null];
        }
        return $out;
    }

    /**
     * Send the ready disbursements of a run to the student accounts.
     *
     * @param int $termid
     * @return array count, total
     */
    public static function send_ready(int $termid): array {
        $n = 0;
        $total = 0.0;
        foreach (self::run($termid) as $r) {
            if ($r['reasons'] || $r['payroll']) {
                continue;
            }
            aid::disburse((int)$r['award']->id, $r['left'], time());
            $n++;
            $total = round($total + $r['left'], 2);
        }
        audit::log('update', 'aid', null, null, 'disbursement run term ' . $termid . ": $n, " . billing::money($total));
        return ['count' => $n, 'total' => $total];
    }

    /**
     * The details staff typed on the worksheet, ready for worksheet(). A blank or unreadable detail is left out, so the
     * worksheet uses its default and shows it.
     *
     * @param array $raw text by name, see WORKSHEETFIELDS
     * @return array
     */
    public static function worksheet_details(array $raw): array {
        $out = [];
        foreach (['charges', 'couldgrants', 'couldloans', 'hoursdone', 'hoursperiod'] as $name) {
            $amount = billing::parse_amount((string)($raw[$name] ?? ''));
            if ($amount !== null) {
                $out[$name] = $amount;
            }
        }
        foreach (['breakdays', 'breaksbefore'] as $name) {
            $days = trim((string)($raw[$name] ?? ''));
            if (ctype_digit($days) && strlen($days) < 5) {
                $out[$name] = (int)$days;
            }
        }
        foreach (['withdrew', 'determined'] as $name) {
            $day = trim((string)($raw[$name] ?? ''));
            if (plans::valid_ymd($day)) {
                $out[$name] = $day;
            }
        }
        return $out;
    }

    /**
     * The institutional charges of a term as they stood when the student left: charges, changes and refunds dated before that
     * day. Anything on that day or later has no effect on the worksheet. A refund counts from the day of the event it is for,
     * not the day it was approved. The aid a return put back on the account is no charge.
     *
     * @param \stdClass $student
     * @param int $termid
     * @param int $left start of the day the student left
     * @return float[] charges, and later: charges dated from that day on, which are left out
     */
    protected static function charges_when_left(\stdClass $student, int $termid, int $left): array {
        global $DB;
        $params = [$student->id, $termid];
        $charges = (float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0) FROM {" . billing::TXN . "}
            WHERE studentid = ? AND termid = ? AND txntype = 'charge' AND refundid IS NULL AND txndate < ?", [...$params, $left]);
        $refunds = (float)$DB->get_field_sql("SELECT COALESCE(SUM(t.amount), 0) FROM {" . billing::TXN . "} t
            JOIN {" . refunds::REFUNDS . "} f ON f.id = t.refundid
            WHERE t.studentid = ? AND t.termid = ? AND t.txntype = 'refund' AND t.chargeid IS NOT NULL
              AND f.eventdate < ?", [...$params, $left]);
        $later = (float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0) FROM {" . billing::TXN . "}
            WHERE studentid = ? AND termid = ? AND txntype = 'charge' AND refundid IS NULL AND reverses IS NULL
              AND reversedby IS NULL AND txndate >= ?", [...$params, $left]);
        return ['charges' => round($charges + $refunds, 2), 'later' => round($later, 2)];
    }

    /**
     * Federal aid paid out for a term by the end of a day. Aid counts as disbursed only as of the date the school determined
     * the student withdrew.
     *
     * @param \stdClass $student
     * @param int $termid
     * @param int|null $until start of the day after, or null for all of it
     * @return float[] fund => amount
     */
    protected static function paid_out(\stdClass $student, int $termid, ?int $until): array {
        global $DB;
        $sql = "SELECT a.fund, COALESCE(SUM(-t.amount), 0) AS paid FROM {" . billing::TXN . "} t
                  JOIN {" . aid::AWARDS . "} a ON a.id = t.aidid
                 WHERE a.studentid = ? AND a.termid = ?" . ($until === null ? '' : ' AND t.txndate < ?') . " GROUP BY a.fund";
        $out = [];
        foreach ($DB->get_records_sql($sql, array_merge([$student->id, $termid], $until === null ? [] : [$until])) as $r) {
            if (!empty(aid::FUNDS[$r->fund]) && (float)$r->paid > 0) {
                $out[$r->fund] = round((float)$r->paid, 2);
            }
        }
        return $out;
    }

    /**
     * Aid accepted for a term and not paid out: what may be aid that could have been disbursed. Whether it counts is for
     * staff to decide, so this is only a hint for the worksheet.
     *
     * @param \stdClass $student
     * @param int $termid
     * @param array $paid fund => aid paid out
     * @return float[] grants, loans
     */
    protected static function not_paid_out(\stdClass $student, int $termid, array $paid): array {
        global $DB;
        $out = ['grants' => 0, 'loans' => 0];
        $sql = "SELECT fund, COALESCE(SUM(amount), 0) AS amount FROM {" . aid::AWARDS . "}
                 WHERE studentid = ? AND termid = ? AND status = 'accepted' GROUP BY fund";
        foreach ($DB->get_records_sql($sql, [$student->id, $termid]) as $r) {
            if (in_array($r->fund, self::RETURNORDER, true)) {
                $kind = in_array($r->fund, aid::LOANS, true) ? 'loans' : 'grants';
                $out[$kind] += max(0, billing::cents((float)$r->amount) - billing::cents((float)($paid[$r->fund] ?? 0)));
            }
        }
        return array_map(fn($cents) => $cents / 100.0, $out);
    }

    /**
     * Federal aid paid out with no term on its award, which no worksheet counts.
     *
     * @param \stdClass $student
     * @return float
     */
    protected static function paid_without_term(\stdClass $student): float {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(self::RETURNORDER);
        return round((float)$DB->get_field_sql("SELECT COALESCE(SUM(-t.amount), 0) FROM {" . billing::TXN . "} t
            JOIN {" . aid::AWARDS . "} a ON a.id = t.aidid
            WHERE a.studentid = ? AND (a.termid IS NULL OR a.termid = 0) AND a.fund $in", array_merge([$student->id], $params)), 2);
    }

    /**
     * Return of aid worksheet of a student who withdrew in a term.
     *
     * The student left on the day aid::left_term() finds, and staff can give another day in the term. A credit-hour program
     * counts calendar days, without scheduled breaks of five days or more. A clock-hour program counts scheduled hours, which
     * staff enter. Without a day or those hours the worksheet can't be sent. Aid counts as paid out when it was by the date
     * the school determined the student withdrew, and the return is due 45 days after that date.
     *
     * @param \stdClass $student
     * @param int $termid
     * @param array $details what staff filled in, see worksheet_details()
     * @return array|null r2t4() + the days used, the period counted, defaults and hints for the form, needsdate and needshours
     *     (what is missing), and ignored (details that make no sense here); null when no federal aid was paid
     */
    public static function worksheet(\stdClass $student, int $termid, array $details = []): ?array {
        global $DB;
        $term = $DB->get_record('local_studentrecords_term', ['id' => $termid], '*', MUST_EXIST);
        $allpaid = self::paid_out($student, $termid, null);
        if (!$allpaid) {
            return null;
        }
        $ignored = [];
        $start = catalog::time_to_date((int)$term->startdate);
        $end = catalog::time_to_date((int)$term->enddate);
        $left = aid::left_term($student, $term);
        if (isset($details['withdrew'])) {
            if ($details['withdrew'] >= $start && $details['withdrew'] <= $end) {
                $left = $details['withdrew'];
            } else {
                $ignored[] = 'withdrew';
            }
        }
        $determined = $left;
        if (isset($details['determined'])) {
            if ($details['determined'] >= $left) {
                $determined = $details['determined'];
            } else {
                $ignored[] = 'determined';
            }
        }
        $utc = new \DateTimeZone('UTC');
        $next = fn(string $day, int $days) => (new \DateTimeImmutable($day, $utc))->modify("+$days days")->format('Y-m-d');
        $daysbetween = fn(string $from, string $to) => (int)(new \DateTimeImmutable($from, $utc))->diff(
            new \DateTimeImmutable($to, $utc)
        )->days + 1;

        $paid = $determined === '' ? $allpaid : self::paid_out($student, $termid, catalog::day_start($next($determined, 1)));
        $daysterm = $daysbetween($start, $end);
        $daysdone = $left === '' ? 0 : $daysbetween($start, $left);
        $breakdays = (int)($details['breakdays'] ?? 0);
        $breaksbefore = (int)($details['breaksbefore'] ?? 0);
        if ($breaksbefore > $breakdays || $breaksbefore > $daysdone || $daysdone - $breaksbefore > $daysterm - $breakdays) {
            array_push($ignored, 'breakdays', 'breaksbefore');
            $breakdays = 0;
            $breaksbefore = 0;
        }
        $clock = self::clock_hours($student);
        $hoursdone = $details['hoursdone'] ?? null;
        $hoursperiod = (float)($details['hoursperiod'] ?? 0);
        $hours = $clock && $hoursperiod > 0 && $hoursdone !== null && $hoursdone <= $hoursperiod;
        if ($clock && $hoursdone !== null && $hoursperiod > 0 && !$hours) {
            $ignored[] = 'hoursdone';
        }
        $done = $hours ? (float)$hoursdone : (float)max(0, $daysdone - $breaksbefore);
        $period = $hours ? $hoursperiod : (float)($daysterm - $breakdays);
        $found = $left === '' ? ['charges' => 0.0, 'later' => 0.0] :
            self::charges_when_left($student, $termid, catalog::day_start($left));
        return self::r2t4(
            $paid,
            $done,
            $period,
            $details['charges'] ?? $found['charges'],
            (float)($details['couldgrants'] ?? 0),
            (float)($details['couldloans'] ?? 0)
        ) + ['funds' => $paid, 'withdrew' => $left === '' ? 0 : catalog::date_to_time($left), 'withdrewday' => $left,
            'daysterm' => $daysterm, 'done' => $done, 'period' => $period, 'clock' => $clock, 'needsdate' => $left === '',
            'needshours' => $clock && !$hours, 'ignored' => $ignored, 'chargesfound' => $found['charges'],
            'chargeslater' => $found['later'], 'notpaid' => self::not_paid_out($student, $termid, $paid),
            'paidlater' => max(0.0, round(array_sum($allpaid) - array_sum($paid), 2)),
            'noterm' => self::paid_without_term($student), 'breakdays' => $breakdays, 'breaksbefore' => $breaksbefore,
            'determined' => $determined === '' ? 0 : catalog::date_to_time($determined), 'determinedday' => $determined,
            'duedate' => $determined === '' ? 0 : catalog::date_to_time($next($determined, self::RETURNDAYS))];
    }

    /**
     * Approve the worksheet and send it to Finance as a refund item (reason "Return of aid"): Finance approves it, which
     * puts the returned aid back on the student account as charges, and sends the money to the Department of Education. One
     * item at a time, and only for the amount staff saw: if the figures moved since, nothing is sent.
     *
     * @param \stdClass $student
     * @param int $termid
     * @param array $details what staff filled in, see worksheet_details()
     * @param int|null $expected the school's share staff saw, in cents
     * @return int refund id
     */
    public static function send_return(\stdClass $student, int $termid, array $details = [], ?int $expected = null): int {
        require_capability('local/studentrecords:manageaid', \context_system::instance());
        return billing::one_at_a_time(fn() => self::send_return_locked($student, $termid, $details, $expected));
    }

    /**
     * Check the worksheet and make the refund item. The caller holds the lock on the accounts.
     *
     * @param \stdClass $student
     * @param int $termid
     * @param array $details
     * @param int|null $expected
     * @return int refund id
     */
    protected static function send_return_locked(\stdClass $student, int $termid, array $details, ?int $expected): int {
        global $DB, $USER;
        $w = self::worksheet($student, $termid, $details);
        if ($w && ($w['needsdate'] || $w['needshours'])) {
            throw new \moodle_exception($w['needsdate'] ? 'err_returndate' : 'err_returnhours', 'local_studentrecords');
        }
        if (!$w || $w['schoolshare'] <= 0) {
            throw new \moodle_exception('err_noreturn', 'local_studentrecords');
        }
        if ($expected !== null && $expected !== billing::cents($w['schoolshare'])) {
            throw new \moodle_exception('err_returnchanged', 'local_studentrecords');
        }
        if (
            $DB->record_exists_select(
                refunds::REFUNDS,
                "studentid = ? AND termid = ? AND reason = 'aidreturn' AND status <> 'rejected'",
                [$student->id, $termid]
            )
        ) {
            throw new \moodle_exception('err_returnexists', 'local_studentrecords');
        }
        $details = [];
        foreach ($w['returns'] as $fund => $amount) {
            $details[] = ['fund' => $fund, 'description' => get_string('fund_' . $fund, 'local_studentrecords'),
                'net' => (float)$w['funds'][$fund], 'percent' => billing::divide(
                    billing::cents($amount) * 1000,
                    max(1, billing::cents((float)$w['funds'][$fund]))
                ) / 10,
                'refund' => $amount, 'chargeid' => 0];
        }
        $id = (int)$DB->insert_record(refunds::REFUNDS, (object)['studentid' => $student->id, 'termid' => $termid,
            'regid' => null, 'reason' => 'aidreturn', 'eventdate' => $w['withdrew'], 'ruleid' => null,
            'rulename' => get_string('ret_rulename', 'local_studentrecords', format_float($w['pct'], 1)),
            'details' => json_encode($details), 'amount' => $w['schoolshare'], 'status' => 'proposed',
            'duedate' => $w['duedate'], 'userid' => $USER->id, 'timecreated' => time(), 'timemodified' => time()]);
        audit::log('create', 'refund', $id, (int)$student->userid, 'return of aid ' . billing::money($w['schoolshare']) .
            ': left ' . $w['withdrewday'] . ', determined ' . $w['determinedday'] . ', charges ' . billing::money($w['charges']) .
            ', could have been disbursed ' . billing::money($w['couldhave']) . ', ' . format_float($w['done'], -1) . ' of ' .
            format_float($w['period'], -1) . ($w['clock'] ? ' clock hours' : ' days'));
        messages::staff('staffrefund', $student, new \moodle_url('/local/studentrecords/refunds.php', ['id' => $id]));
        return $id;
    }
}
