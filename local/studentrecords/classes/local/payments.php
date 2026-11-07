<?php

namespace local_studentrecords\local;

/**
 * Payments: online payments through Moodle's payment system (any payment provider the school turns on), payments
 * staff record (check, wire, cash ...), receipts, and course access that waits for payment.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class payments {
    /** @var string Online payment requests table. */
    const REQ = 'local_studentrecords_payreq';

    /** @var string Payment area used with Moodle's payment system. */
    const AREA = 'account';

    /** @var string[] Ways staff can record a payment. */
    const METHODS = ['check', 'moneyorder', 'cash', 'card', 'banktransfer', 'sponsor', 'flywire', 'paymytuition', 'other'];

    /** @var string[] When a registered student gets into the Moodle course. */
    const ACCESS = ['downpayment', 'full', 'immediate'];

    // Rules that need no database.

    /**
     * Whether course access is allowed under a rule.
     *
     * @param string $rule downpayment (balance paid, or an approved plan with its down payment paid), full (balance
     *     paid) or immediate
     * @param float $balance account balance
     * @param bool $planok the student has an approved plan for the term whose down payment is paid
     * @return bool
     */
    public static function access_allowed(string $rule, float $balance, bool $planok): bool {
        if ($rule === 'immediate') {
            return true;
        }
        if ($balance <= 0.004) {
            return true;
        }
        return $rule === 'downpayment' && $planok;
    }

    /**
     * A receipt number, for example R2026-00001.
     *
     * @param string $year
     * @param int $seq
     * @return string
     */
    public static function receipt_number(string $year, int $seq): string {
        return 'R' . $year . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Whether an online payment amount is allowed: at least $1.00 and not more than the balance.
     *
     * @param float $amount
     * @param float $balance
     * @return bool
     */
    public static function amount_allowed(float $amount, float $balance): bool {
        return $amount >= 1 && $amount <= round($balance, 2) + 0.004;
    }

    // Settings.

    /**
     * The course access rule (setting; default: after the down payment or an approved plan).
     *
     * @return string
     */
    public static function access_rule(): string {
        $rule = catalog::setting('billaccess');
        return in_array($rule, self::ACCESS, true) ? $rule : 'downpayment';
    }

    /**
     * The Moodle payment account used for online payments, when set, available and not archived.
     *
     * @return \core_payment\account|null
     */
    public static function account(): ?\core_payment\account {
        $id = (int)catalog::setting('paymentaccount');
        if (!$id || !\core_payment\account::record_exists($id)) {
            return null;
        }
        $account = new \core_payment\account($id);
        return $account->is_available() && !$account->get('archived') ? $account : null;
    }

    /**
     * Whether students can pay online now (billing on and a usable payment account chosen).
     *
     * @return bool
     */
    public static function online_ready(): bool {
        return billing::enabled() && self::account() !== null;
    }

    // Course access.

    /**
     * Whether the student has an approved payment plan for the term with its down payment paid.
     *
     * @param \stdClass $student
     * @param int $termid
     * @return bool
     */
    public static function plan_ok(\stdClass $student, int $termid): bool {
        return plans::plan_ok($student, $termid);
    }

    /**
     * Whether a student's registrations in a term may get course access now.
     *
     * @param \stdClass $student
     * @param int $termid
     * @return bool
     */
    public static function access_ok(\stdClass $student, int $termid): bool {
        if (!billing::enabled()) {
            return true;
        }
        $rule = self::access_rule();
        return self::access_allowed($rule, billing::balance((int)$student->id), $rule === 'downpayment' &&
            self::plan_ok($student, $termid));
    }

    /**
     * Registrations whose course access waits for payment.
     *
     * @param int $studentid
     * @return array
     */
    public static function pending(int $studentid): array {
        global $DB;
        return $DB->get_records_sql("SELECT r.id, r.offerid, o.termid, o.moodlecourseid, c.code, o.section, t.name AS termname
                                       FROM {" . registration::REG . "} r
                                       JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                                       JOIN {local_studentrecords_course} c ON c.id = o.courseid
                                       JOIN {local_studentrecords_term} t ON t.id = o.termid
                                      WHERE r.studentid = ? AND r.accesspending = 1 AND r.status = 'registered'
                                   ORDER BY t.startdate, c.code", [$studentid]);
    }

    /**
     * Give course access for a registration that waits: enrol in the Moodle course and clear the wait. Two payments can get
     * here at once, so the wait is read under the lock on the registration: the second finds it gone and does nothing. Never
     * call this under the lock on the accounts, which comes after the lock on a registration.
     *
     * @param int $regid
     * @param string $note why (staff giving access before payment), or '' when the payment rule is met
     */
    public static function grant(int $regid, string $note = ''): void {
        registration::with_lock('reg' . $regid, function () use ($regid, $note) {
            global $DB;
            $reg = $DB->get_record(registration::REG, ['id' => $regid], '*', MUST_EXIST);
            if (!$reg->accesspending) {
                return;
            }
            $student = students::get((int)$reg->studentid);
            $offer = registration::offer((int)$reg->offerid);
            billing::transaction(function () use ($regid, $note, $reg, $student, $offer) {
                if ($reg->status === 'registered' && $offer->moodlecourseid) {
                    registration::enrol((int)$offer->moodlecourseid, (int)$student->userid);
                }
                records::save(
                    registration::REG,
                    ['id' => $regid, 'accesspending' => 0],
                    $note !== '' ? 'form' : 'billing',
                    (int)$student->userid
                );
                audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'course access ' . $offer->code .
                    ($note !== '' ? ' given by staff: ' . $note : ' (payment received)'));
                messages::student('accessopen', $student, ['course' => $offer->code, 'term' => $offer->termname]);
            });
        });
    }

    /**
     * Give course access to every waiting registration of a student whose term now meets the payment rule. Under the lock on
     * the accounts this happens when the lock ends. Each registration is tried on its own: one course that can't open stays
     * on the account page for staff, the audit log says why, and the others open. In a transaction a failure has already undone
     * that transaction, so it goes to the caller.
     *
     * @param \stdClass $student
     * @return int registrations given access now
     */
    public static function release(\stdClass $student): int {
        global $DB;
        if (billing::after_lock($student)) {
            return 0;
        }
        $n = 0;
        foreach (self::pending((int)$student->id) as $r) {
            if (!self::access_ok($student, (int)$r->termid)) {
                continue;
            }
            try {
                self::grant((int)$r->id);
                $n++;
            } catch (\Throwable $e) {
                if ($DB->is_transaction_started()) {
                    throw $e;
                }
                audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'course access after payment: ' .
                    $e->getMessage());
            }
        }
        return $n;
    }

    // Payments.

    /**
     * The next receipt number of this year (school time zone). Only for use inside billing::one_at_a_time(), or two payments
     * could get the same number.
     *
     * @return string
     */
    public static function next_receipt(): string {
        global $DB;
        $year = userdate(time(), '%Y', catalog::timezone(), false);
        $seq = $DB->count_records_select(billing::TXN, $DB->sql_like('receipt', '?'), ['R' . $year . '-%']) + 1;
        do {
            $no = self::receipt_number($year, $seq++);
        } while ($DB->record_exists(billing::TXN, ['receipt' => $no]));
        return $no;
    }

    /**
     * Record a payment (a line with a receipt number), then give course access that the payment allows.
     *
     * @param \stdClass $student
     * @param float $amount above zero
     * @param string $method one of METHODS, or online
     * @param array $extra txndate, reference, note, paymentid, termid
     * @return int account line id
     */
    public static function record(\stdClass $student, float $amount, string $method, array $extra = []): int {
        if ($amount <= 0) {
            throw new \moodle_exception('err_amountpositive', 'local_studentrecords');
        }
        $id = billing::one_at_a_time(fn() => self::add_payment($student, $amount, $method, $extra));
        self::settle($student);
        return $id;
    }

    /**
     * Write a payment line and tell the student.
     *
     * @param \stdClass $student
     * @param float $amount above zero
     * @param string $method one of METHODS, or online
     * @param array $extra txndate, reference, note, paymentid, termid
     * @return int account line id
     */
    protected static function add_payment(\stdClass $student, float $amount, string $method, array $extra): int {
        $p = 'local_studentrecords';
        $id = billing::insert_line(array_merge([
            'txndate' => time(), 'reference' => null, 'note' => null, 'paymentid' => null, 'termid' => null,
        ], array_intersect_key($extra, array_flip(['txndate', 'reference', 'note', 'paymentid', 'termid'])), [
            'studentid' => (int)$student->id, 'txntype' => 'payment', 'amount' => -round($amount, 2), 'method' => $method,
            'receipt' => self::next_receipt(),
            'description' => get_string('paymentdesc', $p, get_string('paymethod_' . $method, $p)),
        ]));
        messages::student('paymentreceived', $student);
        return $id;
    }

    /**
     * Bring plans and waiting course access up to date after a payment is saved. A payment is real money, so a problem
     * here (a Moodle course that was deleted, a course with no manual enrolment) must not undo it: it goes in the log,
     * and the course stays on the account page for staff to give access.
     *
     * @param \stdClass $student
     */
    public static function settle(\stdClass $student): void {
        try {
            plans::sync($student);
            self::release($student);
        } catch (\Throwable $e) {
            audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'course access after payment: ' .
                $e->getMessage());
        }
    }

    /**
     * Start an online payment: an open request for the amount.
     *
     * @param \stdClass $student
     * @param float $amount
     * @return int request id
     */
    public static function create_request(\stdClass $student, float $amount): int {
        global $DB, $USER;
        $account = self::account();
        if (!billing::enabled() || !$account) {
            throw new \moodle_exception('err_noonline', 'local_studentrecords');
        }
        if ((int)$student->userid !== (int)$USER->id) {
            throw new \moodle_exception('err_payownonly', 'local_studentrecords');
        }
        if (!self::amount_allowed(round($amount, 2), billing::balance((int)$student->id))) {
            throw new \moodle_exception('err_payamount', 'local_studentrecords');
        }
        return (int)$DB->insert_record(self::REQ, (object)['studentid' => $student->id, 'userid' => $USER->id,
            'amount' => round($amount, 2), 'accountid' => $account->get('id'), 'status' => 'open', 'timecreated' => time()]);
    }

    /**
     * The open payment request with this id. Moodle's payment system asks for it before it takes the money, so this is
     * where a request that may no longer be paid is stopped: one started by someone else, one already paid, or one for
     * more than the balance is now (a request can stay open for days while the balance changes). A provider calling
     * back has no one logged in; the payer of such a call is checked in deliver(). Scheduled tasks run as the admin.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function open_request(int $id): \stdClass {
        global $DB, $USER;
        $req = $DB->get_record(self::REQ, ['id' => $id], '*', MUST_EXIST);
        if (isloggedin() && (int)$USER->id !== (int)$req->userid && !is_siteadmin()) {
            throw new \moodle_exception('err_payownonly', 'local_studentrecords');
        }
        if (!billing::enabled()) {
            throw new \moodle_exception('err_noonline', 'local_studentrecords');
        }
        if ($req->status !== 'open' || !self::amount_allowed((float)$req->amount, billing::balance((int)$req->studentid))) {
            throw new \moodle_exception('err_paidalready', 'local_studentrecords');
        }
        return $req;
    }

    /**
     * Whether a Moodle payment is the payment of a request: for this request, by the student who started it, to the
     * account of the request, in dollars, and not less than the request. A gateway surcharge can make it more.
     *
     * @param \stdClass $req
     * @param \stdClass|null $payment row of Moodle's payments table
     * @param int $userid the user the payment system says paid
     * @return bool
     */
    public static function payment_fits(\stdClass $req, ?\stdClass $payment, int $userid): bool {
        return $payment !== null && $payment->component === 'local_studentrecords' && $payment->paymentarea === self::AREA &&
            (int)$payment->itemid === (int)$req->id && $userid === (int)$req->userid &&
            (int)$payment->userid === (int)$req->userid && (int)$payment->accountid === (int)$req->accountid &&
            $payment->currency === billing::CURRENCY &&
            billing::cents((float)$payment->amount) >= billing::cents((float)$req->amount);
    }

    /**
     * Moodle's payment system confirms a payment: record it on the account and mark the request paid. The amount comes from
     * the request, never from the caller, and a gateway surcharge stays out of it. One Moodle payment makes one account
     * line, however often it is delivered. A second, different payment of the same request (the student paid in two
     * tabs at once) is money the school did receive, so it gets its own line and Finance pays it back.
     *
     * @param int $reqid
     * @param int $paymentid Moodle payment id
     * @param int $userid the user who paid
     * @return bool false when the payment is not the payment of this request
     */
    public static function deliver(int $reqid, int $paymentid, int $userid): bool {
        global $DB;
        $req = $DB->get_record(self::REQ, ['id' => $reqid], '*', MUST_EXIST);
        $payment = $DB->get_record('payments', ['id' => $paymentid]) ?: null;
        $student = students::get((int)$req->studentid);
        if (!self::payment_fits($req, $payment, $userid)) {
            audit::log('update', 'account', (int)$student->id, (int)$student->userid, 'online payment ' . $paymentid .
                ' does not fit request ' . $reqid . ': not recorded');
            return false;
        }
        self::record_online($req, $payment, $student);
        return true;
    }

    /**
     * Put an online payment on the account, unless it is there. The check and the line are one step under the lock on the
     * accounts, so a delivery and the recovery can't both add it. A reversed payment keeps its first line, so it isn't added
     * again.
     *
     * @param \stdClass $req
     * @param \stdClass $payment row of Moodle's payments table that fits the request
     * @param \stdClass $student
     * @return bool whether a line was added
     */
    protected static function record_online(\stdClass $req, \stdClass $payment, \stdClass $student): bool {
        $added = billing::one_at_a_time(function () use ($req, $payment, $student) {
            global $DB;
            if ($DB->record_exists_select(billing::TXN, 'paymentid = ? AND reverses IS NULL', [$payment->id])) {
                return false;
            }
            $txnid = self::add_payment($student, (float)$req->amount, 'online', ['paymentid' => $payment->id,
                'txndate' => (int)$payment->timecreated ?: time(), 'reference' => \core_text::substr($payment->gateway . ' ' .
                get_string('paymentref', 'local_studentrecords', $payment->id), 0, 100)]);
            if ($DB->get_field(self::REQ, 'status', ['id' => $req->id]) === 'open') {
                $DB->update_record(self::REQ, (object)['id' => $req->id, 'status' => 'paid', 'paymentid' => $payment->id,
                    'txnid' => $txnid, 'timepaid' => time()]);
            } else {
                audit::log('update', 'account', (int)$student->id, (int)$student->userid, 'second online payment ' .
                    $payment->id . ' of request ' . $req->id);
            }
            return true;
        });
        if ($added) {
            self::settle($student);
        }
        return $added;
    }

    /**
     * Record the online payments that never reached the account. Moodle's payment system delivers a payment once, so a delivery
     * that stopped (the accounts were busy, the request died) is lost unless something looks for it. Each payment gets the
     * checks of a delivery. One that doesn't fit is said once in the audit log and stays off the account.
     *
     * Only payments from after the start time count, which install and upgrade set. An older payment with no line is no sign
     * of a lost delivery: Finance may have entered that money by hand, and such a line has no link to the payment. A site with
     * no start time gets one now, and this run records nothing.
     *
     * @return int payments recorded
     */
    public static function recover(): int {
        global $DB;
        $from = (int)catalog::setting('recoverfrom');
        if (!$from) {
            set_config('recoverfrom', time(), 'local_studentrecords');
            return 0;
        }
        $sql = "SELECT p.*
                  FROM {payments} p
                  JOIN {" . self::REQ . "} r ON r.id = p.itemid
             LEFT JOIN {" . billing::TXN . "} t ON t.paymentid = p.id AND t.reverses IS NULL
                 WHERE p.component = 'local_studentrecords' AND p.paymentarea = ? AND p.timecreated >= ? AND t.id IS NULL
              ORDER BY p.id";
        $n = 0;
        foreach ($DB->get_records_sql($sql, [self::AREA, $from]) as $payment) {
            try {
                $req = $DB->get_record(self::REQ, ['id' => $payment->itemid], '*', MUST_EXIST);
                $student = students::get((int)$req->studentid);
                $log = fn(string $info) => audit::log('update', 'account', (int)$student->id, (int)$student->userid, $info);
                $misfit = 'online payment ' . $payment->id . ' does not fit request ' . $req->id . ': not recorded';
                if (!self::payment_fits($req, $payment, (int)$payment->userid)) {
                    if (!$DB->record_exists('local_studentrecords_log', ['relateduserid' => $student->userid, 'info' => $misfit])) {
                        $log($misfit);
                    }
                } else if (self::record_online($req, $payment, $student)) {
                    $log('online payment ' . $payment->id . ' recovered: its delivery did not finish');
                    $n++;
                }
            } catch (\Throwable $e) {
                if ($e instanceof \moodle_exception && $e->errorcode === 'err_busy') {
                    // The next run tries again.
                    break;
                }
                mtrace('Online payment ' . $payment->id . ' not recorded: ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Receipt for a payment as a PDF.
     *
     * @param \stdClass $line the payment line
     * @return string PDF content
     */
    public static function receipt_pdf(\stdClass $line): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $p = 'local_studentrecords';
        $esc = fn($v) => s((string)$v);
        $student = students::get((int)$line->studentid);
        $pdf = new \pdf('P', 'mm', 'LETTER');
        $pdf->SetCreator(get_string('pluginname', $p));
        $pdf->SetTitle(get_string('receipt', $p) . ' ' . $line->receipt);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 18, 18);
        $pdf->AddPage();
        $logo = letters::logo_path();
        if ($logo) {
            $pdf->Image($logo, 18, 14, 0, 16);
            $pdf->SetY(34);
        }
        $pdf->writeHTML('<h2>' . $esc(catalog::setting('schoolname')) . '</h2><div>' . nl2br($esc(catalog::setting('address'))) .
            '</div>', true, false, true, false, '');
        $pdf->Ln(4);
        $rows = [
            get_string('receiptno', $p) => $line->receipt,
            get_string('date') => catalog::show_date((int)$line->txndate),
            get_string('receivedfrom', $p) => trim($student->legalfirstname . ' ' . $student->legallastname),
            get_string('studentid', $p) => $student->studentid,
            get_string('amountusd', $p) => billing::money(-(float)$line->amount),
            get_string('paymethod', $p) => get_string('paymethod_' . $line->method, $p),
            get_string('payreference', $p) => (string)$line->reference,
        ];
        $html = '<h3>' . get_string('receipt', $p) . '</h3><table cellpadding="4">';
        foreach ($rows as $k => $v) {
            if ((string)$v !== '') {
                $html .= '<tr><td width="35%"><b>' . $esc($k) . '</b></td><td width="65%">' . $esc($v) . '</td></tr>';
            }
        }
        $html .= '</table>';
        if ($line->reversedby) {
            $html .= '<p><b>' . get_string('receiptreversed', $p) . '</b></p>';
        }
        $html .= '<p>' . get_string('receiptthanks', $p) . '</p>';
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output($line->receipt . '.pdf', 'S');
    }
}
