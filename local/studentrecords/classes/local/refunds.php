<?php

namespace local_studentrecords\local;

/**
 * Refunds: the school's refund rules (each state has its own; the school enters its own), a refund figured by itself
 * when a student drops or withdraws, approved or rejected by staff with the approve refunds capability, and money
 * paid back (recorded by staff: Moodle cannot send money back by itself).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class refunds {
    /** @var string Refund rules table. */
    const RULES = 'local_studentrecords_refrule';

    /** @var string Refunds table. */
    const REFUNDS = 'local_studentrecords_refund';

    /** @var string[] Kinds of refund rule, in the order schools usually list them. */
    const RULETYPES = ['cancel', 'beforestart', 'adddrop', 'pctterm', 'days', 'after'];

    /** @var string[] Ways money is paid back. */
    const PAYOUTS = ['check', 'banktransfer', 'provider', 'cash', 'other'];

    // Rules that need no database.

    /**
     * Business days (Monday to Friday) after one day up to and including another.
     *
     * @param string $fromymd
     * @param string $toymd
     * @return int 0 when the second day is not after the first
     */
    public static function business_days(string $fromymd, string $toymd): int {
        $utc = new \DateTimeZone('UTC');
        $day = new \DateTimeImmutable($fromymd, $utc);
        $to = new \DateTimeImmutable($toymd, $utc);
        $n = 0;
        while ($day < $to) {
            $day = $day->modify('+1 day');
            if ((int)$day->format('N') <= 5) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Whether a rule fits a drop or withdrawal.
     *
     * @param \stdClass|array $rule ruletype, value
     * @param array $c Y-m-d: event (drop or withdrawal), registered, start, adddropend ('' = none), end
     * @return bool
     */
    public static function rule_fits($rule, array $c): bool {
        $r = (array)$rule;
        $value = (float)$r['value'];
        $utc = new \DateTimeZone('UTC');
        switch ($r['ruletype']) {
            case 'cancel':
                return $c['event'] >= $c['registered'] && self::business_days($c['registered'], $c['event']) <= $value;
            case 'beforestart':
                return $c['event'] < $c['start'];
            case 'adddrop':
                return $c['adddropend'] !== '' && $c['event'] <= $c['adddropend'];
            case 'pctterm':
                $start = new \DateTimeImmutable($c['start'], $utc);
                $length = max(1, (int)$start->diff(new \DateTimeImmutable($c['end'], $utc))->days);
                $done = (int)$start->diff(new \DateTimeImmutable($c['event'], $utc))->format('%r%a');
                // Whole numbers, so that a drop exactly on the rule's percent still fits (7 of 100 days under 7%).
                return $done * 10000 <= billing::cents($value) * $length;
            case 'days':
                return $c['event'] <= (new \DateTimeImmutable($c['start'], $utc))->modify('+' . (int)$value . ' days')
                    ->format('Y-m-d');
            case 'after':
                return true;
        }
        return false;
    }

    /**
     * The first active rule, in order, that fits.
     *
     * @param array $rules ordered
     * @param array $c see rule_fits()
     * @return \stdClass|null
     */
    public static function pick_rule(array $rules, array $c): ?\stdClass {
        foreach ($rules as $rule) {
            $rule = (object)$rule;
            if (!empty($rule->active) && self::rule_fits($rule, $c)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Percent of a charge a rule refunds: tuition and refundable fees by the rule's percents; non-refundable charges
     * only when the rule refunds all fees.
     *
     * @param \stdClass|array|null $rule
     * @param string $kind tuition or fee
     * @param bool $refundable
     * @return float
     */
    public static function percent($rule, string $kind, bool $refundable): float {
        if ($rule === null) {
            return 0.0;
        }
        $r = (array)$rule;
        if (!$refundable && empty($r['allfees'])) {
            return 0.0;
        }
        return (float)($kind === 'tuition' ? $r['tuitionpct'] : $r['feespct']);
    }

    // Rules of the school.

    /**
     * The school's refund rules in order.
     *
     * @param bool $activeonly
     * @return array
     */
    public static function rules(bool $activeonly = false): array {
        global $DB;
        return $DB->get_records(self::RULES, $activeonly ? ['active' => 1] : null, 'sortorder, id');
    }

    /**
     * Add or change a refund rule (change history kept). Refunds already figured do not change.
     *
     * @param array $data
     * @return int id
     */
    public static function save_rule(array $data): int {
        $fields = ['sortorder', 'name', 'ruletype', 'value', 'tuitionpct', 'feespct', 'allfees', 'active', 'note'];
        $record = array_intersect_key($data, array_flip($fields));
        $record['allfees'] = empty($record['allfees']) ? 0 : 1;
        $record['active'] = isset($data['active']) ? (empty($data['active']) ? 0 : 1) : 1;
        if (!empty($data['id'])) {
            $record['id'] = (int)$data['id'];
        }
        $id = records::save(self::RULES, $record);
        audit::log(empty($data['id']) ? 'create' : 'update', 'refundrule', $id, null, (string)$record['name']);
        return $id;
    }

    // Refunds.

    /**
     * What is left of a charge after the refunds on it.
     *
     * @param \stdClass $charge
     * @return float
     */
    protected static function charge_left(\stdClass $charge): float {
        global $DB;
        $refunded = -(float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0) FROM {" . billing::TXN . "}
                                                 WHERE txntype = 'refund' AND chargeid = ?", [$charge->id]);
        return round((float)$charge->amount - $refunded, 2);
    }

    /**
     * The charges a drop or withdrawal of a registration can refund, with what is left of each after earlier
     * refunds: the course's own charges, and the term's charges (term fees, per term tuition) when the student has
     * no other registration left in the term. Aid sent back to the government is not a school charge, so it is left out.
     *
     * @param \stdClass $reg
     * @param int $termid
     * @return array charge lines, each with ->net and ->kind
     */
    public static function refundable_charges(\stdClass $reg, int $termid): array {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(registration::ACTIVE);
        $others = $DB->record_exists_sql(
            "SELECT 1 FROM {" . registration::REG . "} r JOIN {" . registration::OFFER . "} o
                                            ON o.id = r.offerid
                                         WHERE r.studentid = ? AND o.termid = ? AND r.id <> ? AND r.status $in",
            array_merge([$reg->studentid, $termid, $reg->id], $params)
        );
        $where = "studentid = ? AND txntype = 'charge' AND amount > 0 AND reversedby IS NULL AND refundid IS NULL AND (regid = ?" .
            ($others ? ')' : ' OR (regid IS NULL AND termid = ?))');
        $params = $others ? [$reg->studentid, $reg->id] : [$reg->studentid, $reg->id, $termid];
        $out = [];
        foreach ($DB->get_records_select(billing::TXN, $where, $params, 'txndate, id') as $charge) {
            $charge->net = self::charge_left($charge);
            $charge->kind = $charge->feeid ? (string)$DB->get_field(billing::FEES, 'kind', ['id' => $charge->feeid]) : 'fee';
            if ($charge->net > 0.004) {
                $out[] = $charge;
            }
        }
        return $out;
    }

    /**
     * Figure a refund for a registration on a day: the rule that fits and the refund of each charge.
     *
     * @param \stdClass $reg
     * @param int $eventtime
     * @return array rule (or null), lines (chargeid, description, net, percent, refund), amount
     */
    public static function figure(\stdClass $reg, int $eventtime): array {
        $offer = registration::offer((int)$reg->offerid);
        $days = registration::term_days((int)$offer->termid);
        $c = ['event' => catalog::time_to_date($eventtime), 'registered' => catalog::time_to_date((int)$reg->timeregistered),
            'start' => $days['startdate'], 'adddropend' => $days['adddropend'], 'end' => $days['enddate']];
        $rule = self::pick_rule(array_values(self::rules(true)), $c);
        $lines = [];
        $amount = 0.0;
        foreach (self::refundable_charges($reg, (int)$offer->termid) as $charge) {
            $pct = self::percent($rule, $charge->kind, (bool)$charge->refundable);
            $refund = billing::times($charge->net, $pct, 100);
            $lines[] = ['chargeid' => (int)$charge->id, 'description' => $charge->description, 'net' => $charge->net,
                'percent' => $pct, 'refund' => $refund];
            $amount = round($amount + $refund, 2);
        }
        return ['rule' => $rule, 'lines' => $lines, 'amount' => $amount, 'termid' => (int)$offer->termid];
    }

    /**
     * Propose the refund of a drop or withdrawal (when billing is on). Nothing to refund is kept too, as "none",
     * so staff see which rule applied.
     *
     * @param int $regid
     * @param string $reason drop or withdraw
     * @param int|null $eventtime default now
     * @return int|null refund id
     */
    public static function propose(int $regid, string $reason, ?int $eventtime = null): ?int {
        global $DB, $USER;
        if (!billing::enabled()) {
            return null;
        }
        $reg = $DB->get_record(registration::REG, ['id' => $regid], '*', MUST_EXIST);
        $eventtime = $eventtime ?? time();
        $f = self::figure($reg, $eventtime);
        if (!$f['lines']) {
            return null;
        }
        $student = students::get((int)$reg->studentid);
        return billing::transaction(function () use ($DB, $USER, $reg, $regid, $reason, $eventtime, $f, $student) {
            $id = (int)$DB->insert_record(self::REFUNDS, (object)['studentid' => $reg->studentid, 'termid' => $f['termid'],
                'regid' => $regid, 'reason' => $reason, 'eventdate' => $eventtime, 'ruleid' => $f['rule']->id ?? null,
                'rulename' => $f['rule']->name ?? null, 'details' => json_encode($f['lines']), 'amount' => $f['amount'],
                'status' => $f['amount'] > 0 ? 'proposed' : 'none', 'duedate' => $eventtime + self::refund_days() * DAYSECS,
                'userid' => $USER->id ?? 0, 'timecreated' => time(), 'timemodified' => time()]);
            audit::log('create', 'refund', $id, (int)$student->userid, $reason . ' ' . billing::money($f['amount']) . ' (' .
                ($f['rule']->name ?? get_string('norefundrule', 'local_studentrecords')) . ')');
            if ($f['amount'] > 0) {
                messages::staff('staffrefund', $student, new \moodle_url('/local/studentrecords/refunds.php', ['id' => $id]));
            }
            return $id;
        });
    }

    /**
     * Figure a proposed refund again on another day (for example the last date of attendance).
     *
     * @param int $refundid
     * @param int $eventtime
     */
    public static function refigure(int $refundid, int $eventtime): void {
        billing::one_at_a_time(function () use ($refundid, $eventtime) {
            global $DB;
            $refund = $DB->get_record(self::REFUNDS, ['id' => $refundid], '*', MUST_EXIST);
            if (!self::can_refigure($refund)) {
                throw new \moodle_exception('err_refunddecided', 'local_studentrecords');
            }
            $f = self::figure($DB->get_record(registration::REG, ['id' => $refund->regid], '*', MUST_EXIST), $eventtime);
            $student = students::get((int)$refund->studentid);
            records::save(
                self::REFUNDS,
                ['id' => $refundid, 'eventdate' => $eventtime, 'ruleid' => $f['rule']->id ?? null,
                'rulename' => $f['rule']->name ?? null, 'details' => json_encode($f['lines']), 'amount' => $f['amount'],
                'status' => $f['amount'] > 0 ? 'proposed' : 'none', 'duedate' => $eventtime + self::refund_days() * DAYSECS],
                'form',
                (int)$student->userid
            );
        });
    }

    /**
     * Whether a refund can be figured again: not once it is decided, and never a return of aid, which comes from the
     * Return of aid worksheet and has no registration to figure from.
     *
     * @param \stdClass $refund
     * @return bool
     */
    public static function can_refigure(\stdClass $refund): bool {
        return in_array($refund->status, ['proposed', 'none'], true) && $refund->reason !== 'aidreturn';
    }

    /**
     * Approve a refund (refund lines on the account, one per charge) or reject it, with a note. The refund may have been
     * figured a while ago, so each charge is checked again: money comes back once, and never for a charge that was
     * reversed or refunded since. Nobody decides a refund on their own account.
     *
     * @param int $refundid
     * @param bool $approve
     * @param string $note
     */
    public static function decide(int $refundid, bool $approve, string $note): void {
        if (!has_capability('local/studentrecords:approverefund', \context_system::instance())) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/studentrecords:approverefund',
                'nopermissions',
                ''
            );
        }
        billing::one_at_a_time(function () use ($refundid, $approve, $note) {
            global $DB, $USER;
            $refund = $DB->get_record(self::REFUNDS, ['id' => $refundid], '*', MUST_EXIST);
            if ($refund->status !== 'proposed') {
                throw new \moodle_exception('err_refunddecided', 'local_studentrecords');
            }
            $student = students::get((int)$refund->studentid);
            if ((int)$student->userid === (int)$USER->id) {
                throw new \moodle_exception('err_refundself', 'local_studentrecords');
            }
            $lines = json_decode((string)$refund->details, true) ?: [];
            if ($approve && $refund->reason === 'aidreturn') {
                // Return of federal aid: the school sends the unearned aid back, so it comes off the student account (a charge
                // per fund); Finance records the money sent back.
                foreach ($lines as $line) {
                    if ((float)$line['refund'] <= 0) {
                        continue;
                    }
                    billing::add_line(['studentid' => $student->id, 'txntype' => 'charge', 'amount' => (float)$line['refund'],
                        'termid' => $refund->termid, 'refundid' => $refundid, 'qualified' => 0, 'refundable' => 0,
                        'description' => get_string('ret_chargedesc', 'local_studentrecords', $line['description'])]);
                }
            } else if ($approve) {
                foreach ($lines as $line) {
                    if ($line['refund'] <= 0) {
                        continue;
                    }
                    $charge = $DB->get_record(
                        billing::TXN,
                        ['id' => $line['chargeid'], 'studentid' => $refund->studentid],
                        '*',
                        MUST_EXIST
                    );
                    $left = billing::cents(self::charge_left($charge));
                    if ($charge->reversedby || billing::cents((float)$line['refund']) > $left) {
                        throw new \moodle_exception('err_refundstale', 'local_studentrecords');
                    }
                    billing::add_line(['studentid' => $student->id, 'txntype' => 'refund', 'amount' => -(float)$line['refund'],
                        'termid' => $charge->termid, 'regid' => $charge->regid, 'feeid' => $charge->feeid,
                        'chargeid' => $charge->id, 'refundid' => $refundid, 'qualified' => (int)$charge->qualified,
                        'refundable' => (int)$charge->refundable,
                        'description' => get_string('refunddesc', 'local_studentrecords', (object)['percent' =>
                            format_float((float)$line['percent'], -1), 'description' => $charge->description])]);
                }
            }
            records::save(self::REFUNDS, ['id' => $refundid, 'status' => $approve ? 'approved' : 'rejected',
                'decidedby' => $USER->id, 'timedecided' => time(), 'decisionnote' => trim($note)], 'form', (int)$student->userid);
            if ($approve && $refund->reason !== 'aidreturn') {
                messages::student('refundapproved', $student);
            }
            audit::log($approve ? 'approve' : 'reject', 'refund', $refundid, (int)$student->userid, billing::money(
                (float)$refund->amount
            ) . ($note !== '' ? ': ' . $note : ''));
        });
    }

    /**
     * Record money paid back to a student (the school owes it: balance below zero).
     *
     * @param \stdClass $student
     * @param float $amount
     * @param string $method one of PAYOUTS
     * @param array $extra txndate, reference, note, refundid
     * @return int account line id
     */
    public static function payout(\stdClass $student, float $amount, string $method, array $extra = []): int {
        return billing::one_at_a_time(function () use ($student, $amount, $method, $extra) {
            $owed = -billing::balance((int)$student->id);
            if ($amount <= 0 || billing::cents($amount) > billing::cents($owed)) {
                throw new \moodle_exception('err_payoutamount', 'local_studentrecords', '', billing::money(max(0, $owed)));
            }
            $id = billing::add_line(array_merge(array_intersect_key($extra, array_flip(['txndate', 'reference', 'note',
                'refundid'])), ['studentid' => $student->id, 'txntype' => 'payout', 'amount' => round($amount, 2),
                'method' => $method, 'description' => get_string(
                    'payoutdesc',
                    'local_studentrecords',
                    get_string('payout_' . $method, 'local_studentrecords')
                )]));
            messages::student('refundpaid', $student);
            return $id;
        });
    }

    /**
     * Days the school has to pay a refund back (setting, default 30).
     *
     * @return int
     */
    public static function refund_days(): int {
        $v = catalog::setting('refunddays');
        return $v !== '' ? max(0, (int)$v) : 30;
    }

    /**
     * Refunds for the staff list, newest first.
     *
     * @param string $status all, proposed, approved, rejected or none
     * @param int $studentid 0 = all students
     * @return array
     */
    public static function listing(string $status = 'proposed', int $studentid = 0): array {
        global $DB;
        $where = [];
        $params = [];
        if ($status !== 'all') {
            $where[] = 'f.status = ?';
            $params[] = $status;
        }
        if ($studentid) {
            $where[] = 'f.studentid = ?';
            $params[] = $studentid;
        }
        return $DB->get_records_sql("SELECT f.*, s.studentid AS studentno, s.legalfirstname, s.legalmiddlename, s.legallastname,
                                            s.preferredname, c.code, t.name AS termname
                                       FROM {" . self::REFUNDS . "} f
                                       JOIN {local_studentrecords_student} s ON s.id = f.studentid
                                  LEFT JOIN {" . registration::REG . "} r ON r.id = f.regid
                                  LEFT JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                  LEFT JOIN {local_studentrecords_course} c ON c.id = o.courseid
                                  LEFT JOIN {local_studentrecords_term} t ON t.id = f.termid " .
            ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY f.timecreated DESC, f.id DESC", $params);
    }
}
