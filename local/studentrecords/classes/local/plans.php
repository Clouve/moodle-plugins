<?php

namespace local_studentrecords\local;

/**
 * Payment plans: the plans a school offers, a plan given to a student for a term (with its schedule of payments),
 * reminders before each due date, and late payments (late fee, then a financial hold after the grace days; staff
 * decide any drop). Payments and credits made after a plan starts count toward its payments in due order.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class plans {
    /** @var string The school's plans. */
    const PLANS = 'local_studentrecords_plan';

    /** @var string Plans given to students. */
    const STUPLANS = 'local_studentrecords_stuplan';

    /** @var string Payments of a student's plan. */
    const INSTS = 'local_studentrecords_inst';

    /** @var string[] How often plan payments are due. */
    const FREQUENCIES = ['weekly', 'biweekly', 'monthly'];

    /** @var string[] Kinds of down payment. */
    const DOWNTYPES = ['percent', 'amount'];

    /** @var string[] How due dates are set. */
    const DUEMODES = ['relative', 'fixed'];

    // Rules that need no database.

    /**
     * Due dates of the payments after the down payment: the first a number of days after the term starts, then
     * every week, two weeks or month; or the school's fixed dates.
     *
     * @param string $duemode relative or fixed
     * @param string $startymd term start (Y-m-d)
     * @param int $firstdue days after the start (may be below zero: before the term starts)
     * @param string $frequency weekly, biweekly or monthly
     * @param int $numpay number of payments
     * @param string $fixed fixed dates, comma separated Y-m-d
     * @return string[] Y-m-d dates, or [] when fixed dates are missing or not valid
     */
    public static function due_days(
        string $duemode,
        string $startymd,
        int $firstdue,
        string $frequency,
        int $numpay,
        string $fixed = ''
    ): array {
        if ($duemode === 'fixed') {
            $dates = array_values(array_filter(array_map('trim', explode(',', $fixed)), fn($d) => $d !== ''));
            foreach ($dates as $d) {
                if (!self::valid_ymd($d)) {
                    return [];
                }
            }
            sort($dates);
            return count($dates) === $numpay ? $dates : [];
        }
        $utc = new \DateTimeZone('UTC');
        $first = (new \DateTimeImmutable($startymd, $utc))->modify(($firstdue >= 0 ? '+' : '') . $firstdue . ' days');
        $out = [];
        for ($i = 0; $i < $numpay; $i++) {
            if ($frequency === 'monthly') {
                // Same day of the month; the last day when the month is shorter (Jan 31 -> Feb 28).
                $target = $first->modify('first day of +' . $i . ' month');
                $day = min((int)$first->format('j'), (int)$target->format('t'));
                $out[] = $target->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
            } else {
                $out[] = $first->modify('+' . ($i * ($frequency === 'weekly' ? 7 : 14)) . ' days')->format('Y-m-d');
            }
        }
        return $out;
    }

    /**
     * Whether a text is a real date written Y-m-d.
     *
     * @param string $ymd
     * @return bool
     */
    public static function valid_ymd(string $ymd): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * The amounts of a plan: the down payment (percent of the total, or an amount up to the total), then the rest in
     * equal payments; cents left over go on the last payment.
     *
     * @param float $total amount the plan covers
     * @param string $downtype percent or amount
     * @param float $downvalue
     * @param int $numpay payments after the down payment
     * @return float[] down payment first, then each payment
     */
    public static function amounts(float $total, string $downtype, float $downvalue, int $numpay): array {
        $total = round($total, 2);
        if ($numpay < 1) {
            return [$total];
        }
        // Whole cents: a share worked out in floats can come out a cent short (1.14 in two parts gave 0.56 and 0.58).
        $whole = billing::cents($total);
        $down = min($whole, billing::cents($downtype === 'percent' ? billing::times($total, $downvalue, 100) : $downvalue));
        $rest = $whole - $down;
        $each = intdiv($rest, $numpay);
        $out = [$down / 100.0];
        for ($i = 1; $i < $numpay; $i++) {
            $out[] = $each / 100.0;
        }
        $out[] = ($rest - $each * ($numpay - 1)) / 100.0;
        return $out;
    }

    /**
     * Share money paid toward a plan among its payments in due order.
     *
     * @param float[] $amounts amount of each payment, in due order
     * @param float $paid paid toward the plan
     * @return float[] paid on each payment
     */
    public static function allocate(array $amounts, float $paid): array {
        $left = max(0.0, round($paid, 2));
        $out = [];
        foreach ($amounts as $a) {
            $share = min(round((float)$a, 2), $left);
            $out[] = round($share, 2);
            $left = round($left - $share, 2);
        }
        return $out;
    }

    /**
     * Whether an unpaid payment is late: after its due date plus the grace days.
     *
     * @param string $dueymd
     * @param int $gracedays
     * @param string $todayymd
     * @return bool
     */
    public static function is_late(string $dueymd, int $gracedays, string $todayymd): bool {
        $last = (new \DateTimeImmutable($dueymd, new \DateTimeZone('UTC')))->modify('+' . max(0, $gracedays) . ' days');
        return $todayymd > $last->format('Y-m-d');
    }

    // The school's plans.

    /**
     * The school's plans.
     *
     * @param bool $activeonly
     * @return array
     */
    public static function templates(bool $activeonly = false): array {
        global $DB;
        return $DB->get_records(self::PLANS, $activeonly ? ['active' => 1] : null, 'name, id');
    }

    /**
     * The school's active plans a student may use (for all, the student's level or program).
     *
     * @param \stdClass $student
     * @return array
     */
    public static function templates_for(\stdClass $student): array {
        global $DB;
        $programid = $student->programid ? (int)$student->programid : null;
        $levelid = $programid ? ((int)$DB->get_field('local_studentrecords_program', 'levelid', ['id' => $programid]) ?: null) :
            null;
        return array_filter(self::templates(true), fn($t) => billing::applies($t, $programid, $levelid));
    }

    /**
     * Add or change a school plan (change history kept). Plans already given to students do not change.
     *
     * @param array $data
     * @return int id
     */
    public static function save_template(array $data): int {
        $fields = ['name', 'scope', 'scopeid', 'downtype', 'downvalue', 'numpay', 'frequency', 'duemode', 'firstdue',
            'duedates', 'planfee', 'latefee', 'gracedays', 'active', 'note'];
        $record = array_intersect_key($data, array_flip($fields));
        $record['scopeid'] = ($record['scope'] ?? 'all') === 'all' ? null : (int)$record['scopeid'];
        $record['active'] = isset($data['active']) ? (empty($data['active']) ? 0 : 1) : 1;
        if (!empty($data['id'])) {
            $record['id'] = (int)$data['id'];
        }
        $id = records::save(self::PLANS, $record);
        audit::log(empty($data['id']) ? 'create' : 'update', 'plan', $id, null, (string)$record['name']);
        return $id;
    }

    // Plans of a student.

    /**
     * The plans of a student with their payments, newest first.
     *
     * @param int $studentid
     * @return array plans, each with ->insts
     */
    public static function for_student(int $studentid): array {
        global $DB;
        $plans = $DB->get_records(self::STUPLANS, ['studentid' => $studentid], 'timeapproved DESC, id DESC');
        foreach ($plans as $plan) {
            $plan->insts = $DB->get_records(self::INSTS, ['stuplanid' => $plan->id], 'seq');
        }
        return $plans;
    }

    /**
     * The schedule a plan would have for a student now: the amount it covers and its payments.
     *
     * @param \stdClass $student
     * @param int $termid
     * @param array $terms downtype, downvalue, numpay, frequency, duemode, firstdue, duedates, planfee
     * @return array ['total' => float, 'rows' => list of ['seq', 'due' (Y-m-d), 'amount']]
     * @throws \moodle_exception when nothing is owed or the due dates are not valid
     */
    public static function preview(\stdClass $student, int $termid, array $terms): array {
        global $DB;
        $total = round(billing::balance((int)$student->id) + (float)$terms['planfee'], 2);
        if (billing::balance((int)$student->id) <= 0.004) {
            throw new \moodle_exception('err_noplanbalance', 'local_studentrecords');
        }
        $start = catalog::time_to_date((int)$DB->get_field(
            'local_studentrecords_term',
            'startdate',
            ['id' => $termid],
            MUST_EXIST
        ));
        $days = self::due_days(
            $terms['duemode'],
            $start,
            (int)$terms['firstdue'],
            $terms['frequency'],
            (int)$terms['numpay'],
            (string)($terms['duedates'] ?? '')
        );
        if (count($days) !== (int)$terms['numpay']) {
            throw new \moodle_exception('err_plandates', 'local_studentrecords');
        }
        $amounts = self::amounts($total, $terms['downtype'], (float)$terms['downvalue'], (int)$terms['numpay']);
        $rows = [['seq' => 0, 'due' => catalog::time_to_date(time()), 'amount' => $amounts[0]]];
        foreach ($days as $i => $day) {
            $rows[] = ['seq' => $i + 1, 'due' => $day, 'amount' => $amounts[$i + 1]];
        }
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Send the plan agreement to the student: a summary message (first due date, link to the full schedule on the
     * account page; no amounts in messages).
     *
     * @param \stdClass $student
     * @param int $stuplanid
     * @return bool sent
     */
    public static function send_agreement(\stdClass $student, int $stuplanid): bool {
        global $DB;
        $plan = $DB->get_record(self::STUPLANS, ['id' => $stuplanid], '*', MUST_EXIST);
        $first = $DB->get_records(self::INSTS, ['stuplanid' => $stuplanid], 'seq', '*', 0, 1);
        $first = $first ? reset($first) : null;
        $term = $plan->termid ? (string)$DB->get_field('local_studentrecords_term', 'name', ['id' => $plan->termid]) : '';
        return messages::student('planagreement', $student, ['term' => $term,
            'due_date' => $first ? catalog::show_date((int)$first->duedate) : '']);
    }

    /**
     * Give a student a plan for a term: the plan fee is charged, the balance is split into the down payment (due
     * today) and the payments.
     *
     * @param \stdClass $student
     * @param int $termid
     * @param int|null $planid the school plan; null = custom (a note is required)
     * @param array $terms downtype, downvalue, numpay, frequency, duemode, firstdue, duedates, planfee, latefee,
     *     gracedays, name
     * @param string $note
     * @return int student plan id
     */
    public static function create(\stdClass $student, int $termid, ?int $planid, array $terms, string $note = ''): int {
        if ($planid === null && trim($note) === '') {
            throw new \moodle_exception('err_customnote', 'local_studentrecords');
        }
        return billing::one_at_a_time(function () use ($student, $termid, $planid, $terms, $note) {
            global $DB, $USER;
            if ($DB->record_exists(self::STUPLANS, ['studentid' => $student->id, 'termid' => $termid, 'status' => 'active'])) {
                throw new \moodle_exception('err_planexists', 'local_studentrecords');
            }
            $preview = self::preview($student, $termid, $terms);
            $now = time();
            $id = (int)$DB->insert_record(self::STUPLANS, (object)['studentid' => $student->id, 'termid' => $termid,
                'planid' => $planid, 'name' => \core_text::substr(trim($terms['name']), 0, 255), 'total' => $preview['total'],
                'planfee' => round((float)$terms['planfee'], 2), 'latefee' => round((float)$terms['latefee'], 2),
                'gracedays' => max(0, (int)$terms['gracedays']), 'status' => 'active', 'note' => trim($note),
                'approvedby' => $USER->id, 'timeapproved' => $now, 'usermodified' => $USER->id, 'timemodified' => $now]);
            foreach ($preview['rows'] as $row) {
                $DB->insert_record(self::INSTS, (object)['stuplanid' => $id, 'seq' => $row['seq'],
                    'duedate' => catalog::date_to_time($row['due']), 'amount' => $row['amount'], 'paid' => 0, 'status' => 'due',
                    'timemodified' => $now]);
            }
            if ((float)$terms['planfee'] > 0) {
                billing::add_line(['studentid' => $student->id, 'txntype' => 'charge', 'amount' => (float)$terms['planfee'],
                    'termid' => $termid, 'planid' => $id, 'qualified' => 0, 'refundable' => 0,
                    'description' => get_string('planfeedesc', 'local_studentrecords', trim($terms['name']))]);
            }
            audit::log('create', 'plan', $id, (int)$student->userid, trim($terms['name']) . ' ' .
                billing::money($preview['total']) . ($planid === null ? ' (custom: ' . trim($note) . ')' : ''));
            self::sync($student);
            payments::release($student);
            return $id;
        });
    }

    /**
     * Cancel a student's plan (kept, with who and when); its payments stop counting.
     *
     * @param int $stuplanid
     * @param string $note
     */
    public static function cancel(int $stuplanid, string $note): void {
        global $DB, $USER;
        $plan = $DB->get_record(self::STUPLANS, ['id' => $stuplanid, 'status' => 'active'], '*', MUST_EXIST);
        $student = students::get((int)$plan->studentid);
        records::save(self::STUPLANS, ['id' => $stuplanid, 'status' => 'cancelled', 'cancelledby' => $USER->id,
            'timecancelled' => time(), 'note' => trim($plan->note . "\n" . $note)], 'form', (int)$student->userid);
        audit::log('update', 'plan', $stuplanid, (int)$student->userid, 'cancelled: ' . $note);
    }

    /**
     * Money paid toward a plan: payments and credits added since the plan started (a returned payment counts back).
     *
     * @param \stdClass $plan
     * @return float
     */
    public static function paid_toward(\stdClass $plan): float {
        global $DB;
        return round(-(float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0) FROM {" . billing::TXN . "}
            WHERE studentid = ? AND txntype IN ('payment', 'credit') AND timecreated >= ?", [$plan->studentid,
            $plan->timeapproved]), 2);
    }

    /**
     * Bring a student's plans up to date: what is paid on each payment, paid / late, plan completed. A completed plan is
     * looked at too, because a returned payment opens it again.
     *
     * @param \stdClass $student
     */
    public static function sync(\stdClass $student): void {
        global $DB;
        $today = catalog::time_to_date(time());
        $select = "studentid = ? AND status IN ('active', 'completed')";
        foreach ($DB->get_records_select(self::STUPLANS, $select, [$student->id]) as $plan) {
            $insts = array_values($DB->get_records(self::INSTS, ['stuplanid' => $plan->id], 'seq'));
            $paid = self::allocate(array_map(fn($i) => (float)$i->amount, $insts), self::paid_toward($plan));
            $allpaid = true;
            foreach ($insts as $k => $inst) {
                $full = $paid[$k] >= round((float)$inst->amount, 2) - 0.004;
                $status = $full ? 'paid' : (self::is_late(
                    catalog::time_to_date((int)$inst->duedate),
                    (int)$plan->gracedays,
                    $today
                ) ? 'late' : 'due');
                $allpaid = $allpaid && $full;
                if ($status !== $inst->status || abs($paid[$k] - (float)$inst->paid) > 0.004) {
                    $DB->update_record(self::INSTS, (object)['id' => $inst->id, 'paid' => $paid[$k], 'status' => $status,
                        'timepaid' => $full ? ($inst->timepaid ?: time()) : null, 'timemodified' => time()]);
                }
            }
            $status = $allpaid ? 'completed' : 'active';
            if ($status !== $plan->status) {
                records::save(self::STUPLANS, ['id' => $plan->id, 'status' => $status], 'billing', (int)$student->userid);
            }
        }
    }

    /**
     * Whether the student has an approved plan for the term whose down payment is paid.
     *
     * @param \stdClass $student
     * @param int $termid
     * @return bool
     */
    public static function plan_ok(\stdClass $student, int $termid): bool {
        global $DB;
        return $DB->record_exists_sql("SELECT 1 FROM {" . self::STUPLANS . "} p JOIN {" . self::INSTS . "} i
                                         ON i.stuplanid = p.id AND i.seq = 0
                                      WHERE p.studentid = ? AND p.termid = ? AND p.status IN ('active', 'completed')
                                        AND i.status = 'paid'", [$student->id, $termid]);
    }

    /**
     * The next payment due of a student's active plans, if any.
     *
     * @param int $studentid
     * @return \stdClass|null with duedate, amount, paid
     */
    public static function next_due(int $studentid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_sql("SELECT i.* FROM {" . self::INSTS . "} i JOIN {" . self::STUPLANS . "} p ON p.id = i.stuplanid
                                       WHERE p.studentid = ? AND p.status = 'active' AND i.status <> 'paid'
                                    ORDER BY i.duedate, i.seq", [$studentid], 0, 1);
        return $rows ? reset($rows) : null;
    }

    // Daily work (scheduled task).

    /**
     * Send a reminder before each due date and handle late payments: a late fee (once per payment) and a
     * financial hold (once while one is in force); staff decide any drop.
     *
     * @return array counts: reminders, latefees, holds
     */
    public static function daily(): array {
        global $DB;
        $counts = ['reminders' => 0, 'latefees' => 0, 'holds' => 0];
        if (!billing::enabled()) {
            return $counts;
        }
        $p = 'local_studentrecords';
        $today = catalog::time_to_date(time());
        $days = (int)(catalog::setting('reminderdays') !== '' ? catalog::setting('reminderdays') : 7);
        $remindby = (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->modify('+' . max(0, $days) . ' days')
            ->format('Y-m-d');
        $plans = $DB->get_records(self::STUPLANS, ['status' => 'active']);
        foreach ($plans as $plan) {
            self::sync(students::get((int)$plan->studentid));
        }
        $latefee = $DB->get_records(billing::FEES, ['basis' => 'late', 'active' => 1], 'id', '*', 0, 1);
        $latefee = $latefee ? reset($latefee) : null;
        foreach ($DB->get_records(self::STUPLANS, ['status' => 'active']) as $plan) {
            $student = students::get((int)$plan->studentid);
            if (billing::balance((int)$student->id) <= 0.004) {
                // A refund or a reversal cleared the account, which does not count as paying the plan. Nothing is owed, so
                // there is nothing to remind about and nothing late.
                continue;
            }
            foreach ($DB->get_records(self::INSTS, ['stuplanid' => $plan->id], 'seq') as $inst) {
                $due = catalog::time_to_date((int)$inst->duedate);
                $owed = round((float)$inst->amount - (float)$inst->paid, 2);
                // Each step and its mark are saved together, so that a task that stops halfway does not do a step twice.
                if (
                    $days > 0 && $inst->status === 'due' && !$inst->reminded && $inst->seq > 0 && $due >= $today &&
                        $due <= $remindby
                ) {
                    billing::transaction(function () use ($DB, $inst, $student) {
                        $DB->set_field(self::INSTS, 'reminded', time(), ['id' => $inst->id]);
                        messages::student('paymentdue', $student);
                    });
                    $counts['reminders']++;
                }
                if ($inst->status !== 'late') {
                    continue;
                }
                if (!$inst->latefeeid && (float)$plan->latefee > 0) {
                    billing::transaction(function () use ($DB, $inst, $plan, $student, $latefee, $p) {
                        $lineid = billing::add_line(['studentid' => $student->id, 'txntype' => 'charge',
                            'amount' => (float)$plan->latefee, 'termid' => $plan->termid, 'planid' => $plan->id,
                            'feeid' => $latefee->id ?? null, 'qualified' => 0, 'refundable' => 0,
                            'description' => get_string('latefeedesc', $p, catalog::show_date((int)$inst->duedate))]);
                        $DB->set_field(self::INSTS, 'latefeeid', $lineid, ['id' => $inst->id]);
                    });
                    $counts['latefees']++;
                }
                if (!$inst->holdid && !self::billing_hold_in_force($plan)) {
                    billing::transaction(function () use ($DB, $inst, $plan, $student, $owed, $p) {
                        $holdid = academic::place_hold($student, ['holdtype' => 'financial', 'blocksreg' => 1,
                            'startdate' => time(), 'reason' => get_string('latehold', $p, (object)['plan' => $plan->name,
                            'date' => catalog::show_date((int)$inst->duedate), 'amount' => billing::money($owed)])]);
                        $DB->set_field(self::INSTS, 'holdid', $holdid, ['id' => $inst->id]);
                        messages::student('paymentlate', $student);
                        messages::staff('stafflate', $student, new \moodle_url(
                            '/local/studentrecords/account.php',
                            ['id' => $student->id]
                        ));
                    });
                    $counts['holds']++;
                }
            }
        }
        return $counts;
    }

    /**
     * Whether a hold placed for one of a plan's late payments is still in force.
     *
     * @param \stdClass $plan
     * @return bool
     */
    protected static function billing_hold_in_force(\stdClass $plan): bool {
        global $DB;
        $holdids = $DB->get_fieldset_select(self::INSTS, 'holdid', 'stuplanid = ? AND holdid IS NOT NULL', [$plan->id]);
        foreach ($holdids as $holdid) {
            $hold = $DB->get_record(academic::HOLDS, ['id' => $holdid]);
            if ($hold && academic::hold_active($hold, time())) {
                return true;
            }
        }
        return false;
    }
}
