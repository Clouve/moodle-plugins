<?php

namespace local_studentrecords\local;

/**
 * Optional federal aid reporting module (off unless the school turns it on in the setup wizard; only for schools approved
 * for federal Title IV aid). Reporting only: aid awards, disbursements to the student account, and aid reports (recipients,
 * disbursements, enrollment status, withdrawals with the percent of the term completed as helper data for the school's own
 * Return of Title IV funds calculation, and a 90/10 worksheet). Aid processing stays in the school's aid software. No Social
 * Security number is stored.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class aid {
    /** @var string Aid awards table. */
    const AWARDS = 'local_studentrecords_aid';

    /** @var array Funds: fund => federal Title IV (true) or not. */
    const FUNDS = ['pell' => true, 'fseog' => true, 'fws' => true, 'dlsub' => true, 'dlunsub' => true, 'dlplus' => true,
        'otherfed' => true, 'state' => false, 'institutional' => false, 'private' => false];

    /** @var string[] Federal loans (disbursed as federal loan credits); other federal funds are grants; work-study is paid out. */
    const LOANS = ['dlsub', 'dlunsub', 'dlplus'];

    /** @var string Shortname of the Financial Aid role the Federal aid page can create. */
    const ROLE = 'srfinaid';

    /** @var string[] Capabilities of the Financial Aid role. */
    const ROLECAPS = ['local/studentrecords:viewaid', 'local/studentrecords:manageaid'];

    /** @var string[] Award statuses. */
    const STATUSES = ['offered', 'accepted', 'declined', 'cancelled'];

    // Rules that need no database.

    /**
     * The kind of account credit a disbursement of a fund is: federal grant, federal loan, or (state, institutional,
     * private) a grant / scholarship; null for work-study (paid to the student, not to the account).
     *
     * @param string $fund
     * @return string|null
     */
    public static function credit_kind(string $fund): ?string {
        if ($fund === 'fws') {
            return null;
        }
        if (in_array($fund, self::LOANS, true)) {
            return 'fedloan';
        }
        return (self::FUNDS[$fund] ?? false) ? 'fedgrant' : ($fund === 'private' ? 'scholarship' : 'grant');
    }

    /**
     * Calendar days completed of a period on a day, and the days in the period (start and the day itself counted).
     *
     * @param string $startymd
     * @param string $endymd
     * @param string $dayymd
     * @return int[] done, total
     */
    protected static function days_completed(string $startymd, string $endymd, string $dayymd): array {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($startymd, $utc);
        $total = (int)$start->diff(new \DateTimeImmutable($endymd, $utc))->days + 1;
        $done = (int)$start->diff(new \DateTimeImmutable($dayymd, $utc))->format('%r%a') + 1;
        return [max(0, min($total, $done)), max(1, $total)];
    }

    /**
     * Percent of a period completed on a day, in calendar days (start and the day itself counted).
     *
     * @param string $startymd
     * @param string $endymd
     * @param string $dayymd
     * @return float 0-100
     */
    public static function percent_completed(string $startymd, string $endymd, string $dayymd): float {
        return self::share_percent(...self::days_completed($startymd, $endymd, $dayymd));
    }

    /**
     * A part of a period as a percent, the way the federal worksheet does it: worked out to four decimals, then rounded to
     * three. The handbook's worked examples cut the fourth decimal off, so 38 of 110 days is 0.3454 and 34.5%, and 0.4486 is
     * 44.9%.
     *
     * @param int $done
     * @param int $total above zero
     * @return float percent with one decimal
     */
    public static function share_percent(int $done, int $total): float {
        return billing::divide(intdiv($done * 10000, $total), 10) / 10;
    }

    /**
     * The day a student left a term, as the Return of aid pages read it: the last day of attendance in the term, then the last
     * attended date on the file, the withdrawal date on the file, and the day their last class of the term ended. A date
     * outside the term says nothing about this term.
     *
     * @param \stdClass $student
     * @param \stdClass $term
     * @return string Y-m-d, or '' when nothing says
     */
    public static function left_term(\stdClass $student, \stdClass $term): string {
        global $DB;
        $ended = (int)$DB->get_field_sql("SELECT MAX(r.timeended) FROM {" . registration::REG . "} r
            JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
            WHERE r.studentid = ? AND o.termid = ?", [$student->id, $term->id]);
        $start = catalog::time_to_date((int)$term->startdate);
        $end = catalog::time_to_date((int)$term->enddate);
        foreach (
            [attendance::term_last($student, (int)$term->id), catalog::time_to_date((int)$student->lastattended),
            catalog::time_to_date((int)$student->withdrawdate), catalog::time_to_date($ended)] as $day
        ) {
            if ($day !== '' && $day >= $start && $day <= $end) {
                return $day;
            }
        }
        return '';
    }

    /**
     * Whether a student is past the 60% point of a period, by any amount. A student past it has earned all aid, even when
     * the percent shown rounds to 60.0%.
     *
     * @param int $done
     * @param int $total
     * @return bool
     */
    public static function past_sixty(int $done, int $total): bool {
        return $done * 10 > $total * 6;
    }

    /**
     * 90/10 percent: federal aid cash out of all cash received for tuition and fees.
     *
     * @param float $federal
     * @param float $all
     * @return float|null null when no cash
     */
    public static function ninety_ten(float $federal, float $all): ?float {
        return $all > 0 ? billing::divide(billing::cents($federal) * 10000, billing::cents($all)) / 100 : null;
    }

    // Module.

    /**
     * Whether the school turned the federal aid module on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return catalog::module_on('fedaid');
    }

    /**
     * Throw unless the module is on.
     */
    public static function require_enabled(): void {
        if (!self::enabled()) {
            throw new \moodle_exception('err_aidoff', 'local_studentrecords');
        }
    }

    /**
     * Aid awards of a student, with the amount disbursed.
     *
     * @param int $studentid
     * @return array
     */
    public static function awards(int $studentid): array {
        global $DB;
        return $DB->get_records_sql("SELECT a.*, (SELECT COALESCE(-SUM(t.amount), 0) FROM {" . billing::TXN . "} t
                                                    WHERE t.aidid = a.id) AS disbursed
                                       FROM {" . self::AWARDS . "} a WHERE a.studentid = ?
                                   ORDER BY a.awardyear DESC, a.id", [$studentid]);
    }

    /**
     * Add or change an aid award (change history kept).
     *
     * @param \stdClass $student
     * @param array $data awardyear, termid, fund, amount, status, note, id
     * @return int id
     */
    public static function save_award(\stdClass $student, array $data): int {
        self::require_enabled();
        require_capability('local/studentrecords:manageaid', \context_system::instance());
        if (!array_key_exists($data['fund'], self::FUNDS) || !in_array($data['status'], self::STATUSES, true)) {
            throw new \coding_exception('Unknown fund or status');
        }
        $record = ['studentid' => $student->id, 'awardyear' => trim($data['awardyear']),
            'termid' => empty($data['termid']) ? null : (int)$data['termid'], 'fund' => $data['fund'],
            'amount' => round((float)$data['amount'], 2), 'status' => $data['status'],
            'note' => trim((string)($data['note'] ?? ''))];
        if (!empty($data['id'])) {
            $record['id'] = (int)$data['id'];
        }
        $id = records::save(self::AWARDS, $record, 'form', (int)$student->userid);
        audit::log(empty($data['id']) ? 'create' : 'update', 'aid', $id, (int)$student->userid, $record['fund'] . ' ' .
            billing::money($record['amount']) . ' ' . $record['status']);
        return $id;
    }

    /**
     * Disburse (part of) an accepted award to the student account: a credit line linked to the award.
     *
     * @param int $awardid
     * @param float $amount
     * @param int $date
     * @return int account line id
     */
    public static function disburse(int $awardid, float $amount, int $date): int {
        self::require_enabled();
        require_capability('local/studentrecords:manageaid', \context_system::instance());
        return billing::one_at_a_time(function () use ($awardid, $amount, $date) {
            global $DB;
            $award = $DB->get_record(self::AWARDS, ['id' => $awardid], '*', MUST_EXIST);
            $kind = self::credit_kind($award->fund);
            $done = -(float)$DB->get_field_sql(
                "SELECT COALESCE(SUM(amount), 0) FROM {" . billing::TXN . "} WHERE aidid = ?",
                [$awardid]
            );
            if ($award->status !== 'accepted' || $kind === null) {
                throw new \moodle_exception('err_aidnotdisbursable', 'local_studentrecords');
            }
            if ($amount <= 0 || billing::cents($amount) > billing::cents((float)$award->amount - $done)) {
                throw new \moodle_exception(
                    'err_aidamount',
                    'local_studentrecords',
                    '',
                    billing::money((float)$award->amount - $done)
                );
            }
            $p = 'local_studentrecords';
            return billing::add_line(['studentid' => $award->studentid, 'txntype' => 'credit', 'method' => $kind,
                'amount' => -round($amount, 2), 'aidid' => $awardid, 'termid' => $award->termid, 'txndate' => $date,
                'description' => get_string('aiddisbdesc', $p, (object)['fund' => get_string('fund_' . $award->fund, $p),
                'year' => $award->awardyear])]);
        });
    }

    /**
     * Create the Financial Aid role (system level, view aid and manage aid) if it does not exist. It is separate from the
     * Finance role.
     *
     * @return int role id
     */
    public static function create_role(): int {
        global $DB;
        $existing = $DB->get_field('role', 'id', ['shortname' => self::ROLE]);
        if ($existing) {
            return (int)$existing;
        }
        $p = 'local_studentrecords';
        $roleid = create_role(get_string('finaidrole', $p), self::ROLE, get_string('finaidrole_desc', $p));
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        $sys = \context_system::instance();
        foreach (self::ROLECAPS as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $sys->id);
        }
        $sys->mark_dirty();
        audit::log('create', 'setup', $roleid, null, 'Financial Aid role');
        return $roleid;
    }

    /**
     * Students for the aid list: search by name or student ID, with their award count.
     *
     * @param string $search
     * @return array
     */
    public static function students(string $search): array {
        global $DB;
        $where = '';
        $params = [];
        if ($search !== '') {
            $like = [];
            foreach (['s.studentid', 's.legalfirstname', 's.legallastname'] as $i => $f) {
                $like[] = $DB->sql_like($f, ':q' . $i, false);
                $params['q' . $i] = '%' . $DB->sql_like_escape($search) . '%';
            }
            $where = 'WHERE ' . implode(' OR ', $like);
        }
        return $DB->get_records_sql("SELECT s.id, s.studentid, s.legalfirstname, s.legalmiddlename, s.legallastname,
                                            s.preferredname, s.status,
                                            (SELECT COUNT(1) FROM {" . self::AWARDS . "} a WHERE a.studentid = s.id) AS awards
                                       FROM {local_studentrecords_student} s $where
                                   ORDER BY s.legallastname, s.legalfirstname", $params, 0, 200);
    }

    // Reports.

    /**
     * Aid recipients and amounts of an award year.
     *
     * @param string $awardyear
     * @return array rows: student, studentno, fund, status, amount, disbursed
     */
    public static function recipients(string $awardyear): array {
        global $DB;
        $out = [];
        $sql = "SELECT a.*, s.studentid AS studentno, s.legalfirstname, s.legalmiddlename, s.legallastname, s.preferredname,
                       (SELECT COALESCE(-SUM(t.amount), 0) FROM {" . billing::TXN . "} t WHERE t.aidid = a.id) AS disbursed
                  FROM {" . self::AWARDS . "} a JOIN {local_studentrecords_student} s ON s.id = a.studentid
                 WHERE a.awardyear = ? ORDER BY s.legallastname, s.legalfirstname, a.fund";
        foreach ($DB->get_records_sql($sql, [$awardyear]) as $a) {
            $out[] = ['student' => students::display_name($a), 'studentno' => $a->studentno, 'fund' => $a->fund,
                'status' => $a->status, 'amount' => (float)$a->amount, 'disbursed' => (float)$a->disbursed];
        }
        return $out;
    }

    /**
     * Disbursements (aid credit lines, reversals included) between two times.
     *
     * @param int $from
     * @param int $to first second after the period
     * @return array rows: date, student, studentno, fund, amount
     */
    public static function disbursements(int $from, int $to): array {
        global $DB;
        $out = [];
        $sql = "SELECT t.id, t.txndate, t.amount, a.fund, s.studentid AS studentno, s.legalfirstname, s.legalmiddlename,
                       s.legallastname, s.preferredname
                  FROM {" . billing::TXN . "} t JOIN {" . self::AWARDS . "} a ON a.id = t.aidid
                  JOIN {local_studentrecords_student} s ON s.id = t.studentid
                 WHERE t.txndate >= ? AND t.txndate < ? ORDER BY t.txndate, t.id";
        foreach ($DB->get_records_sql($sql, [$from, $to]) as $t) {
            $out[] = ['date' => catalog::time_to_date((int)$t->txndate), 'student' => students::display_name($t),
                'studentno' => $t->studentno, 'fund' => $t->fund, 'amount' => -(float)$t->amount];
        }
        return $out;
    }

    /**
     * Students with an accepted federal award for a term.
     *
     * @param int $termid
     * @return array student ids
     */
    protected static function term_recipients(int $termid): array {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(array_keys(array_filter(self::FUNDS)));
        return $DB->get_fieldset_sql("SELECT DISTINCT studentid FROM {" . self::AWARDS . "}
                                       WHERE termid = ? AND status = 'accepted' AND fund $in", array_merge([$termid], $params));
    }

    /**
     * Enrollment status of the aid recipients of a term (as the Clearinghouse: F, H, L, W, G, A).
     *
     * @param int $termid
     * @return array rows: student, studentno, status, hours
     */
    public static function enrollment(int $termid): array {
        global $DB;
        $term = $DB->get_record('local_studentrecords_term', ['id' => $termid], '*', MUST_EXIST);
        $fulltime = (float)(catalog::setting('fulltimehours') !== '' ? catalog::setting('fulltimehours') : 12);
        $halftime = (float)(catalog::setting('halftime') !== '' ? catalog::setting('halftime') : 6);
        $out = [];
        foreach (self::term_recipients($termid) as $sid) {
            $s = students::get((int)$sid);
            $load = outside::term_load((int)$sid, $termid);
            $out[] = ['student' => students::display_name($s), 'studentno' => $s->studentid, 'hours' => $load['hours'],
                'status' => outside::nsc_status(
                    ['status' => $s->status, 'loadtype' => $s->loadtype, 'hours' => $load['hours'],
                    'allended' => $load['allended'], 'awarded' => $s->awarddate && $s->awarddate <= $term->enddate],
                    $fulltime,
                    $halftime
                )];
        }
        return $out;
    }

    /**
     * Aid recipients of a term who withdrew (every course of the term dropped or withdrawn, or status withdrawn), with the
     * dates and the percent of the term completed: helper data for the school's own Return of Title IV funds calculation.
     *
     * @param int $termid
     * @return array rows: sid, student, studentno, withdrawn (Y-m-d), lastattended, start, end, percent, over60, disbursed
     */
    public static function withdrawals(int $termid): array {
        global $DB;
        $term = $DB->get_record('local_studentrecords_term', ['id' => $termid], '*', MUST_EXIST);
        $start = catalog::time_to_date((int)$term->startdate);
        $end = catalog::time_to_date((int)$term->enddate);
        $out = [];
        foreach (self::term_recipients($termid) as $sid) {
            $s = students::get((int)$sid);
            $load = outside::term_load((int)$sid, $termid);
            if ($s->status !== 'withdrawn' && !$load['allended']) {
                continue;
            }
            $ended = (int)$DB->get_field_sql("SELECT MAX(r.timeended) FROM {" . registration::REG . "} r
                JOIN {" . registration::OFFER . "} o ON o.id = r.offerid WHERE r.studentid = ? AND o.termid = ?", [$sid, $termid]);
            $withdrawn = catalog::time_to_date((int)($s->withdrawdate ?: $ended) ?: null);
            $lda = attendance::term_last($s, $termid);
            $lda = $lda !== '' ? $lda : catalog::time_to_date($s->lastattended ? (int)$s->lastattended : null);
            $day = self::left_term($s, $term);
            $pct = $day !== '' ? self::percent_completed($start, $end, $day) : null;
            $over60 = $day !== '' && self::past_sixty(...self::days_completed($start, $end, $day));
            $disbursed = -(float)$DB->get_field_sql("SELECT COALESCE(SUM(t.amount), 0) FROM {" . billing::TXN . "} t
                JOIN {" . self::AWARDS . "} a ON a.id = t.aidid WHERE t.studentid = ? AND a.termid = ?", [$sid, $termid]);
            $out[] = ['sid' => (int)$sid, 'student' => students::display_name($s), 'studentno' => $s->studentid,
                'withdrawn' => $withdrawn,
                'lastattended' => $lda, 'start' => $start, 'end' => $end, 'percent' => $pct, 'over60' => $over60,
                'disbursed' => $disbursed];
        }
        return $out;
    }

    /**
     * 90/10 worksheet for a fiscal year: federal aid cash credited to accounts, and all cash received for tuition and fees
     * (payments less money paid back, plus all aid and scholarship credits that are money).
     *
     * @param int $from
     * @param int $to first second after the year
     * @return array federal, other, all, percent
     */
    public static function ninety_ten_worksheet(int $from, int $to): array {
        global $DB;
        $sum = fn(string $where, array $params) => -(float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0)
            FROM {" . billing::TXN . "} WHERE txndate >= ? AND txndate < ? AND $where", array_merge([$from, $to], $params));
        $federal = round($sum("txntype = 'credit' AND method IN ('fedgrant', 'fedloan')", []), 2);
        $other = round($sum("(txntype IN ('payment', 'payout') OR (txntype = 'credit' AND method IN ('scholarship', 'grant',
            'sponsor')))", []), 2);
        $all = round($federal + $other, 2);
        return ['federal' => $federal, 'other' => $other, 'all' => $all, 'percent' => self::ninety_ten($federal, $all)];
    }
}
