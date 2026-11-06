<?php

namespace local_studentrecords\local;

/**
 * Waitlists for full sections and for program places. When a seat opens, the first student waiting is offered it by
 * message and the seat is held for them for a few days; then it goes to the next one.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class waitlist {
    /** @var string */
    const TABLE = 'local_studentrecords_wait';

    /** @var int Days an offered seat is held. */
    const HOLDDAYS = 2;

    /** @var string[] Statuses. */
    const STATUSES = ['waiting', 'offered', 'done', 'left'];

    /**
     * Whether an offer of a seat is still held at a time.
     *
     * @param int $timeoffered
     * @param int $now
     * @return bool
     */
    public static function offer_held(int $timeoffered, int $now): bool {
        return $timeoffered > 0 && $now < $timeoffered + self::HOLDDAYS * DAYSECS;
    }

    /**
     * The student's open waitlist entry for a section, if any.
     *
     * @param int $studentid
     * @param int $offerid
     * @return \stdClass|null
     */
    public static function entry(int $studentid, int $offerid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_select(self::TABLE, "kind = 'section' AND studentid = ? AND offerid = ? AND status IN
            ('waiting', 'offered')", [$studentid, $offerid], 'id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * People waiting for a section, in order (waiting and offered).
     *
     * @param int $offerid
     * @return array
     */
    public static function for_offer(int $offerid): array {
        global $DB;
        return $DB->get_records_select(
            self::TABLE,
            "kind = 'section' AND offerid = ? AND status IN ('waiting', 'offered')",
            [$offerid],
            'timecreated, id'
        );
    }

    /**
     * Place of a student on a section's waitlist (1 = first), or 0.
     *
     * @param int $studentid
     * @param int $offerid
     * @return int
     */
    public static function position(int $studentid, int $offerid): int {
        $i = 0;
        foreach (self::for_offer($offerid) as $w) {
            $i++;
            if ((int)$w->studentid === $studentid) {
                return $i;
            }
        }
        return 0;
    }

    /**
     * Seats held for other students who were offered one (offer still held).
     *
     * @param int $offerid
     * @param int $exceptstudentid
     * @return int
     */
    public static function held_seats(int $offerid, int $exceptstudentid = 0): int {
        global $DB;
        $n = 0;
        foreach ($DB->get_records(self::TABLE, ['kind' => 'section', 'offerid' => $offerid, 'status' => 'offered']) as $w) {
            if ((int)$w->studentid !== $exceptstudentid && self::offer_held((int)$w->timeoffered, time())) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Join a full section's waitlist.
     *
     * @param \stdClass $student
     * @param int $offerid
     * @return int waitlist entry id
     */
    public static function join(\stdClass $student, int $offerid): int {
        return registration::with_lock('offer' . $offerid, fn() => self::add_entry($student, $offerid));
    }

    /**
     * Add a student to a section's waitlist. The caller holds the lock on the offering.
     *
     * @param \stdClass $student
     * @param int $offerid
     * @return int waitlist entry id
     */
    protected static function add_entry(\stdClass $student, int $offerid): int {
        global $DB;
        $offer = registration::offer($offerid);
        if ($existing = self::entry((int)$student->id, $offerid)) {
            return (int)$existing->id;
        }
        if ($offer->status !== 'open' || $offer->capacity === null) {
            throw new \moodle_exception(
                'err_offernotopen',
                'local_studentrecords',
                '',
                get_string('offerstatus_' . $offer->status, 'local_studentrecords')
            );
        }
        $reg = $DB->get_record(registration::REG, ['studentid' => $student->id, 'offerid' => $offerid]);
        if ($reg && in_array($reg->status, registration::ACTIVE, true)) {
            throw new \moodle_exception('err_alreadyregistered', 'local_studentrecords');
        }
        $id = records::save(self::TABLE, ['kind' => 'section', 'offerid' => $offerid, 'studentid' => $student->id,
            'status' => 'waiting', 'timecreated' => time()], 'form', (int)$student->userid);
        audit::log('update', 'student', (int)$student->id, (int)$student->userid, 'waitlist ' . $offer->code . ' ' .
            $offer->section);
        return $id;
    }

    /**
     * Leave a waitlist (by the student or staff).
     *
     * @param int $waitid
     */
    public static function leave(int $waitid): void {
        global $DB;
        $w = $DB->get_record(self::TABLE, ['id' => $waitid], '*', MUST_EXIST);
        $student = students::get((int)$w->studentid);
        records::save(self::TABLE, ['id' => $w->id, 'status' => 'left', 'timeended' => time()], 'form', (int)$student->userid);
        if ($w->status === 'offered' && $w->offerid) {
            self::seat_opened((int)$w->offerid);
        }
    }

    /**
     * A student registered: their entry for that section is done.
     *
     * @param int $studentid
     * @param int $offerid
     */
    public static function registered(int $studentid, int $offerid): void {
        $w = self::entry($studentid, $offerid);
        if ($w) {
            records::save(self::TABLE, ['id' => $w->id, 'status' => 'done', 'timeended' => time()], 'registration', null);
        }
    }

    /**
     * Seats opened in a section (a drop, a withdrawal, more seats, a lapsed offer): offer them to the next students
     * waiting, by message, while seats are free. After the add period nobody could take a seat, so none is offered. An offer
     * is for a student to take up, so with self-registration off none is made, and a student who couldn't take it is passed
     * over and stays on the list.
     *
     * @param int $offerid
     * @return int students offered a seat
     */
    public static function seat_opened(int $offerid): int {
        return registration::with_lock('offer' . $offerid, fn() => self::offer_seats($offerid));
    }

    /**
     * Offer the free seats of a section. The caller holds the lock on the offering.
     *
     * @param int $offerid
     * @return int students offered a seat
     */
    protected static function offer_seats(int $offerid): int {
        $offer = registration::offer($offerid);
        if (
            !selfreg::enabled() || $offer->status !== 'open' ||
                registration::window_problem('add', catalog::time_to_date(time()), registration::term_days((int)$offer->termid))
        ) {
            return 0;
        }
        $free = $offer->capacity === null ? PHP_INT_MAX : (int)$offer->capacity - registration::taken($offerid) -
            self::held_seats($offerid);
        $n = 0;
        foreach (self::for_offer($offerid) as $w) {
            if ($free <= 0) {
                break;
            }
            if ($w->status !== 'waiting') {
                continue;
            }
            $student = students::get((int)$w->studentid);
            if (!selfreg::status_allows($student) || academic::blocking_holds((int)$student->id)) {
                continue;
            }
            records::save(
                self::TABLE,
                ['id' => $w->id, 'status' => 'offered', 'timeoffered' => time()],
                'registration',
                (int)$student->userid
            );
            messages::student('waitseat', $student, ['course' => $offer->code, 'term' => $offer->termname]);
            $free--;
            $n++;
        }
        return $n;
    }

    /**
     * Daily: seat offers not taken in time lapse (the student stays off the list) and go to the next student.
     *
     * @return int offers lapsed
     */
    public static function daily(): int {
        global $DB;
        $n = 0;
        $offers = [];
        foreach ($DB->get_records(self::TABLE, ['kind' => 'section', 'status' => 'offered']) as $w) {
            if (!self::offer_held((int)$w->timeoffered, time())) {
                records::save(self::TABLE, ['id' => $w->id, 'status' => 'left', 'timeended' => time(),
                    'note' => get_string('wait_lapsed', 'local_studentrecords')], 'task', null);
                $offers[(int)$w->offerid] = true;
                $n++;
            }
        }
        foreach (array_keys($offers) as $offerid) {
            self::seat_opened($offerid);
        }
        return $n;
    }
}
