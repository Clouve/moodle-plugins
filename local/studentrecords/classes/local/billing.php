<?php

namespace local_studentrecords\local;

/**
 * Billing: the school's price list, charges added when a student registers, and the student account. Account
 * lines are never changed or removed; a mistake is fixed by a reversing line. Positive amounts add to what the
 * student owes (charges, money paid back); negative amounts reduce it (payments, credits, refunds).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class billing {
    /** @var string Price list table. */
    const FEES = 'local_studentrecords_fee';

    /** @var string Account lines table. */
    const TXN = 'local_studentrecords_txn';

    /** @var string[] Kinds of price list items. */
    const KINDS = ['tuition', 'fee'];

    /** @var string[] What an item is charged per. */
    const BASES = ['credithour', 'clockhour', 'course', 'term', 'program', 'once', 'manual', 'late'];

    /** @var string[] Bases charged by themselves when a student registers. */
    const AUTO = ['credithour', 'clockhour', 'course', 'term', 'program', 'once'];

    /** @var array Per hour bases and the kind of course hours they are charged on. */
    const HOURLY = ['credithour' => 'credit', 'clockhour' => 'clock'];

    /** @var string[] Who an item applies to. */
    const SCOPES = ['all', 'level', 'program'];

    /** @var string[] Kinds of account lines. */
    const TYPES = ['charge', 'credit', 'payment', 'refund', 'payout'];

    /** @var string[] Kinds of credit staff can add. */
    const CREDITKINDS = ['scholarship', 'grant', 'waiver', 'discount', 'sponsor', 'adjustment'];

    /** @var string[] Kinds of credit of the federal aid module (disbursements; added from the aid award only). */
    const AIDKINDS = ['fedgrant', 'fedloan'];

    /** @var string The only currency (US dollars). */
    const CURRENCY = 'USD';

    /** @var float The largest amount the account tables can hold. */
    const MAXAMOUNT = 9999999999.99;

    /** @var string Shortname of the Finance role the setup page can create. */
    const FINANCEROLE = 'srfinance';

    /** @var string[] Capabilities of the Finance role. */
    const FINANCECAPS = ['local/studentrecords:viewbilling', 'local/studentrecords:managebilling',
        'local/studentrecords:approverefund'];

    // Rules that need no database.

    /**
     * An amount in US dollars, for example $1,500.00 or -$25.00.
     *
     * @param float $amount
     * @return string
     */
    public static function money(float $amount): string {
        $a = round($amount, 2);
        return ($a < 0 ? '-' : '') . '$' . number_format(abs($a), 2);
    }

    /**
     * An amount typed by staff: digits with at most 2 decimals, an optional $ and thousands commas. A comma anywhere else
     * is refused, so that 1,50 is not read as 150.00.
     *
     * @param string $text
     * @return float|null null when not a valid amount
     */
    public static function parse_amount(string $text): ?float {
        $t = preg_replace('/^\$\s*/', '', trim($text));
        if (!preg_match('/^(\d+|\d{1,3}(,\d{3})+)(\.\d{1,2})?$/', $t)) {
            return null;
        }
        $amount = round((float)str_replace(',', '', $t), 2);
        return $amount <= self::MAXAMOUNT ? $amount : null;
    }

    /**
     * An amount in whole cents.
     *
     * @param float $amount with at most 2 decimals
     * @return int
     */
    public static function cents(float $amount): int {
        return (int)round($amount * 100);
    }

    /**
     * Whole numbers divided and rounded to a whole number, a half going away from zero. There is no float in it, so the
     * result is exact and the same on every PHP version.
     *
     * @param int $number
     * @param int $by above zero
     * @return int
     */
    public static function divide(int $number, int $by): int {
        $quotient = intdiv(2 * abs($number) + $by, 2 * $by);
        return $number < 0 ? -$quotient : $quotient;
    }

    /**
     * An amount times a number of hours, or with $per = 100 a percent of an amount, to the cent: a half cent goes up.
     * round() can't do this, because a product such as 2.05 x 1.5 is held just under 3.075 and PHP 8.4 no longer
     * corrects that. Hours and percents have at most 2 decimals, as the database keeps them.
     *
     * @param float $amount
     * @param float $factor
     * @param int $per what the factor is out of
     * @return float
     */
    public static function times(float $amount, float $factor, int $per = 1): float {
        return self::divide(self::cents($amount) * self::cents($factor), 100 * $per) / 100;
    }

    /**
     * Whether a price list item applies to a student's program and level.
     *
     * @param \stdClass|array $fee
     * @param int|null $programid
     * @param int|null $levelid
     * @return bool
     */
    public static function applies($fee, ?int $programid, ?int $levelid): bool {
        $f = (array)$fee;
        if ($f['scope'] === 'program') {
            return $programid !== null && (int)$f['scopeid'] === $programid;
        }
        if ($f['scope'] === 'level') {
            return $levelid !== null && (int)$f['scopeid'] === $levelid;
        }
        return true;
    }

    /**
     * Whether an item can be charged on a course: a price per credit hour only on credit hour courses, a price per
     * clock hour only on clock hour courses; other items on any course.
     *
     * @param \stdClass|array $fee
     * @param string|null $unittype the course's hours: credit or clock (null = not known: hourly prices do not apply)
     * @return bool
     */
    public static function fits_unit($fee, ?string $unittype): bool {
        $basis = ((array)$fee)['basis'];
        return !isset(self::HOURLY[$basis]) || self::HOURLY[$basis] === $unittype;
    }

    /**
     * The tuition item for a program and level and a course's kind of hours: the most specific active item charged
     * when registering (program before level before all) that fits the course's hours; on a tie the oldest item.
     *
     * @param array $fees price list items
     * @param int|null $programid
     * @param int|null $levelid
     * @param string|null $unittype the course's hours: credit or clock
     * @return \stdClass|null
     */
    public static function pick_tuition(array $fees, ?int $programid, ?int $levelid, ?string $unittype): ?\stdClass {
        $rank = ['program' => 2, 'level' => 1, 'all' => 0];
        $best = null;
        foreach ($fees as $fee) {
            $fee = (object)$fee;
            if (
                $fee->kind !== 'tuition' || empty($fee->active) || !in_array($fee->basis, self::AUTO, true) ||
                    !self::applies($fee, $programid, $levelid) || !self::fits_unit($fee, $unittype)
            ) {
                continue;
            }
            if (
                $best === null || $rank[$fee->scope] > $rank[$best->scope] ||
                    ($rank[$fee->scope] === $rank[$best->scope] && (int)$fee->id < (int)$best->id)
            ) {
                $best = $fee;
            }
        }
        return $best;
    }

    /**
     * The fees (not tuition) charged by themselves when a student of a program and level registers for a course.
     *
     * @param array $fees price list items
     * @param int|null $programid
     * @param int|null $levelid
     * @param string|null $unittype the course's hours: credit or clock
     * @return \stdClass[]
     */
    public static function auto_fees(array $fees, ?int $programid, ?int $levelid, ?string $unittype): array {
        $out = [];
        foreach ($fees as $fee) {
            $fee = (object)$fee;
            if (
                $fee->kind === 'fee' && !empty($fee->active) && in_array($fee->basis, self::AUTO, true) &&
                    self::applies($fee, $programid, $levelid) && self::fits_unit($fee, $unittype)
            ) {
                $out[] = $fee;
            }
        }
        return $out;
    }

    /**
     * The amount of one charge: per credit or clock hour items times the course hours, others the item amount.
     *
     * @param \stdClass|array $fee
     * @param float $hours course hours (credit or clock)
     * @return float
     */
    public static function line_amount($fee, float $hours): float {
        $f = (array)$fee;
        return isset(self::HOURLY[$f['basis']]) ? self::times((float)$f['amount'], $hours) : round((float)$f['amount'], 2);
    }

    /**
     * Account lines in date order with the running balance after each line.
     *
     * @param array $lines with amount, txndate, id
     * @return array the same lines (objects) with running
     */
    public static function with_running(array $lines): array {
        $lines = array_map(fn($l) => (object)(array)$l, array_values($lines));
        usort($lines, fn($a, $b) => [(int)$a->txndate, (int)$a->id] <=> [(int)$b->txndate, (int)$b->id]);
        $running = 0.0;
        foreach ($lines as $l) {
            $running = round($running + (float)$l->amount, 2);
            $l->running = $running;
        }
        return $lines;
    }

    // Settings and price list.

    /**
     * Whether billing is switched on in setup.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return catalog::setting('billing') === '1';
    }

    /**
     * Price list items.
     *
     * @param bool $activeonly
     * @return array id => item
     */
    public static function fees(bool $activeonly = false): array {
        global $DB;
        return $DB->get_records(self::FEES, $activeonly ? ['active' => 1] : null, 'kind DESC, name, id');
    }

    /**
     * Who a price list item applies to, for lists.
     *
     * @param \stdClass $fee
     * @return string
     */
    public static function scope_text(\stdClass $fee): string {
        global $DB;
        if ($fee->scope === 'program') {
            $code = $DB->get_field('local_studentrecords_program', 'code', ['id' => $fee->scopeid]);
            return get_string('scope_program', 'local_studentrecords') . ': ' . s((string)$code);
        }
        if ($fee->scope === 'level') {
            $name = $DB->get_field('local_studentrecords_level', 'name', ['id' => $fee->scopeid]);
            return get_string('scope_level', 'local_studentrecords') . ': ' . format_string((string)$name);
        }
        return get_string('scope_all', 'local_studentrecords');
    }

    /**
     * Add or change a price list item (change history kept). A changed price applies to new charges only.
     *
     * @param array $data
     * @return int id
     */
    public static function save_fee(array $data): int {
        $record = ['name' => trim($data['name']), 'kind' => $data['kind'], 'basis' => $data['basis'],
            'amount' => round((float)$data['amount'], 2), 'scope' => $data['scope'],
            'scopeid' => $data['scope'] === 'all' ? null : (int)$data['scopeid'],
            'refundable' => empty($data['refundable']) ? 0 : 1, 'qualified' => empty($data['qualified']) ? 0 : 1,
            'active' => isset($data['active']) ? (empty($data['active']) ? 0 : 1) : 1,
            'note' => trim((string)($data['note'] ?? ''))];
        if (!empty($data['id'])) {
            $record['id'] = (int)$data['id'];
        }
        $id = records::save(self::FEES, $record);
        audit::log(empty($data['id']) ? 'create' : 'update', 'fee', $id, null, $record['name']);
        return $id;
    }

    // Account lines.

    /**
     * Run work in one database transaction: all of it is saved, or none of it.
     *
     * @param callable $work
     * @return mixed what the work returns
     */
    public static function transaction(callable $work) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $result = $work();
            $transaction->allow_commit();
            return $result;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Run work that reads an account and then writes it, one at a time, in one transaction: two people or two payment
     * callbacks can't both see the same balance, request or line and both act on it. Don't call this from inside a
     * transaction that was started some other way: the lock must outlast the commit.
     *
     * @param callable $work
     * @return mixed what the work returns
     */
    public static function one_at_a_time(callable $work) {
        return registration::with_lock('accounts', fn() => self::transaction($work));
    }

    /**
     * Add an account line, then bring plans and waiting course access up to date.
     *
     * @param array $data see insert_line()
     * @return int id
     */
    public static function add_line(array $data): int {
        return self::transaction(function () use ($data) {
            $id = self::insert_line($data);
            $student = students::get((int)$data['studentid']);
            $lowered = (float)$data['amount'] < 0;
            // A returned payment counts back on a plan as much as a new payment counts toward it.
            if ($lowered || in_array($data['txntype'], ['payment', 'credit'], true)) {
                plans::sync($student);
            }
            if ($lowered) {
                payments::release($student);
            }
            return $id;
        });
    }

    /**
     * Write an account line and nothing else.
     *
     * @param array $data studentid, txntype, amount (signed), description; optional txndate, termid, regid, feeid,
     *     method, receipt, reference, qualified, refundable, planid, refundid, paymentid, note, reverses
     * @return int id
     */
    public static function insert_line(array $data): int {
        global $DB, $USER;
        if (!in_array($data['txntype'], self::TYPES, true)) {
            throw new \coding_exception('Unknown account line type ' . $data['txntype']);
        }
        $student = students::get((int)$data['studentid']);
        $record = (object)array_merge([
            'txndate' => time(), 'termid' => null, 'regid' => null, 'feeid' => null, 'method' => null, 'receipt' => null,
            'reference' => null, 'qualified' => 0, 'refundable' => 1, 'reverses' => null, 'reversedby' => null,
            'planid' => null, 'refundid' => null, 'chargeid' => null, 'aidid' => null, 'paymentid' => null, 'note' => null,
        ], $data, [
            'amount' => round((float)$data['amount'], 2),
            'description' => \core_text::substr(trim($data['description']), 0, 255),
            'userid' => $USER->id ?? 0, 'timecreated' => time(),
        ]);
        $id = (int)$DB->insert_record(self::TXN, $record);
        audit::log('create', 'account', (int)$student->id, (int)$student->userid, $record->txntype . ' ' .
            self::money((float)$record->amount) . ' ' . $record->description);
        return $id;
    }

    /**
     * Reverse an account line with a reason: a new line with the opposite amount; the first line is marked as
     * reversed (that mark is kept in the change history).
     *
     * @param int $txnid
     * @param string $reason
     * @return int id of the reversing line
     */
    public static function reverse(int $txnid, string $reason): int {
        return self::one_at_a_time(function () use ($txnid, $reason) {
            global $DB;
            $line = $DB->get_record(self::TXN, ['id' => $txnid], '*', MUST_EXIST);
            if ($line->reversedby || $line->reverses) {
                throw new \moodle_exception('err_cannotreverse', 'local_studentrecords');
            }
            if (trim($reason) === '') {
                throw new \moodle_exception('err_reasonneeded', 'local_studentrecords');
            }
            $refunded = (float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0) FROM {" . self::TXN . "}
                                                    WHERE txntype = 'refund' AND chargeid = ?", [$line->id]);
            if (abs($refunded) > 0.004) {
                // The refund and the reversal would both give back the same money.
                throw new \moodle_exception('err_chargerefunded', 'local_studentrecords');
            }
            $data = (array)$line;
            unset($data['id'], $data['userid'], $data['timecreated'], $data['reversedby'], $data['receipt']);
            $id = self::add_line(array_merge($data, [
                'amount' => -(float)$line->amount, 'txndate' => time(), 'reverses' => $line->id, 'note' => trim($reason),
                'description' => get_string('reversaldesc', 'local_studentrecords', (object)['id' => $line->id,
                    'description' => $line->description]),
            ]));
            $DB->set_field(self::TXN, 'reversedby', $id, ['id' => $line->id]);
            $student = students::get((int)$line->studentid);
            records::history(self::TXN, (int)$line->id, 'reversedby', null, $id, 'form', (int)$student->userid);
            return $id;
        });
    }

    /**
     * Balance of a student account: more than zero = the student owes; less than zero = the school owes.
     *
     * @param int $studentid
     * @return float
     */
    public static function balance(int $studentid): float {
        global $DB;
        return round((float)$DB->get_field_sql(
            "SELECT COALESCE(SUM(amount), 0) FROM {" . self::TXN . "} WHERE studentid = ?",
            [$studentid]
        ), 2);
    }

    /**
     * Account lines of a student with the running balance, oldest first.
     *
     * @param int $studentid
     * @param int $termid 0 = all
     * @return array
     */
    public static function lines(int $studentid, int $termid = 0): array {
        global $DB;
        $params = ['studentid' => $studentid];
        if ($termid) {
            $params['termid'] = $termid;
        }
        return self::with_running($DB->get_records(self::TXN, $params));
    }

    /**
     * Net amount already on the account for a price list item (charges less reversals and refunds).
     *
     * @param int $studentid
     * @param int $feeid
     * @param array $where more conditions: termid, regid
     * @return float
     */
    protected static function net_charged(int $studentid, int $feeid, array $where = []): float {
        global $DB;
        $conditions = ['studentid = ?', 'feeid = ?', "txntype IN ('charge', 'refund')"];
        $params = [$studentid, $feeid];
        foreach ($where as $field => $value) {
            $conditions[] = "$field = ?";
            $params[] = $value;
        }
        return round((float)$DB->get_field_sql("SELECT COALESCE(SUM(amount), 0) FROM {" . self::TXN . "} WHERE " .
            implode(' AND ', $conditions), $params), 2);
    }

    /**
     * The charges a registration brings that are not on the account yet: tuition (per hour, per course, once per
     * term or once per program) and the fees that apply (per hour or course, once per term, once ever). Prices per credit
     * hour apply to credit hour courses only, prices per clock hour to clock hour courses only.
     *
     * @param \stdClass $student
     * @param int $regid
     * @return array lines ready for add_line
     */
    public static function registration_charges(\stdClass $student, int $regid): array {
        global $DB;
        $reg = $DB->get_record(registration::REG, ['id' => $regid], '*', MUST_EXIST);
        $offer = registration::offer((int)$reg->offerid);
        $programid = $student->programid ? (int)$student->programid : null;
        $levelid = $programid ? ((int)$DB->get_field('local_studentrecords_program', 'levelid', ['id' => $programid]) ?: null) :
            null;
        $fees = self::fees(true);
        $items = self::auto_fees($fees, $programid, $levelid, $offer->unittype ?: null);
        $tuition = self::pick_tuition($fees, $programid, $levelid, $offer->unittype ?: null);
        if ($tuition) {
            array_unshift($items, $tuition);
        }
        $p = 'local_studentrecords';
        $out = [];
        foreach ($items as $fee) {
            $perreg = isset(self::HOURLY[$fee->basis]) || $fee->basis === 'course';
            $where = $perreg ? ['regid' => $regid] : ($fee->basis === 'term' ? ['termid' => (int)$offer->termid] : []);
            if (self::net_charged((int)$student->id, (int)$fee->id, $where) > 0) {
                continue;
            }
            $amount = self::line_amount($fee, (float)$offer->hours);
            if ($amount <= 0) {
                continue;
            }
            $a = (object)['fee' => $fee->name, 'code' => $offer->code, 'term' => $offer->termname,
                'hours' => format_float((float)$offer->hours, -1), 'rate' => self::money((float)$fee->amount)];
            $out[] = ['studentid' => (int)$student->id, 'txntype' => 'charge', 'amount' => $amount,
                'description' => get_string('chargedesc_' . $fee->basis, $p, $a), 'termid' => (int)$offer->termid,
                'regid' => $perreg ? $regid : null, 'feeid' => (int)$fee->id,
                'qualified' => (int)$fee->qualified, 'refundable' => (int)$fee->refundable];
        }
        return $out;
    }

    /**
     * Cost estimate for classes a student has not registered in yet (self-registration cart): the same prices as
     * real registration charges; per-term and one-time items already charged (or already in this estimate) count once.
     * Hourly items of the same price are added up ("Tuition, 7 credits x $300.00").
     *
     * @param \stdClass $student
     * @param array $offers offerings from registration::offer()
     * @return array ['lines' => [['label' => plain text, 'amount' => float]], 'total' => float]
     */
    public static function estimate(\stdClass $student, array $offers): array {
        global $DB;
        $programid = $student->programid ? (int)$student->programid : null;
        $levelid = $programid ? ((int)$DB->get_field('local_studentrecords_program', 'levelid', ['id' => $programid]) ?: null) :
            null;
        $fees = self::fees(true);
        $p = 'local_studentrecords';
        $lines = [];
        $once = [];
        foreach ($offers as $offer) {
            $items = self::auto_fees($fees, $programid, $levelid, $offer->unittype ?: null);
            $tuition = self::pick_tuition($fees, $programid, $levelid, $offer->unittype ?: null);
            if ($tuition) {
                array_unshift($items, $tuition);
            }
            foreach ($items as $fee) {
                $hourly = isset(self::HOURLY[$fee->basis]);
                if (!$hourly && $fee->basis !== 'course') {
                    $key = $fee->id . '|' . ($fee->basis === 'term' ? (int)$offer->termid : 0);
                    $where = $fee->basis === 'term' ? ['termid' => (int)$offer->termid] : [];
                    if (isset($once[$key]) || self::net_charged((int)$student->id, (int)$fee->id, $where) > 0) {
                        continue;
                    }
                    $once[$key] = true;
                }
                $amount = self::line_amount($fee, (float)$offer->hours);
                if ($amount <= 0) {
                    continue;
                }
                if ($hourly) {
                    $key = 'h' . $fee->id;
                    $lines[$key] = $lines[$key] ?? ['fee' => $fee, 'hours' => 0.0, 'amount' => 0.0];
                    $lines[$key]['hours'] += (float)$offer->hours;
                    $lines[$key]['amount'] = round($lines[$key]['amount'] + $amount, 2);
                } else {
                    $lines['f' . $fee->id . '|' . $offer->id] = ['fee' => $fee, 'code' => $fee->basis === 'course' ?
                        $offer->code : '', 'amount' => $amount];
                }
            }
        }
        $out = [];
        $total = 0.0;
        foreach ($lines as $l) {
            if (isset($l['hours'])) {
                $label = get_string('estimate_hourly', $p, (object)['fee' => $l['fee']->name,
                    'hours' => format_float($l['hours'], -1),
                    'unit' => get_string('estimate_' . self::HOURLY[$l['fee']->basis], $p),
                    'rate' => self::money((float)$l['fee']->amount)]);
            } else {
                $label = $l['fee']->name . ($l['code'] !== '' ? ', ' . $l['code'] : '');
            }
            $out[] = ['label' => $label, 'amount' => $l['amount']];
            $total = round($total + $l['amount'], 2);
        }
        return ['lines' => $out, 'total' => $total];
    }

    /**
     * Add the charges of a registration (when billing is on).
     *
     * @param \stdClass $student
     * @param int $regid
     * @return int number of lines added
     */
    public static function charge_registration(\stdClass $student, int $regid): int {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (self::registration_charges($student, $regid) as $line) {
            self::add_line($line);
            $n++;
        }
        return $n;
    }

    /**
     * Add the missing charges of every registration in a term (for registrations made before billing was on).
     *
     * @param int $termid
     * @return int number of lines added
     */
    public static function charge_term(int $termid): int {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(registration::ACTIVE);
        $sql = "SELECT r.id, r.studentid FROM {" . registration::REG . "} r JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                 WHERE o.termid = ? AND r.status $in ORDER BY r.id";
        $n = 0;
        foreach ($DB->get_records_sql($sql, array_merge([$termid], $params)) as $r) {
            $n += self::charge_registration(students::get((int)$r->studentid), (int)$r->id);
        }
        return $n;
    }

    /**
     * Student accounts with their balance, for the Finance list.
     *
     * @param string $search name or student ID
     * @param string $filter all, owing or credit
     * @return array
     */
    public static function accounts(string $search = '', string $filter = 'all'): array {
        global $DB;
        $where = [];
        $params = [];
        if ($search !== '') {
            $like = [];
            foreach (['s.studentid', 's.legalfirstname', 's.legallastname', 's.preferredname'] as $i => $f) {
                $like[] = $DB->sql_like($f, ':q' . $i, false);
                $params['q' . $i] = '%' . $DB->sql_like_escape($search) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }
        $sql = "SELECT s.id, s.userid, s.studentid, s.legalfirstname, s.legalmiddlename, s.legallastname, s.preferredname,
                       s.status, s.programid, COALESCE(b.balance, 0) AS balance,
                       COALESCE(b.numlines, 0) AS numlines
                  FROM {local_studentrecords_student} s
             LEFT JOIN (SELECT studentid, SUM(amount) AS balance, COUNT(1) AS numlines FROM {" . self::TXN . "}
                         GROUP BY studentid) b ON b.studentid = s.id " .
            ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
              ORDER BY s.legallastname, s.legalfirstname, s.id";
        $rows = $DB->get_records_sql($sql, $params);
        return array_filter($rows, fn($r) => $filter === 'owing' ? (float)$r->balance > 0.004 :
            ($filter === 'credit' ? (float)$r->balance < -0.004 : true));
    }

    /**
     * Whether the current user may see a student's account: Finance staff, or the student.
     *
     * @param \stdClass $student
     * @return bool
     */
    public static function can_view(\stdClass $student): bool {
        global $USER;
        $sys = \context_system::instance();
        if (has_capability('local/studentrecords:viewbilling', $sys)) {
            return true;
        }
        return $student->userid && (int)$student->userid === (int)$USER->id &&
            has_capability('local/studentrecords:viewown', $sys);
    }

    /**
     * Account statement as a PDF: school, student, lines with running balance, balance, how to pay.
     *
     * @param \stdClass $student
     * @param int $termid 0 = all lines
     * @return string PDF content
     */
    public static function statement_pdf(\stdClass $student, int $termid = 0): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $p = 'local_studentrecords';
        $esc = fn($v) => s((string)$v);
        $pdf = new \pdf('P', 'mm', 'LETTER');
        $pdf->SetCreator(get_string('pluginname', $p));
        $pdf->SetTitle(get_string('statement', $p) . ' ' . $student->studentid);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 18, 18);
        $pdf->AddPage();
        $logo = letters::logo_path();
        if ($logo) {
            $pdf->Image($logo, 18, 14, 0, 16);
            $pdf->SetY(34);
        }
        $school = catalog::setting('schoolname');
        $pdf->writeHTML('<h2>' . $esc($school) . '</h2><div>' . nl2br($esc(catalog::setting('address'))) . '</div><div>' .
            $esc(trim(catalog::setting('phone') . '  ' . catalog::setting('email'))) . '</div>', true, false, true, false, '');
        $pdf->Ln(4);
        $terms = catalog::terms();
        $name = trim($student->legalfirstname . ' ' . $student->legallastname);
        $head = '<h3>' . get_string('statement', $p) . '</h3><p>' . get_string('statementdate', $p) . ': ' .
            $esc(catalog::show_date(time())) . '<br>' . $esc($name) . '<br>' . get_string('studentid', $p) . ': ' .
            $esc($student->studentid) . ($termid ? '<br>' . get_string('term', $p) . ': ' . format_string($terms[$termid] ?? '') :
            '') . '</p>';
        $pdf->writeHTML($head, true, false, true, false, '');
        $rows = '<table cellpadding="3" border="0"><tr style="font-weight:bold;background-color:#eeeeee"><td width="16%">' .
            get_string('date') . '</td><td width="44%">' . get_string('description') . '</td><td width="12%">' .
            get_string('receiptno', $p) . '</td><td width="14%" align="right">' . get_string('amountusd', $p) .
            '</td><td width="14%" align="right">' . get_string('runningbalance', $p) . '</td></tr>';
        $lines = self::lines((int)$student->id, $termid);
        foreach ($lines as $l) {
            $rows .= '<tr><td>' . $esc(catalog::show_date((int)$l->txndate)) . '</td><td>' . $esc($l->description) .
                '</td><td>' . $esc($l->receipt) . '</td><td align="right">' . self::money((float)$l->amount) .
                '</td><td align="right">' . self::money((float)$l->running) . '</td></tr>';
        }
        $rows .= '</table>';
        $pdf->writeHTML($lines ? $rows : '<p>' . get_string('nolines', $p) . '</p>', true, false, true, false, '');
        $balance = self::balance((int)$student->id);
        $state = $balance > 0.004 ? 'balancedue' : ($balance < -0.004 ? 'balancecredit' : 'balancezero');
        $foot = '<p><b>' . get_string($state, $p, self::money(abs($balance))) . '</b>' .
            ($termid ? '<br>' . get_string('termfilternote', $p) : '') . '</p>';
        foreach (['payinfo', 'statementnote'] as $setting) {
            if (catalog::setting($setting) !== '') {
                $foot .= '<p>' . nl2br($esc(catalog::setting($setting))) . '</p>';
            }
        }
        $pdf->writeHTML($foot, true, false, true, false, '');
        return $pdf->Output('statement.pdf', 'S');
    }

    /**
     * Create the Finance role (system level, the three billing capabilities) if it does not exist.
     *
     * @return int role id
     */
    public static function create_finance_role(): int {
        global $DB;
        $existing = $DB->get_field('role', 'id', ['shortname' => self::FINANCEROLE]);
        if ($existing) {
            return (int)$existing;
        }
        $p = 'local_studentrecords';
        $roleid = create_role(get_string('financerole', $p), self::FINANCEROLE, get_string('financerole_desc', $p));
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        $sys = \context_system::instance();
        foreach (self::FINANCECAPS as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $sys->id);
        }
        $sys->mark_dirty();
        audit::log('create', 'setup', $roleid, null, 'Finance role');
        return $roleid;
    }
}
