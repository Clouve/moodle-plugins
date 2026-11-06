<?php

namespace local_studentrecords\local;

/**
 * Self-registration (a school setting, off by default): students pick classes for a term with open registration,
 * see the cost estimate and any time clash, and confirm. The same checks as staff registration apply (holds, seats,
 * prerequisites, add period); a full section offers its waitlist.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class selfreg {
    /** @var string Cart table. */
    const CART = 'local_studentrecords_cart';

    /** @var string[] Day codes in week order. */
    const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @var string[] Student statuses that may register themselves. Everyone else goes through the registrar's office. */
    const STATUSES = ['admitted', 'enrolled', 'active'];

    // Rules that need no database.

    /**
     * Clean a list of class days: known codes only, in week order, comma separated.
     *
     * @param string $days e.g. "thu, Tue"
     * @return string e.g. "tue,thu"
     */
    public static function clean_days(string $days): string {
        $given = array_map('trim', explode(',', strtolower($days)));
        return implode(',', array_values(array_filter(self::DAYS, fn($d) => in_array($d, $given, true))));
    }

    /**
     * Whether a time is HH:MM (24 hours).
     *
     * @param string $time
     * @return bool
     */
    public static function valid_time(string $time): bool {
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    /**
     * Whether two class times clash: a shared day and overlapping times. Classes without days or times never clash.
     *
     * @param string $daysa
     * @param string $starta HH:MM
     * @param string $enda HH:MM
     * @param string $daysb
     * @param string $startb
     * @param string $endb
     * @return bool
     */
    public static function times_clash(
        string $daysa,
        string $starta,
        string $enda,
        string $daysb,
        string $startb,
        string $endb
    ): bool {
        foreach ([$starta, $enda, $startb, $endb] as $t) {
            if (!self::valid_time($t)) {
                return false;
            }
        }
        $shared = array_intersect(explode(',', self::clean_days($daysa)), explode(',', self::clean_days($daysb)));
        if (!array_filter($shared)) {
            return false;
        }
        return $starta < $endb && $startb < $enda;
    }

    /**
     * Class days and time as text ("Tue, Thu 9:00-11:30"), "Online", or ''.
     *
     * @param \stdClass $offer with meetdays, starttime, endtime, room
     * @param bool $withroom
     * @return string
     */
    public static function meets_text(\stdClass $offer, bool $withroom = false): string {
        $days = self::clean_days((string)($offer->meetdays ?? ''));
        $room = trim((string)($offer->room ?? ''));
        if ($days === '') {
            return $room;
        }
        $names = array_map(fn($d) => get_string($d, 'calendar'), explode(',', $days));
        $text = implode(', ', $names);
        $start = (string)($offer->starttime ?? '');
        $end = (string)($offer->endtime ?? '');
        if (self::valid_time($start)) {
            $text .= ' ' . self::short_time($start) . (self::valid_time($end) ? '-' . self::short_time($end) : '');
        }
        if ($withroom && $room !== '') {
            $text .= ' · ' . $room;
        }
        return $text;
    }

    /**
     * "09:00" as "9:00".
     *
     * @param string $time
     * @return string
     */
    public static function short_time(string $time): string {
        return ltrim(substr($time, 0, 1), '0') . substr($time, 1);
    }

    /**
     * A confirmation number for a registration: term, student and time, e.g. SR-12-345-2Z4K.
     *
     * @param int $termid
     * @param int $studentid
     * @param int $time
     * @return string
     */
    public static function confirmation(int $termid, int $studentid, int $time): string {
        return 'SR-' . $termid . '-' . $studentid . '-' . strtoupper(base_convert((string)$time, 10, 36));
    }

    // Database.

    /**
     * Whether the school lets students register themselves.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return catalog::setting('selfreg') === '1';
    }

    /**
     * Whether this student's status lets them register themselves.
     *
     * @param \stdClass $student
     * @return bool
     */
    public static function status_allows(\stdClass $student): bool {
        return in_array($student->status, self::STATUSES, true);
    }

    /**
     * Why a student can't pick classes today, in words for the page, or null when they can.
     *
     * @param \stdClass $student
     * @return string|null
     */
    public static function closed_notice(\stdClass $student): ?string {
        if (!self::enabled()) {
            return get_string('myreg_off', 'local_studentrecords');
        }
        if (!self::status_allows($student)) {
            return get_string('err_selfregstatus', 'local_studentrecords', self::status_name($student));
        }
        return self::open_terms() ? null : get_string('myreg_noterms', 'local_studentrecords');
    }

    /**
     * Active terms with the add period open today.
     *
     * @return array id => term
     */
    public static function open_terms(): array {
        global $DB;
        $out = [];
        $today = catalog::time_to_date(time());
        foreach ($DB->get_records('local_studentrecords_term', ['active' => 1], 'startdate') as $t) {
            if (!registration::window_problem('add', $today, registration::term_days((int)$t->id))) {
                $out[$t->id] = $t;
            }
        }
        return $out;
    }

    /**
     * Offerings in the student's cart for a term.
     *
     * @param \stdClass $student
     * @param int $termid
     * @return array offer id => offer (registration::offer())
     */
    public static function cart(\stdClass $student, int $termid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(self::CART, ['studentid' => $student->id], 'timecreated, id') as $c) {
            $o = $DB->get_record(registration::OFFER, ['id' => $c->offerid]);
            if ($o && (int)$o->termid === $termid) {
                $out[(int)$o->id] = registration::offer((int)$o->id);
            }
        }
        return $out;
    }

    /**
     * The student's registered classes in a term.
     *
     * @param \stdClass $student
     * @param int $termid
     * @return array offer id => offer
     */
    public static function registered(\stdClass $student, int $termid): array {
        global $DB;
        $out = [];
        $sql = "SELECT r.offerid FROM {" . registration::REG . "} r JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                 WHERE r.studentid = ? AND o.termid = ? AND r.status = 'registered'";
        foreach ($DB->get_records_sql($sql, [$student->id, $termid]) as $r) {
            $out[(int)$r->offerid] = registration::offer((int)$r->offerid);
        }
        return $out;
    }

    /**
     * What a student has picked or waits for, in every term: classes in the cart and places on waitlists. A student who can't
     * pick classes today can still take these back.
     *
     * @param \stdClass $student
     * @return array cart: offers; waits: offer id => offer, with waitstatus
     */
    public static function pending(\stdClass $student): array {
        global $DB;
        $cart = [];
        foreach ($DB->get_records(self::CART, ['studentid' => $student->id], 'timecreated, id') as $c) {
            $cart[(int)$c->offerid] = registration::offer((int)$c->offerid);
        }
        $waits = [];
        foreach ($DB->get_records(waitlist::TABLE, ['studentid' => $student->id, 'kind' => 'section'], 'timecreated, id') as $w) {
            if (in_array($w->status, ['waiting', 'offered'], true)) {
                $waits[(int)$w->offerid] = registration::offer((int)$w->offerid);
                $waits[(int)$w->offerid]->waitstatus = $w->status;
            }
        }
        return ['cart' => $cart, 'waits' => $waits];
    }

    /**
     * Problems with adding one section to the cart (as registration would find them), not counting a full section.
     *
     * @param \stdClass $student
     * @param \stdClass $offer
     * @return array list of [language string key, a]
     */
    public static function problems(\stdClass $student, \stdClass $offer): array {
        return array_values(array_filter(registration::add_problems($student, $offer), fn($p) => $p[0] !== 'err_full'));
    }

    /**
     * Whether a section is full for this student (seats taken plus seats held for others).
     *
     * @param \stdClass $student
     * @param \stdClass $offer
     * @return bool
     */
    public static function is_full(\stdClass $student, \stdClass $offer): bool {
        return $offer->capacity !== null && registration::taken((int)$offer->id) +
            waitlist::held_seats((int)$offer->id, (int)$student->id) >= (int)$offer->capacity;
    }

    /**
     * Add a section to the cart.
     *
     * @param \stdClass $student
     * @param int $offerid
     */
    public static function add(\stdClass $student, int $offerid): void {
        global $DB;
        self::require_allowed($student);
        $offer = registration::offer($offerid);
        if (!isset(self::open_terms()[$offer->termid])) {
            throw new \moodle_exception('err_regclosed', 'local_studentrecords');
        }
        $problems = self::problems($student, $offer);
        if ($problems) {
            throw new \moodle_exception($problems[0][0], 'local_studentrecords', '', $problems[0][1]);
        }
        if (self::is_full($student, $offer)) {
            throw new \moodle_exception('err_full', 'local_studentrecords', '', (int)$offer->capacity);
        }
        if (!$DB->record_exists(self::CART, ['studentid' => $student->id, 'offerid' => $offerid])) {
            $DB->insert_record(self::CART, (object)['studentid' => $student->id, 'offerid' => $offerid, 'timecreated' => time()]);
        }
    }

    /**
     * Join the waitlist of a full section, by the same rules as adding it to the cart: self-registration on, the term open
     * and nothing in the way but the full section.
     *
     * @param \stdClass $student
     * @param int $offerid
     * @return int waitlist entry id
     */
    public static function wait(\stdClass $student, int $offerid): int {
        self::require_allowed($student);
        $offer = registration::offer($offerid);
        if (!isset(self::open_terms()[$offer->termid])) {
            throw new \moodle_exception('err_regclosed', 'local_studentrecords');
        }
        $problems = self::problems($student, $offer);
        if ($problems) {
            throw new \moodle_exception($problems[0][0], 'local_studentrecords', '', $problems[0][1]);
        }
        if (!self::is_full($student, $offer)) {
            throw new \moodle_exception('err_notfull', 'local_studentrecords');
        }
        return waitlist::join($student, $offerid);
    }

    /**
     * Take a section out of the cart.
     *
     * @param \stdClass $student
     * @param int $offerid
     */
    public static function remove(\stdClass $student, int $offerid): void {
        global $DB;
        $DB->delete_records(self::CART, ['studentid' => $student->id, 'offerid' => $offerid]);
    }

    /**
     * Time clashes among the cart and the registered classes of the term: pairs of [offer a, offer b].
     *
     * @param array $offers offers to check (cart and registered)
     * @return array
     */
    public static function clashes(array $offers): array {
        $out = [];
        $list = array_values($offers);
        for ($i = 0; $i < count($list); $i++) {
            for ($j = $i + 1; $j < count($list); $j++) {
                $a = $list[$i];
                $b = $list[$j];
                if (
                    self::times_clash(
                        (string)$a->meetdays,
                        (string)$a->starttime,
                        (string)$a->endtime,
                        (string)$b->meetdays,
                        (string)$b->starttime,
                        (string)$b->endtime
                    )
                ) {
                    $out[] = [$a, $b];
                }
            }
        }
        return $out;
    }

    /**
     * Register the student in every class of the cart (one by one: a class that fails stays in the cart with its reason).
     *
     * @param \stdClass $student
     * @param int $termid
     * @return array ['done' => offers registered, 'failed' => [offer, message], 'confirmation' => string]
     */
    public static function confirm(\stdClass $student, int $termid): array {
        self::require_allowed($student);
        if (!isset(self::open_terms()[$termid])) {
            throw new \moodle_exception('err_regclosed', 'local_studentrecords');
        }
        $cart = self::cart($student, $termid);
        $registered = self::registered($student, $termid);
        if (self::clashes($cart + $registered)) {
            throw new \moodle_exception('err_timeclash', 'local_studentrecords');
        }
        $done = [];
        $failed = [];
        foreach ($cart as $offer) {
            try {
                registration::add($student, (int)$offer->id);
                $done[] = $offer;
            } catch (\moodle_exception $e) {
                $failed[] = [$offer, $e->getMessage()];
            }
        }
        $confirmation = $done ? self::confirmation($termid, (int)$student->id, time()) : '';
        if ($done) {
            audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'self-registration ' . $confirmation .
                ': ' . implode(', ', array_map(fn($o) => $o->code . ' ' . $o->section, $done)));
        }
        return ['done' => $done, 'failed' => $failed, 'confirmation' => $confirmation];
    }

    /**
     * Stop when self-registration is off, or when this student's status doesn't allow it.
     *
     * @param \stdClass $student
     */
    public static function require_allowed(\stdClass $student): void {
        if (!self::enabled()) {
            throw new \moodle_exception('err_selfregoff', 'local_studentrecords');
        }
        if (!self::status_allows($student)) {
            throw new \moodle_exception('err_selfregstatus', 'local_studentrecords', '', self::status_name($student));
        }
    }

    /**
     * The name of a student's status.
     *
     * @param \stdClass $student
     * @return string
     */
    protected static function status_name(\stdClass $student): string {
        return get_string('status_' . $student->status, 'local_studentrecords');
    }
}
