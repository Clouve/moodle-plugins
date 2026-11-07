<?php

/**
 * Fills a fresh test site with Riverside College, a made-up school.
 *
 * Run it with docs/tools/site.sh seed, which starts from the snapshot "fresh" and saves the snapshot "seed". Never point it
 * at a real site. Every date comes from the day it runs, so a retake in a later year still works. The people, the school,
 * its approvals, its address and its keys are all made up.
 *
 * Everything goes through the plugins' own classes where they have a method for it. The plugins stamp their records with
 * today's date, so clock::at() runs a piece of work and then moves what it wrote back to the day it belongs to.
 *
 * docs/tools/README.md says who is who.
 */

define('CLI_SCRIPT', true);
require('/work/moodle/config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->libdir . '/phpunit/classes/util.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/pdflib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_academictranscript\local\posting;
use local_academictranscript\local\programs as atprograms;
use local_academictranscript\local\requests;
use local_academictranscript\local\srpost;
use local_studentrecords\local\academic;
use local_studentrecords\local\admissions;
use local_studentrecords\local\aid;
use local_studentrecords\local\aidhelp;
use local_studentrecords\local\attendance;
use local_studentrecords\local\audit;
use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\faculty;
use local_studentrecords\local\ferpa;
use local_studentrecords\local\graduation;
use local_studentrecords\local\letters;
use local_studentrecords\local\messages;
use local_studentrecords\local\outside;
use local_studentrecords\local\payments;
use local_studentrecords\local\plans;
use local_studentrecords\local\records;
use local_studentrecords\local\refunds;
use local_studentrecords\local\registration;
use local_studentrecords\local\selfreg;
use local_studentrecords\local\standing;
use local_studentrecords\local\students;
use local_studentrecords\local\waitlist;

/**
 * Runs work as a person, on a day in the past.
 */
final class clock {
    /** @var array The columns that say when something happened, by table. Dates that were chosen, such as a due date, aren't here. */
    const COLUMNS = [
        'local_studentrecords_level' => ['timemodified'],
        'local_studentrecords_grade' => ['timemodified'],
        'local_studentrecords_minpass' => ['timemodified'],
        'local_studentrecords_term' => ['timemodified'],
        'local_studentrecords_course' => ['timemodified'],
        'local_studentrecords_prereq' => ['timemodified'],
        'local_studentrecords_program' => ['timemodified'],
        'local_studentrecords_progcrs' => ['timemodified'],
        'local_studentrecords_student' => ['timecreated', 'timemodified'],
        'local_studentrecords_faculty' => ['timecreated', 'timemodified'],
        'local_studentrecords_faccred' => ['timemodified'],
        'local_studentrecords_facqual' => ['timemodified'],
        'local_studentrecords_history' => ['timecreated'],
        'local_studentrecords_log' => ['timecreated'],
        'local_studentrecords_stathis' => ['effective', 'timecreated'],
        'local_studentrecords_reqdoc' => ['timemodified'],
        'local_studentrecords_appdoc' => ['statedate', 'timemodified'],
        'local_studentrecords_admdec' => ['timecreated'],
        'local_studentrecords_educ' => ['timecreated', 'timemodified'],
        'local_studentrecords_emerg' => ['timecreated', 'timemodified'],
        'local_studentrecords_demog' => ['timemodified'],
        'local_studentrecords_offer' => ['timecreated', 'timemodified'],
        'local_studentrecords_reg' => ['timeregistered', 'timeended', 'timemodified'],
        'local_studentrecords_hold' => ['startdate', 'timeplaced', 'timereleased'],
        'local_studentrecords_saprule' => ['timemodified'],
        'local_studentrecords_stand' => ['timecomputed', 'timeconfirmed'],
        'local_studentrecords_xfer' => ['timecreated', 'timemodified'],
        'local_studentrecords_grchg' => ['timerequested', 'timedecided'],
        'local_studentrecords_gradapp' => ['timeapplied', 'timereviewed', 'timeconferred', 'timemodified'],
        'local_studentrecords_letter' => ['timeissued'],
        'local_studentrecords_ferpa' => ['acknowledged'],
        'local_studentrecords_relauth' => ['fromdate', 'timecreated', 'timerevoked', 'timemodified'],
        'local_studentrecords_fee' => ['timecreated', 'timemodified'],
        'local_studentrecords_txn' => ['txndate', 'timecreated'],
        'local_studentrecords_payreq' => ['timecreated', 'timepaid'],
        'local_studentrecords_plan' => ['timecreated', 'timemodified'],
        'local_studentrecords_stuplan' => ['timeapproved', 'timecancelled', 'timemodified'],
        'local_studentrecords_inst' => ['timepaid', 'reminded', 'timemodified'],
        'local_studentrecords_refrule' => ['timecreated', 'timemodified'],
        'local_studentrecords_refund' => ['eventdate', 'timedecided', 'timecreated', 'timemodified'],
        'local_studentrecords_msglog' => ['timecreated'],
        'local_studentrecords_meeting' => ['timecreated', 'timemodified'],
        'local_studentrecords_attend' => ['timecreated', 'timemodified'],
        'local_studentrecords_export' => ['timecreated', 'timemodified'],
        'local_studentrecords_aid' => ['timecreated', 'timemodified'],
        'local_studentrecords_wait' => ['timeoffered', 'timeended', 'timecreated', 'timemodified'],
        'local_studentrecords_cart' => ['timecreated'],
        'local_studentrecords_subst' => ['timecreated'],
        'local_studentrecords_aidyear' => ['timecreated', 'timemodified'],
        'local_academictranscript_student' => ['timemodified'],
        'local_academictranscript_code' => ['timemodified'],
        'local_academictranscript_transfer' => ['timemodified'],
        'local_academictranscript_posted' => ['timeposted', 'timemodified'],
        'local_academictranscript_change' => ['timerequested', 'timedecided'],
        'local_academictranscript_issue' => ['timecreated'],
        'local_academictranscript_log' => ['timecreated'],
        'local_academictranscript_request' => ['timeissued', 'timecreated', 'timemodified'],
        'notifications' => ['timecreated', 'timeread'],
        'user' => ['timecreated', 'timemodified'],
        'user_enrolments' => ['timecreated', 'timemodified'],
        'role_assignments' => ['timemodified'],
        'course' => ['timecreated', 'timemodified'],
    ];

    /** @var array Receipt numbers given so far, by year. */
    protected static $receipts = [];

    /** @var int Messages older than this aren't delivered: the log says sent, and nobody needs them on a home page. */
    public static $deliverfrom = 0;

    /**
     * Be this person from now on.
     *
     * @param string $username
     */
    public static function become(string $username): void {
        global $DB;
        \core\session\manager::set_user($DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST));
    }

    /**
     * Run work as a person, and move what it wrote back to a day in the past.
     *
     * @param int $when the time it happened, at most today
     * @param string $who username
     * @param callable $work
     * @return mixed what the work returns
     */
    public static function at(int $when, string $who, callable $work) {
        global $DB;
        self::become($who);
        $start = time();
        $lastline = (int)$DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {local_studentrecords_txn}');
        $result = $work();
        self::messages($when);
        $when = min($when, $start);
        $delta = $start - $when;
        if ($delta > 0) {
            // A refund is due some days after its event, so its due date moves with the event.
            $DB->execute('UPDATE {local_studentrecords_refund} SET duedate = duedate - ? WHERE eventdate >= ?', [$delta, $start]);
            foreach (self::COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    $DB->execute("UPDATE {{$table}} SET $column = $column - ? WHERE $column >= ?", [$delta, $start]);
                }
            }
        }
        self::receipts($lastline);
        return $result;
    }

    /**
     * Deliver the messages the work queued. An old one only gets its log row marked as sent.
     *
     * @param int $when
     */
    protected static function messages(int $when): void {
        global $DB;
        foreach ($DB->get_records('task_adhoc', ['component' => 'local_studentrecords'], 'id') as $task) {
            $job = json_decode($task->customdata);
            if ($when >= self::$deliverfrom) {
                messages::send_queued($job);
            } else {
                $DB->set_field(messages::LOG, 'status', 'sent', ['id' => $job->logid]);
            }
            $DB->delete_records('task_adhoc', ['id' => $task->id]);
        }
    }

    /**
     * Number the new receipts in the year of their payment, as they would have been that year.
     *
     * @param int $lastline id of the last account line before the work
     */
    protected static function receipts(int $lastline): void {
        global $DB;
        $lines = $DB->get_records_select(billing::TXN, 'id > ? AND receipt IS NOT NULL', [$lastline], 'txndate, id', 'id, txndate');
        foreach ($lines as $line) {
            $year = userdate((int)$line->txndate, '%Y', catalog::timezone(), false);
            self::$receipts[$year] = (self::$receipts[$year] ?? 0) + 1;
            $DB->set_field(billing::TXN, 'receipt', 'tmp-' . $line->id, ['id' => $line->id]);
        }
        foreach ($lines as $line) {
            $year = userdate((int)$line->txndate, '%Y', catalog::timezone(), false);
            $count = (int)$DB->count_records_select(billing::TXN, $DB->sql_like('receipt', '?'), ['R' . $year . '-%']);
            $DB->set_field(billing::TXN, 'receipt', payments::receipt_number($year, $count + 1), ['id' => $line->id]);
        }
    }
}

/**
 * The made-up school.
 */
final class riverside {
    /** @var string One password for everyone but the admin. */
    const PASSWORD = 'Pass@1234';

    /** @var array A course total that earns each letter. */
    const PERCENT = ['A' => 95, 'A-' => 91, 'B+' => 88, 'B' => 85, 'B-' => 81, 'C+' => 78, 'C' => 74, 'C-' => 71, 'D+' => 68, 'D' => 64,
        'F' => 50];

    /** @var array Season => weeks long, weeks to the next term. Fifty-two weeks in all. */
    const SEASONS = ['Spring' => [16, 3], 'Summer' => [8, 6], 'Fall' => [16, 3]];

    /** @var array Staff: username => first name, last name, job title, department, staff or faculty. */
    const STAFF = [
        'rosa' => ['Rosa', 'Delgado', 'College Registrar', 'Office of the Registrar', 'staff'],
        'omar' => ['Omar', 'Haddad', 'Associate Dean', 'Dean\'s Office', 'staff'],
        'nina' => ['Nina', 'Petrov', 'Director of Admissions', 'Admissions', 'staff'],
        'felix' => ['Felix', 'Romero', 'Bursar', 'Business Office', 'staff'],
        'gwen' => ['Gwen', 'Tanaka', 'Director of Financial Aid', 'Financial Aid', 'staff'],
        'mona' => ['Mona', 'Whitfield', 'Dean of Operations', 'Dean\'s Office', 'staff'],
        'doris' => ['Doris', 'Hale', 'Front Desk Clerk', 'Admissions', 'staff'],
        'kira' => ['Kira', 'Olsen', 'Records Assistant', 'Office of the Registrar', 'staff'],
        'irene' => ['Irene', 'Novak', 'Professor of English', 'Arts and Sciences', 'faculty'],
        'tomas' => ['Tomas', 'Silva', 'Associate Professor of Business', 'Business', 'faculty'],
        'carl' => ['Carl', 'Jensen', 'Chair of Business Programs', 'Business', 'faculty'],
        'pia' => ['Pia', 'Lindgren', 'Laboratory Instructor', 'Health and Technology', 'faculty'],
    ];

    /** @var array Courses: code => title, hours, level, prerequisites, days, start, end, room, instructor, second teacher. */
    const COURSES = [
        'ENG101' => ['English Composition I', 3, 'U', [], 'mon,wed', '09:00', '10:15', 'Alder Hall 101', 'irene', ''],
        'ENG102' => ['English Composition II', 3, 'U', ['ENG101'], 'tue,thu', '09:00', '10:15', 'Alder Hall 101', 'irene', ''],
        'MAT110' => ['College Algebra', 3, 'U', [], 'mon,wed', '10:30', '11:45', 'Birch Hall 204', 'carl', ''],
        'BUS101' => ['Introduction to Business', 3, 'U', [], 'tue,thu', '10:30', '11:45', 'Birch Hall 110', 'tomas', ''],
        'CIS120' => ['Computer Applications', 3, 'U', [], 'fri', '09:00', '11:45', 'Cedar Lab 3', 'pia', 'tomas'],
        'COM150' => ['Public Speaking', 3, 'U', [], 'tue,thu', '16:00', '17:15', 'Alder Hall 205', 'irene', ''],
        'ACC201' => ['Principles of Accounting I', 3, 'U', ['BUS101'], 'mon,wed', '13:00', '14:15', 'Birch Hall 110', 'tomas', ''],
        'ACC202' => ['Principles of Accounting II', 3, 'U', ['ACC201'], 'mon,wed', '14:30', '15:45', 'Birch Hall 110', 'tomas', ''],
        'MKT210' => ['Principles of Marketing', 3, 'U', ['BUS101'], 'tue,thu', '13:00', '14:15', 'Birch Hall 112', 'tomas', ''],
        'MKT220' => ['Digital Marketing', 3, 'U', ['MKT210'], 'tue,thu', '14:30', '15:45', 'Birch Hall 112', 'tomas', ''],
        'ECO201' => ['Microeconomics', 3, 'U', ['MAT110'], 'mon,wed', '09:00', '10:15', 'Birch Hall 204', 'carl', ''],
        'PSY101' => ['Introduction to Psychology', 3, 'U', [], '', '', '', 'Online', 'irene', ''],
        'BIO105' => ['Environmental Biology', 4, 'U', [], 'tue,thu', '09:00', '10:40', 'Cedar Lab 1', 'pia', 'tomas'],
        'LDR501' => ['Foundations of Leadership', 3, 'G', [], 'wed', '18:00', '20:45', 'Birch Hall 301', 'carl', ''],
        'LDR510' => ['Leading Organizational Change', 3, 'G', ['LDR501'], 'wed', '18:00', '20:45', 'Birch Hall 301', 'carl', ''],
        'PHL101' => ['Phlebotomy Theory', 60, 'C', [], 'mon,wed', '17:00', '21:00', 'Cedar Lab 2', 'pia', 'carl'],
        'PHL102' => ['Phlebotomy Clinical Practice', 60, 'C', ['PHL101'], 'tue,thu', '17:00', '21:00', 'Cedar Lab 2', 'pia', 'carl'],
    ];

    /** @var array Sections in each term: term => course code, or code => [section, seats]. */
    const SECTIONS = [
        -3 => ['ENG101', 'MAT110', 'BUS101', 'CIS120', 'COM150', 'PHL101', 'LDR501'],
        -2 => ['ENG101', 'ENG102', 'MAT110', 'BUS101', 'ACC201', 'MKT210', 'ECO201', 'PHL102', 'LDR510'],
        -1 => ['ACC202', 'MKT220', 'PSY101', 'COM150', 'CIS120'],
        0 => ['ENG101', 'ENG102', 'MAT110', 'MAT110/02', 'BUS101', 'ACC201', 'ACC202', 'MKT210', 'MKT220', 'ECO201', 'PSY101', 'BIO105',
            'CIS120', 'PHL101', 'PHL102', 'LDR501'],
        1 => ['ENG101', 'ENG102', 'MAT110', 'BUS101', 'BUS101/02', 'ACC201', 'MKT210', 'PSY101', 'COM150', 'LDR510', 'PHL101'],
    ];

    /** @var array Students with a history: username => first, last, program, first term, status, details. */
    const STUDENTS = [
        'hank' => ['Henry', 'Osei', 'AS-BUS', -3, 'graduated', ['preferred' => 'Hank', 'transfer' => 'block']],
        'gina' => ['Gina', 'Marsh', 'AS-BUS', -3, 'active', ['transfer' => 'block']],
        'marco' => ['Marco', 'Ruiz', 'AS-BUS', -3, 'active', ['transfer' => 'block']],
        'priya' => ['Priya', 'Nair', 'AS-BUS', -3, 'active', ['transfer' => 'block', 'middle' => 'Devi']],
        'ben' => ['Benedict', 'Okoye', 'AS-BUS', -3, 'active', ['preferred' => 'Ben']],
        'sean' => ['Sean', 'Doyle', 'AS-BUS', -3, 'active', ['load' => 'parttime']],
        'kira' => ['Kira', 'Olsen', 'MS-LDR', -3, 'active', ['load' => 'parttime', 'staff' => true]],
        'dante' => ['Dante', 'Hill', 'CERT-PHL', -3, 'active', ['load' => 'parttime']],
        'carmen' => ['Carmen', 'Vega', 'AS-BUS', -2, 'active', ['middle' => 'Luz']],
        'dara' => ['Dara', 'Whitman', 'AS-BUS', -2, 'active', ['load' => 'parttime']],
        'nadia' => ['Nadia', 'Farah', 'AS-BUS', -2, 'active', ['ferpaoptout' => 1]],
        'theo' => ['Theodore', 'Bauer', 'AS-BUS', -2, 'active', ['preferred' => 'Theo']],
        'ivy' => ['Ivy', 'Stone', 'AS-BUS', -2, 'active', ['load' => 'parttime']],
        'yuki' => ['Yuki', 'Mori', 'AS-BUS', -2, 'active', ['f1' => true]],
        'dan' => ['Daniel', 'Reyes', 'AS-BUS', -2, 'withdrawn', ['preferred' => 'Dan']],
        'nell' => ['Nell', 'Hughes', 'AS-BUS', -2, 'withdrawn', []],
        'sam' => ['Samuel', 'Ito', 'AS-BUS', -2, 'active', ['preferred' => 'Sam', 'load' => 'parttime']],
        'leah' => ['Leah', 'Abara', 'AS-BUS', -2, 'onleave', []],
        'wes' => ['Wesley', 'Bell', 'AS-BUS', -2, 'withdrawn', ['preferred' => 'Wes']],
        'eli' => ['Eli', 'Rosen', 'AS-BUS', -1, 'active', []],
        'uma' => ['Uma', 'Reddy', 'AS-BUS', 0, 'active', ['transfer' => 'equivalent']],
        'vince' => ['Vincent', 'Marlow', 'AS-BUS', 0, 'active', ['preferred' => 'Vince', 'load' => 'parttime']],
        'cleo' => ['Cleo', 'Banks', 'CERT-PHL', 0, 'active', ['load' => 'parttime']],
        'alma' => ['Alma', 'Dunn', 'MS-LDR', 0, 'active', ['load' => 'parttime']],
        'reed' => ['Reed', 'Collins', 'MS-LDR', 0, 'active', ['load' => 'parttime']],
        'jonah' => ['Jonah', 'Price', 'AS-BUS', 1, 'enrolled', []],
        'aiden' => ['Aiden', 'Cole', 'AS-BUS', -3, 'active', []],
        'brooke' => ['Brooke', 'Hayes', 'AS-BUS', -3, 'active', []],
        'kofi' => ['Kofi', 'Mensah', 'AS-BUS', -3, 'active', []],
        'jada' => ['Jada', 'Lewis', 'CERT-PHL', -3, 'active', ['load' => 'parttime']],
        'caleb' => ['Caleb', 'Ward', 'AS-BUS', -2, 'active', []],
        'daisy' => ['Daisy', 'Lin', 'AS-BUS', -2, 'active', []],
        'emil' => ['Emil', 'Novotny', 'AS-BUS', -2, 'active', []],
        'lila' => ['Lila', 'Haas', 'AS-BUS', -2, 'active', []],
        'fiona' => ['Fiona', 'Walsh', 'AS-BUS', 0, 'active', []],
        'gabe' => ['Gabriel', 'Soto', 'AS-BUS', 0, 'active', ['preferred' => 'Gabe']],
        'hana' => ['Hana', 'Yilmaz', 'AS-BUS', 0, 'active', []],
        'isaac' => ['Isaac', 'Boyd', 'CERT-PHL', 0, 'active', ['load' => 'parttime']],
    ];

    /** @var array Final grades: username => term => course code => grade, null while the class runs. */
    const HISTORY = [
        'hank' => [-3 => ['ENG101' => 'A', 'MAT110' => 'A-', 'BUS101' => 'A', 'CIS120' => 'B+'],
            -2 => ['ENG102' => 'A-', 'ACC201' => 'A', 'ECO201' => 'B+'],
            -1 => ['ACC202' => 'A', 'COM150' => 'A', 'PSY101' => 'A-']],
        'gina' => [-3 => ['ENG101' => 'B+', 'MAT110' => 'B', 'BUS101' => 'A-', 'CIS120' => 'A'],
            -2 => ['ENG102' => 'B+', 'MKT210' => 'A', 'ECO201' => 'B'],
            -1 => ['MKT220' => 'A-', 'COM150' => 'B+', 'PSY101' => 'A']],
        'marco' => [-3 => ['ENG101' => 'B', 'MAT110' => 'C+', 'BUS101' => 'B-', 'CIS120' => 'B'],
            -2 => ['ENG102' => 'B-', 'ACC201' => 'C+', 'ECO201' => 'C'],
            -1 => ['COM150' => 'B'],
            0 => ['ACC202' => null, 'PSY101' => null]],
        'priya' => [-3 => ['ENG101' => 'A-', 'MAT110' => 'B+', 'BUS101' => 'A', 'CIS120' => 'A-'],
            -2 => ['ENG102' => 'A', 'MKT210' => 'A-', 'ECO201' => 'B+'],
            -1 => ['COM150' => 'A', 'PSY101' => 'A'],
            0 => ['MKT220' => null]],
        'ben' => [-3 => ['ENG101' => 'B', 'MAT110' => 'D', 'BUS101' => 'C+'],
            -2 => ['MAT110' => 'B-', 'ENG102' => 'C+', 'ACC201' => 'C'],
            -1 => ['CIS120' => 'B', 'COM150' => 'B'],
            0 => ['ACC202' => null, 'ECO201' => null, 'PSY101' => null],
            1 => ['MKT210' => null]],
        'sean' => [-3 => ['ENG101' => 'D', 'MAT110' => 'F', 'BUS101' => 'C-'],
            -2 => ['ENG101' => 'C', 'MAT110' => 'F'],
            -1 => ['CIS120' => 'D+'],
            0 => ['MAT110/02' => null, 'ENG102' => null, 'BUS101' => null],
            1 => ['COM150' => null]],
        'kira' => [-3 => ['LDR501' => 'A-'], -2 => ['LDR510' => 'B+'], 0 => ['CIS120' => null], 1 => ['PSY101' => null]],
        'dante' => [-3 => ['PHL101' => 'B'], 0 => ['PHL102' => null]],
        'carmen' => [-2 => ['ENG101' => 'A-', 'MAT110' => 'B+', 'BUS101' => 'B'],
            -1 => ['CIS120' => 'A-', 'COM150' => 'B+'],
            0 => ['ENG102' => null, 'ACC201' => null, 'ECO201' => null, 'PSY101' => null],
            1 => ['MKT210' => null]],
        'dara' => [-2 => ['ENG101' => 'B', 'BUS101' => 'C+'],
            -1 => ['CIS120' => 'B-'],
            0 => ['ENG102' => null, 'MAT110' => null, 'ACC201' => null],
            1 => ['COM150' => null]],
        'nadia' => [-2 => ['ENG101' => 'A', 'BUS101' => 'A-', 'MAT110' => 'A-'],
            -1 => ['COM150' => 'A'],
            0 => ['ENG102' => null, 'MKT210' => null, 'CIS120' => null, 'ECO201' => null],
            1 => ['PSY101' => null]],
        'theo' => [-2 => ['ENG101' => 'B-', 'MAT110' => 'C+'],
            -1 => ['COM150' => 'B'],
            0 => ['BUS101' => null, 'ENG102' => null, 'PSY101' => null]],
        'ivy' => [-2 => ['BUS101' => 'B', 'ENG101' => 'C+'],
            -1 => ['CIS120' => 'B'],
            0 => ['MKT210' => null, 'PSY101' => null]],
        'yuki' => [-2 => ['ENG101' => 'A-', 'MAT110' => 'A', 'BUS101' => 'A'],
            -1 => ['CIS120' => 'A', 'COM150' => 'A-'],
            0 => ['ENG102' => null, 'ACC201' => null, 'ECO201' => null, 'MKT210' => null],
            1 => ['PSY101' => null]],
        'dan' => [-2 => ['ENG101' => 'B', 'MAT110' => 'C+'],
            -1 => ['COM150' => 'B'],
            0 => ['ENG102' => null, 'BUS101' => null, 'PSY101' => null, 'CIS120' => null]],
        'nell' => [-2 => ['ENG101' => 'C+', 'MAT110' => 'B-'],
            0 => ['ENG102' => null, 'BUS101' => null, 'CIS120' => null, 'ECO201' => null]],
        'sam' => [-2 => ['BUS101' => 'B', 'MAT110' => 'B'],
            -1 => ['CIS120' => 'B+'],
            0 => ['ACC201' => null, 'ECO201' => null],
            1 => ['COM150' => null]],
        'leah' => [-2 => ['ENG101' => 'B', 'MAT110' => 'B-'], -1 => ['CIS120' => null]],
        'wes' => [-2 => ['ENG101' => 'C+', 'BUS101' => 'C'], -1 => ['CIS120' => null]],
        'eli' => [-1 => ['CIS120' => 'B', 'COM150' => 'C+'],
            0 => ['MAT110' => null, 'BUS101' => null, 'PSY101' => null, 'BIO105' => null],
            1 => ['ENG101' => null]],
        'uma' => [0 => ['ENG102' => null, 'BUS101' => null, 'MAT110/02' => null, 'CIS120' => null],
            1 => ['COM150' => null]],
        'vince' => [0 => ['BIO105' => null], 1 => ['COM150' => null]],
        'cleo' => [0 => ['PHL101' => null]],
        'alma' => [0 => ['LDR501' => null], 1 => ['LDR510' => null]],
        'reed' => [0 => ['LDR501' => null]],
        'aiden' => [-3 => ['ENG101' => 'B', 'MAT110' => 'B+', 'BUS101' => 'B'],
            -2 => ['ENG102' => 'B', 'ACC201' => 'B-', 'ECO201' => 'C+'],
            -1 => ['ACC202' => 'B-', 'CIS120' => 'A-'],
            0 => ['BIO105' => null, 'PSY101' => null, 'MKT210' => null]],
        'brooke' => [-3 => ['ENG101' => 'A', 'MAT110' => 'A-', 'BUS101' => 'A'],
            -2 => ['ENG102' => 'A-', 'MKT210' => 'A'],
            -1 => ['MKT220' => 'A-', 'CIS120' => 'A'],
            0 => ['ECO201' => null, 'BIO105' => null, 'PSY101' => null, 'ACC201' => null]],
        'kofi' => [-3 => ['ENG101' => 'B-', 'BUS101' => 'B'],
            -2 => ['MKT210' => 'C+', 'MAT110' => 'B-'],
            -1 => ['CIS120' => 'B-'],
            0 => ['MKT220' => null, 'ECO201' => null, 'ENG102' => null]],
        'jada' => [-3 => ['PHL101' => 'A-'], 0 => ['PHL102' => null]],
        'caleb' => [-2 => ['ENG101' => 'C+', 'BUS101' => 'B-', 'MAT110' => 'C'],
            -1 => ['COM150' => 'B-'],
            0 => ['ACC201' => null, 'MKT210' => null, 'ECO201' => null, 'BIO105' => null]],
        'daisy' => [-2 => ['ENG101' => 'A-', 'BUS101' => 'B+', 'MAT110' => 'B'],
            -1 => ['COM150' => 'A-'],
            0 => ['ACC201' => null, 'MKT210' => null, 'ENG102' => null, 'CIS120' => null]],
        'emil' => [-2 => ['ENG101' => 'B+', 'BUS101' => 'B', 'MAT110' => 'B-'],
            -1 => ['CIS120' => 'B'],
            0 => ['ACC201' => null, 'ECO201' => null, 'ENG102' => null, 'BIO105' => null]],
        'lila' => [-2 => ['ENG101' => 'B', 'BUS101' => 'A-'],
            -1 => ['COM150' => 'B+', 'PSY101' => 'B'],
            0 => ['MKT210' => null, 'ENG102' => null, 'BIO105' => null]],
        'fiona' => [0 => ['ENG101' => null, 'MAT110' => null, 'BUS101' => null, 'CIS120' => null]],
        'gabe' => [0 => ['ENG101' => null, 'MAT110' => null, 'BUS101' => null, 'PSY101' => null]],
        'hana' => [0 => ['ENG101' => null, 'MAT110/02' => null, 'CIS120' => null, 'BIO105' => null]],
        'isaac' => [0 => ['PHL101' => null]],
    ];

    /** @var array Applicants and people who haven't started: username => first, last, program, status. */
    const PIPELINE = [
        'ian' => ['Ian', 'Lowell', 'AS-BUS', 'inquiry'],
        'bella' => ['Bella', 'Cruz', 'CERT-PHL', 'inquiry'],
        'ada' => ['Ada', 'Whitlock', 'AS-BUS', 'applicant'],
        'noah' => ['Noah', 'Kim', 'CERT-PHL', 'applicant'],
        'lucy' => ['Lucy', 'Tran', 'AS-BUS', 'applicant'],
        'raj' => ['Raj', 'Malhotra', 'MS-LDR', 'applicant'],
        'tara' => ['Tara', 'Quinn', 'CERT-PHL', 'waitlisted'],
        'evan' => ['Evan', 'Shaw', 'CERT-PHL', 'waitlisted'],
        'zoe' => ['Zoe', 'Fischer', 'AS-BUS', 'admitted'],
        'mina' => ['Mina', 'Park', 'AS-BUS', 'admitted'],
        'kai' => ['Kai', 'Lund', 'AS-BUS', 'admitted'],
        'rex' => ['Rex', 'Dolan', 'AS-BUS', 'denied'],
        'faye' => ['Faye', 'Okafor', 'AS-BUS', 'deferred'],
    ];

    /** @var array Students with only Academic Transcript data, and people with no record: username => first, last. */
    const OTHERS = [
        'ruth' => ['Ruth', 'Keller'], 'hugo' => ['Hugo', 'Lindqvist'], 'lena' => ['Lena', 'Ortiz'], 'arlo' => ['Arlo', 'Tate'],
        'june' => ['June', 'Adler'], 'owen' => ['Owen', 'Pratt'], 'paul' => ['Paul', 'Greer'],
    ];

    /** @var array Transfer credit of a student who comes in with half a degree: course, credits. */
    const BLOCK = [['HIS 101 United States History I', 3], ['HIS 102 United States History II', 3], ['SOC 101 Sociology', 3],
        ['PHI 110 Ethics', 3], ['SPA 101 Spanish I', 4], ['SPA 102 Spanish II', 4], ['CHE 110 Chemistry in Daily Life', 4],
        ['ART 105 Art Appreciation', 3], ['MUS 100 Music Appreciation', 3]];

    /** @var string[] Town names for the made-up addresses. Some of these names exist somewhere, and no address is real. */
    const CITIES = ['Elm Hollow', 'Fairmount', 'Ashby', 'Willow Bend', 'Marlin Heights'];

    /** @var testing_data_generator */
    public static $g;

    /** @var local_academictranscript_generator */
    public static $at;

    /** @var stdClass[] Terms by offset: -3 to -1 are over, 0 is running, 1 is next. */
    public static $terms = [];

    /** @var stdClass[] The terms of Academic Transcript, by offset. */
    public static $atterms = [];

    /** @var array Final date values to put on the terms at the end: offset => fields. */
    public static $termfinish = [];

    /** @var int[] Levels: U, G, C => id. */
    public static $levels = [];

    /** @var int[] Programs: code => id. */
    public static $programs = [];

    /** @var int[] Catalog courses: code => id. */
    public static $courses = [];

    /** @var int[] Faculty records: username => id. */
    public static $faculty = [];

    /** @var int[] Plans of the school: short name => id. */
    public static $plans = [];

    /** @var int[] Checklist documents: short name => id. */
    public static $docs = [];

    /** @var stdClass[] Offerings: "term|code/section" => offering. */
    public static $offers = [];

    /** @var int[] Student records: username => id. */
    public static $students = [];

    /** @var string[] What the final report says: label => value. */
    public static $report = [];

    /**
     * A day, as noon in the school's time zone: this many days after a term starts (or before, below zero).
     *
     * @param int $term offset of the term
     * @param int $days
     * @return int
     */
    public static function day(int $term, int $days): int {
        return self::shift((int)self::$terms[$term]->startdate, $days);
    }

    /**
     * A day after the end of a term.
     *
     * @param int $term offset of the term
     * @param int $days
     * @return int
     */
    public static function after(int $term, int $days): int {
        return self::shift((int)self::$terms[$term]->enddate, $days);
    }

    /**
     * A time some days from another, at a time of the school's working day.
     *
     * @param int $time
     * @param int $days
     * @return int
     */
    public static function shift(int $time, int $days): int {
        $ymd = (new DateTimeImmutable(catalog::time_to_date($time), new DateTimeZone('UTC')))->modify("$days days")->format('Y-m-d');
        return catalog::date_to_time($ymd) + mt_rand(-3 * 3600, 4 * 3600);
    }

    /**
     * A time this many days ago.
     *
     * @param int $days
     * @return int
     */
    public static function ago(int $days): int {
        return self::shift(time(), -$days);
    }

    /**
     * A Moodle user with a made-up address.
     *
     * @param string $username
     * @param string $first
     * @param string $last
     * @return stdClass
     */
    public static function person(string $username, string $first, string $last): stdClass {
        return self::$g->create_user(['username' => $username, 'password' => self::PASSWORD, 'firstname' => $first,
            'lastname' => $last, 'email' => $username . '@example.com', 'city' => self::CITIES[crc32($username) % 5],
            'country' => 'US', 'firstnamephonetic' => '', 'lastnamephonetic' => '', 'middlename' => '', 'alternatename' => '',
            'description' => '', 'descriptionformat' => FORMAT_HTML]);
    }

    /**
     * Site settings that aren't the plugins'.
     */
    public static function site(): void {
        global $DB;
        set_config('timezone', 'America/Chicago');
        set_config('forcetimezone', 99);
        core_date::set_default_server_timezone();
        set_config('country', 'US');
        set_config('lang', 'en');
        set_config('guestloginbutton', 0);
        set_config('showcampaigncontent', 0);
        set_config('showservicesandsupportcontent', 0);
        set_config('enabledashboard', 1);
        set_config('summary', 'Riverside College is a made-up school. Every person and record on this site is made up.');
        $site = get_site();
        $DB->set_field('course', 'summary', 'Riverside College is a made-up school. Every person and record on this site is made up.',
            ['id' => $site->id]);
        // Guided tours would cover the first page of every new account.
        $DB->set_field_select('tool_usertours_tours', 'enabled', 0, '1 = 1');
        // The term dates are worked out from these two before the school's other settings are saved.
        set_config('timezone', 'America/Chicago', 'local_studentrecords');
        set_config('withdrawpct', 75, 'local_studentrecords');
    }

    /**
     * The staff accounts and their roles.
     */
    public static function staff(): void {
        global $DB;
        $sys = context_system::instance();
        foreach (self::STAFF as $username => [$first, $last]) {
            self::person($username, $first, $last);
        }
        foreach (array_keys(self::OTHERS + self::PIPELINE + self::STUDENTS) as $username) {
            if (!isset(self::STAFF[$username])) {
                $row = self::OTHERS[$username] ?? self::PIPELINE[$username] ?? self::STUDENTS[$username];
                self::person($username, $row[0], $row[1]);
            }
        }
        $sr = fn(array $caps) => array_map(fn($cap) => 'local/studentrecords:' . $cap, $caps);
        $at = fn(array $caps) => array_map(fn($cap) => 'local/academictranscript:' . $cap, $caps);
        $make = function (string $name, string $short, string $description, array $caps) use ($sys): int {
            $id = create_role($name, $short, $description);
            set_role_contextlevels($id, [CONTEXT_SYSTEM]);
            foreach ($caps as $cap) {
                assign_capability($cap, CAP_ALLOW, $id, $sys->id);
            }
            return $id;
        };
        $registrar = $make('Registrar', 'srregistrar', 'Records staff: student records, offerings, terms, transcripts.', array_merge(
            $sr(['configure', 'import', 'managestudents', 'viewstudents', 'managefaculty', 'viewfaculty', 'viewdemographics',
                'approvegradechange', 'viewlog', 'takeattendance']),
            $at(['configure', 'issueofficial', 'entercodes', 'postgrades', 'approvechanges', 'managerecords', 'viewissued']),
            // The Moodle user box on the student form searches Moodle's users.
            ['moodle/user:viewalldetails']
        ));
        $admissions = $make('Admissions', 'sradmissions', 'Admissions staff: applicants, decisions and the student file.',
            array_merge($sr(['managestudents', 'viewstudents', 'viewdemographics']), ['moodle/user:viewalldetails']));
        $approver = $make('Grade change approver', 'srapprover', 'The second person on grade changes.',
            $sr(['approvegradechange']));
        $finance = billing::create_finance_role();
        $finaid = aid::create_role();
        $manager = (int)$DB->get_field('role', 'id', ['shortname' => 'manager']);
        $roles = ['rosa' => [$registrar], 'kira' => [$registrar, $finance], 'nina' => [$admissions], 'doris' => [$admissions],
            'omar' => [$approver], 'felix' => [$finance], 'gwen' => [$finaid], 'mona' => [$manager]];
        foreach ($roles as $username => $ids) {
            foreach ($ids as $id) {
                role_assign($id, $DB->get_field('user', 'id', ['username' => $username]), $sys->id);
            }
        }
        $sys->mark_dirty();
        accesslib_clear_all_caches(true);
    }

    /**
     * The terms: three that are over, the one running, which began about ten weeks ago, and the next one.
     */
    public static function calendar(): void {
        $utc = new DateTimeZone('UTC');
        $seasons = array_keys(self::SEASONS);
        $today = new DateTimeImmutable(catalog::time_to_date(time()), $utc);
        $start = $today->modify('-70 days')->modify('monday this week');
        $month = (int)$start->format('n');
        $year = (int)$start->format('Y') + ($month === 12 ? 1 : 0);
        $season = in_array($month, [12, 1, 2, 3, 4], true) ? 0 : ($month <= 7 ? 1 : 2);
        $terms = [0 => [$seasons[$season], $year, $start, 16]];
        // Walk back from the running term.
        [$s, $y, $from] = [$season, $year, $start];
        for ($i = -1; $i >= -3; $i--) {
            $y = $s === 0 ? $y - 1 : $y;
            $s = ($s + 2) % 3;
            [$weeks, $gap] = self::SEASONS[$seasons[$s]];
            $from = $from->modify('-' . (($weeks + $gap) * 7) . ' days');
            $terms[$i] = [$seasons[$s], $y, $from, $weeks];
        }
        // And on to the next.
        $gap = self::SEASONS[$seasons[$season]][1];
        $next = ($season + 1) % 3;
        $terms[1] = [$seasons[$next], $year + ($next === 0 ? 1 : 0), $start->modify('+' . ((16 + $gap) * 7) . ' days'),
            self::SEASONS[$seasons[$next]][0]];
        ksort($terms);
        foreach ($terms as $offset => [$name, $termyear, $first, $weeks]) {
            $last = $first->modify('+' . ($weeks * 7 - 3) . ' days');
            $time = fn(DateTimeImmutable $day) => catalog::date_to_time($day->format('Y-m-d'));
            $id = records::save('local_studentrecords_term', ['name' => $name . ' ' . $termyear, 'startdate' => $time($first),
                'enddate' => $time($last), 'adddropstart' => $time($first->modify('-42 days')),
                'adddropend' => $time($first->modify('+7 days')),
                'withdrawby' => catalog::withdraw_time($time($first), $time($last)), 'withdrawcalc' => 1,
                'examstart' => $time($last->modify('-4 days')), 'examend' => $time($last), 'active' => 1,
                'notes' => $weeks === 16 ? 'Sixteen weeks, with one week of final exams.' : 'Eight-week summer term.']);
            audit::log('create', 'term', $id);
            self::$terms[$offset] = self::term_row($id);
            self::$termfinish[$offset] = ['gradefreeze' => $time($last->modify('+14 days')), 'active' => $offset >= 0 ? 1 : 0];
            self::$atterms[$offset] = self::$at->create_term(['name' => $name . ' ' . $termyear,
                'startdate' => $time($first), 'enddate' => $time($last)]);
        }
    }

    /**
     * A term row.
     *
     * @param int $id
     * @return stdClass
     */
    public static function term_row(int $id): stdClass {
        global $DB;
        return $DB->get_record('local_studentrecords_term', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Save a list of settings of Student Records, with the history a person's change leaves.
     *
     * @param array $settings
     */
    protected static function settings(array $settings): void {
        foreach ($settings as $name => $value) {
            records::set_setting($name, (string)$value);
        }
    }

    /**
     * Put an image of docs/tools/assets in a file area of the system context.
     *
     * @param string $component
     * @param string $area
     * @param string $file
     */
    protected static function image(string $component, string $area, string $file): void {
        $fs = get_file_storage();
        $context = context_system::instance();
        $fs->delete_area_files($context->id, $component, $area, 0);
        $fs->create_file_from_pathname(['contextid' => $context->id, 'component' => $component, 'filearea' => $area,
            'itemid' => 0, 'filepath' => '/', 'filename' => $file], '/tmp/seed-assets/' . $file);
    }

    /**
     * The school: the answers of the setup wizard, the grading scale, levels, rules, catalog, programs and the checklist.
     */
    public static function school(): void {
        self::settings([
            'schoolname' => 'Riverside College', 'othernames' => 'RVC', 'legalname' => 'Riverside College, Inc.',
            'address' => "100 College Way\nElm Hollow, IL 62999", 'campusaddress' => "Main Campus\n100 College Way\nElm Hollow, IL 62999",
            'state' => 'Illinois', 'website' => 'https://www.riverside.example.com', 'phone' => '(618) 555-0100',
            'email' => 'registrar@example.com', 'registrarname' => 'Rosa Delgado', 'registrartitle' => 'College Registrar',
            'officehours' => 'Monday to Friday, 8:30 to 4:30', 'timezone' => 'America/Chicago', 'opeid' => '00999900',
            'stateagency' => 'State Board of Private Colleges (made up)', 'statelicense' => 'PC-2041',
            'accreditor' => 'Great Rivers Commission on Colleges (made up)', 'fedaid' => 1, 'f1' => 1,
            'ferpanotice' => "Riverside College Annual Notice of Student Rights\n\n" .
                "You have the right to see your education records, to ask us to correct them, and to say who else may see them. " .
                "We share directory information unless you tell us not to. To withhold it, ask the Registrar's office. " .
                "If you think we broke these rules, you may complain to the U.S. Department of Education.\n\n" .
                "This notice is sample text for a made-up school.",
            'calendartype' => 'semester', 'yearstart' => 'August 1', 'creditunit' => 'Semester credit hour', 'withdrawpct' => 75,
            'minattendance' => 80, 'atriskgrade' => 70, 'ldamoodle' => 0, 'refundlda' => 0, 'fulltimehours' => 12, 'selfreg' => 1,
            'idauto' => 1, 'idprefix' => 'RVC', 'idyear' => 1, 'idpadding' => 4, 'idseparator' => '-', 'transferingpa' => 0,
            'honors' => '3.9 Summa cum laude; 3.7 Magna cum laude; 3.5 Cum laude',
        ]);
        self::image('local_studentrecords', 'logo', 'logo.png');
        foreach (['school', 'approvals', 'calendar', 'admissions'] as $step) {
            audit::log('update', 'setup', null, null, $step);
        }

        // Grading scale, best first, then the marks.
        $order = 0;
        foreach ([['A', 93, 100, 4.0, 'Excellent'], ['A-', 90, 92, 3.7, 'Excellent'], ['B+', 87, 89, 3.3, 'Good'], ['B', 83, 86, 3.0, 'Good'],
            ['B-', 80, 82, 2.7, 'Good'], ['C+', 77, 79, 2.3, 'Satisfactory'], ['C', 73, 76, 2.0, 'Satisfactory'],
            ['C-', 70, 72, 1.7, 'Satisfactory'], ['D+', 67, 69, 1.3, 'Poor, passing'], ['D', 63, 66, 1.0, 'Poor, passing'],
            ['F', 0, 62, 0.0, 'Failing']] as [$letter, $min, $max, $points, $description]) {
            records::save('local_studentrecords_grade', ['letter' => $letter, 'minpct' => $min, 'maxpct' => $max,
                'points' => $points, 'ingpa' => 1, 'earns' => $points > 0 ? 1 : 0, 'description' => $description,
                'sortorder' => ++$order]);
        }
        foreach ([['P', 1, 'Pass. Hours earned, no grade points.'], ['NP', 0, 'No Pass. No hours earned.'],
            ['I', 0, 'Incomplete. Replaced by a final grade when the work is finished.'],
            ['W', 0, 'Withdrawn. The student left the course.']] as [$letter, $earns, $description]) {
            records::save('local_studentrecords_grade', ['letter' => $letter, 'ingpa' => 0, 'earns' => $earns,
                'description' => $description, 'sortorder' => ++$order]);
        }

        // Levels.
        foreach (['U' => 'Undergraduate', 'G' => 'Graduate', 'C' => 'Certificate'] as $key => $name) {
            self::$levels[$key] = records::save('local_studentrecords_level', ['name' => $name, 'sortorder' => count(self::$levels) + 1,
                'description' => $name . ' programs']);
        }

        // Catalog.
        foreach (self::COURSES as $code => [$title, $hours, $level]) {
            self::$courses[$code] = records::save('local_studentrecords_course', ['code' => $code, 'title' => $title,
                'hours' => $hours, 'unittype' => $level === 'C' ? 'clock' : 'credit', 'levelid' => self::$levels[$level]]);
            audit::log('create', 'course', self::$courses[$code]);
        }
        foreach (self::COURSES as $code => $course) {
            foreach ($course[3] as $required) {
                records::save('local_studentrecords_prereq', ['courseid' => self::$courses[$code],
                    'reqcourseid' => self::$courses[$required]], 'import');
                records::save('local_studentrecords_course', ['id' => self::$courses[$code], 'prereqtext' => $required]);
            }
        }

        // Programs. Only the first has a course list.
        self::$programs['AS-BUS'] = records::save('local_studentrecords_program', ['code' => 'AS-BUS', 'name' => 'Business Administration',
            'school' => 'School of Business', 'levelid' => self::$levels['U'], 'credential' => 'Associate of Science',
            'credentialtype' => 'associate', 'isdegree' => 1, 'cipcode' => '52.0201', 'delivery' => 'Day and evening classes on campus',
            'deliverymode' => 'hybrid', 'location' => 'Main Campus', 'duration' => '2 years', 'maxtime' => '3 years',
            'unittype' => 'credit', 'totalhours' => 60, 'trackhours' => 6, 'mingpa' => 2.0, 'residenthours' => 24,
            'graderules' => 'C or better in Core Courses']);
        self::$programs['MS-LDR'] = records::save('local_studentrecords_program', ['code' => 'MS-LDR', 'name' => 'Organizational Leadership',
            'school' => 'School of Business', 'levelid' => self::$levels['G'], 'credential' => 'Master of Science',
            'credentialtype' => 'master', 'isdegree' => 1, 'cipcode' => '52.0213', 'delivery' => 'Evening classes',
            'deliverymode' => 'inperson', 'location' => 'Main Campus', 'duration' => '2 years', 'maxtime' => '4 years',
            'unittype' => 'credit', 'totalhours' => 30, 'mingpa' => 3.0, 'residenthours' => 24]);
        self::$programs['CERT-PHL'] = records::save('local_studentrecords_program', ['code' => 'CERT-PHL', 'name' => 'Phlebotomy Technician',
            'school' => 'School of Health', 'levelid' => self::$levels['C'], 'credential' => 'Certificate',
            'credentialtype' => 'certificate', 'isdegree' => 0, 'cipcode' => '51.1009', 'delivery' => 'Evening labs',
            'deliverymode' => 'inperson', 'location' => 'Main Campus', 'duration' => '1 term', 'maxtime' => '1 year',
            'unittype' => 'clock', 'totalhours' => 120]);
        foreach (self::$programs as $id) {
            audit::log('create', 'program', $id);
        }
        $outline = [['Core Courses (18 credits)', 'required', ['ENG101', 'ENG102', 'MAT110', 'BUS101', 'CIS120', 'COM150']],
            ['Accounting Track', 'track', ['ACC201', 'ACC202']], ['Marketing Track', 'track', ['MKT210', 'MKT220']],
            ['Electives (6 credits)', 'elective', ['ECO201', 'PSY101', 'BIO105']]];
        $sort = 0;
        foreach ($outline as [$group, $requirement, $codes]) {
            foreach ($codes as $code) {
                records::save('local_studentrecords_progcrs', ['programid' => self::$programs['AS-BUS'],
                    'courseid' => self::$courses[$code], 'groupname' => $group, 'requirement' => $requirement,
                    'sortorder' => ++$sort]);
            }
        }
        $sort = 0;
        foreach (['PHL101', 'PHL102'] as $code) {
            records::save('local_studentrecords_progcrs', ['programid' => self::$programs['CERT-PHL'],
                'courseid' => self::$courses[$code], 'groupname' => 'Clinical Sequence', 'requirement' => 'required',
                'sortorder' => ++$sort]);
        }

        // Pass rules, standing rules, the checklist.
        records::save('local_studentrecords_minpass', ['levelid' => self::$levels['U'], 'minletter' => 'D',
            'note' => 'Lowest passing grade for undergraduate courses']);
        records::save('local_studentrecords_minpass', ['levelid' => self::$levels['G'], 'minletter' => 'C',
            'note' => 'Lowest passing grade for graduate courses']);
        records::save('local_studentrecords_minpass', ['levelid' => self::$levels['C'], 'minpct' => 70,
            'note' => 'Certificate courses need 70%']);
        records::save('local_studentrecords_minpass', ['programid' => self::$programs['AS-BUS'],
            'groupname' => 'Core Courses (18 credits)', 'minletter' => 'C', 'note' => 'Core courses need a C']);
        foreach ([['U', 2.0, 67, 6, 150, 50], ['G', 3.0, 67, 5, 150, 20]] as [$level, $gpa, $completion, $years, $timeframe, $transfer]) {
            records::save(standing::RULES, ['levelid' => self::$levels[$level], 'mingpa' => $gpa, 'mincompletion' => $completion,
                'maxyears' => $years, 'maxtimeframe' => $timeframe, 'maxtransfer' => $transfer, 'active' => 1]);
        }
        foreach ([['diploma', 'High school diploma or GED', 'A copy of the diploma or the GED certificate.', 1, null, null],
            ['transcripts', 'Official transcripts from earlier schools', 'Sent straight from each school.', 1, null, null],
            ['id', 'Government photo ID', 'A driver\'s license or passport.', 1, null, null],
            ['immunization', 'Immunization record', 'Needed for clinical practice.', 1, null, self::$levels['C']],
            ['essay', 'Personal statement', 'One page, optional.', 0, null, null]] as $i => [$key, $name, $text, $required, $programid, $levelid]) {
            self::$docs[$key] = records::save('local_studentrecords_reqdoc', ['name' => $name, 'description' => $text,
                'required' => $required, 'programid' => $programid, 'levelid' => $levelid, 'active' => 1, 'sortorder' => $i + 1]);
        }
        admissions::reset_cache();
    }

    /**
     * Faculty and staff records.
     */
    public static function people(): void {
        global $DB;
        foreach (self::STAFF as $username => [$first, $last, $title, $department, $type]) {
            $user = $DB->get_record('user', ['username' => $username]);
            self::$faculty[$username] = faculty::save(['userid' => $user->id, 'stafftype' => $type, 'firstname' => $first,
                'lastname' => $last, 'honorific' => $type === 'faculty' ? 'Dr.' : '', 'jobtitle' => $title, 'department' => $department,
                'email' => $user->email, 'phone' => '(618) 555-01' . (10 + count(self::$faculty)), 'employment' => 'fulltime',
                'active' => 1, 'nolms' => $username === 'doris' ? 1 : 0,
                'officehours' => $type === 'faculty' ? 'Tuesday and Thursday, 1:00 to 3:00' : '']);
        }
        $DB->set_field('local_studentrecords_faculty', 'honorific', '', ['id' => self::$faculty['pia']]);
        $DB->set_field('local_studentrecords_faculty', 'employment', 'parttime', ['id' => self::$faculty['pia']]);
        foreach ([['irene', 'degree', 'Ph.D.', 'English', 'Lakeshore State University (made up)', 2009, null],
            ['irene', 'degree', 'M.A.', 'Rhetoric', 'Lakeshore State University (made up)', 2004, null],
            ['tomas', 'degree', 'M.B.A.', 'Accounting', 'Prairie University (made up)', 2012, null],
            ['tomas', 'license', 'Certified Public Accountant', 'State of Illinois', 'State Board of Accountancy (made up)', 2014, null],
            ['carl', 'degree', 'Ph.D.', 'Economics', 'Prairie University (made up)', 2006, null],
            ['pia', 'degree', 'B.S.', 'Medical Laboratory Science', 'Northfield College (made up)', 2016, null],
            ['pia', 'certification', 'Certified Phlebotomy Technician', 'Phlebotomy', 'National Lab Board (made up)', 2017, 75]] as $row) {
            [$who, $type, $title, $subject, $institution, $year, $expires] = $row;
            faculty::save_credential(['facultyid' => self::$faculty[$who], 'credtype' => $type, 'title' => $title, 'subject' => $subject,
                'institution' => $institution, 'yearawarded' => $year, 'verified' => 1,
                'expires' => $expires ? time() + $expires * DAYSECS : null]);
        }
        foreach (self::COURSES as $code => $course) {
            faculty::add_qualifications(self::$faculty[$course[8]], [self::$courses[$code]]);
            if ($course[9] !== '') {
                faculty::add_qualifications(self::$faculty[$course[9]], [self::$courses[$code]]);
            }
        }
    }

    /**
     * Billing: settings, the price list, plans of the school, refund rules and a payment account.
     */
    public static function money(): void {
        $account = \core_payment\helper::save_payment_account((object)['name' => 'Riverside College student payments',
            'idnumber' => 'rvc-student-payments', 'enabled' => 1, 'contextid' => context_system::instance()->id]);
        \core_payment\helper::save_payment_gateway((object)['accountid' => $account->get('id'), 'gateway' => 'paypal', 'enabled' => 1,
            'config' => json_encode(['brandname' => 'Riverside College', 'clientid' => 'made-up-client-id', 'secret' => 'made-up-secret',
                'environment' => 'sandbox'])]);
        self::settings(['billing' => 1, 'billaccess' => 'downpayment', 'paymentaccount' => $account->get('id'), 'reminderdays' => 7,
            'refunddays' => 30, 'halftime' => 6, 'gradlevels' => self::$levels['G'],
            'payinfo' => "Pay online from your account page, by check to Riverside College, or at the Business Office in Alder Hall.",
            'statementnote' => 'Questions about this statement? Call the Business Office at (618) 555-0104.']);
        audit::log('update', 'setup', null, null, 'billing settings');
        $U = self::$levels['U'];
        $G = self::$levels['G'];
        foreach ([['Undergraduate tuition', 'tuition', 'credithour', 320, 'level', $U, 1, 1],
            ['Graduate tuition', 'tuition', 'credithour', 480, 'level', $G, 1, 1],
            ['Certificate tuition', 'tuition', 'clockhour', 18, 'program', self::$programs['CERT-PHL'], 1, 1],
            ['Registration fee', 'fee', 'term', 75, 'all', null, 0, 1],
            ['Technology fee', 'fee', 'term', 60, 'all', null, 1, 1],
            ['Lab supplies fee', 'fee', 'course', 45, 'program', self::$programs['CERT-PHL'], 1, 1],
            ['Application fee', 'fee', 'once', 35, 'all', null, 0, 0],
            ['Replacement ID card', 'fee', 'manual', 15, 'all', null, 0, 0],
            ['Graduation fee', 'fee', 'manual', 50, 'all', null, 0, 1],
            ['Late payment fee', 'fee', 'late', 25, 'all', null, 0, 0]] as [$name, $kind, $basis, $amount, $scope, $scopeid, $back, $tax]) {
            billing::save_fee(['name' => $name, 'kind' => $kind, 'basis' => $basis, 'amount' => $amount, 'scope' => $scope,
                'scopeid' => $scopeid, 'refundable' => $back, 'qualified' => $tax]);
        }
        foreach ([['monthly', 'Four-payment plan', 'all', null, 'percent', 25, 3, 'monthly', 'relative', 30, null, 40, 25, 5],
            ['two', 'Two-payment plan', 'all', null, 'percent', 50, 1, 'monthly', 'relative', 45, null, 20, 25, 5],
            ['clock', 'Certificate plan', 'program', self::$programs['CERT-PHL'], 'amount', 200, 2, 'biweekly', 'relative', 14, null, 0, 15, 3],
            ] as [$key, $name, $scope, $scopeid, $downtype, $down, $num, $frequency, $mode, $first, $dates, $fee, $late, $grace]) {
            self::$plans[$key] = plans::save_template(['name' => $name, 'scope' => $scope, 'scopeid' => $scopeid, 'downtype' => $downtype,
                'downvalue' => $down, 'numpay' => $num, 'frequency' => $frequency, 'duemode' => $mode, 'firstdue' => $first,
                'duedates' => $dates, 'planfee' => $fee, 'latefee' => $late, 'gracedays' => $grace]);
        }
        foreach ([['Cancel within three business days', 'cancel', 3, 100, 100, 1],
            ['Before the term starts', 'beforestart', 0, 100, 100, 0],
            ['During add/drop', 'adddrop', 0, 100, 100, 0],
            ['First quarter of the term', 'pctterm', 25, 50, 0, 0],
            ['First 40% of the term', 'pctterm', 40, 25, 0, 0],
            ['After that', 'after', 0, 0, 0, 0]] as $i => [$name, $type, $value, $tuition, $fees, $all]) {
            refunds::save_rule(['sortorder' => $i + 1, 'name' => $name, 'ruletype' => $type, 'value' => $value, 'tuitionpct' => $tuition,
                'feespct' => $fees, 'allfees' => $all]);
        }
    }

    /**
     * Academic Transcript: settings, seal and signature, and its own programs and catalog.
     */
    public static function transcript(): void {
        foreach (['schoolname' => 'Riverside College', 'address' => "100 College Way\nElm Hollow, IL 62999", 'phone' => '(618) 555-0100',
            'email' => 'registrar@example.com', 'website' => 'https://www.riverside.example.com', 'registrarname' => 'Rosa Delgado',
            'registrartitle' => 'College Registrar', 'source' => 'studentrecords', 'roundpercent' => 1, 'repeatrule' => 'latest',
            'creditlabel' => 'Credit Hours', 'clocklabel' => 'Clock Hours', 'fee_email' => 5, 'fee_mail' => 10, 'fee_pickup' => 5,
            'pagesize' => 'LETTER', 'accentcolor' => '#1F3A5F', 'signaturemode' => 'images', 'logo' => '/logo.png',
            'signatureimage' => '/signature.png', 'sealimage' => '/seal.png',
            'key_accreditation' => '{school} is licensed by the State Board of Private Colleges and accredited by the Great Rivers ' .
                'Commission on Colleges. Both are made up, like the school.',
            'key_contact' => 'Office of the Registrar, {school}, 100 College Way, Elm Hollow, IL 62999 · (618) 555-0100 · registrar@example.com',
            'key_units' => 'One credit hour equals 15 hours of instruction plus 30 hours of outside work per term. One clock hour equals ' .
                '50 minutes of supervised instruction.',
            'key_scalenote' => 'Riverside College uses a 4.0 scale.', 'setupcomplete' => 1] as $name => $value) {
            set_config($name, $value, 'local_academictranscript');
        }
        self::image('local_academictranscript', 'logo', 'logo.png');
        self::image('local_academictranscript', 'signatureimage', 'signature.png');
        self::image('local_academictranscript', 'sealimage', 'seal.png');
        $business = atprograms::save(['name' => 'Business Administration', 'credential' => 'Associate of Science', 'unittype' => 'credit',
            'totalhours' => 60]);
        $leadership = atprograms::save(['name' => 'Organizational Leadership', 'credential' => 'Master of Science', 'unittype' => 'credit',
            'totalhours' => 30]);
        $phlebotomy = atprograms::save(['name' => 'Phlebotomy Technician', 'credential' => 'Certificate', 'unittype' => 'clock',
            'totalhours' => 120]);
        foreach (self::COURSES as $code => [$title, $hours, $level]) {
            atprograms::save_course(['code' => $code, 'title' => $title, 'hours' => $hours, 'unittype' => $level === 'C' ? 'clock' : 'credit',
                'programid' => ['U' => $business, 'G' => $leadership, 'C' => $phlebotomy][$level]]);
        }
    }

    /**
     * The theme.
     */
    public static function theme(): void {
        set_config('theme', 'studentrecords');
        foreach (['schoolname' => 'Riverside College', 'logo' => '/mark.png', 'accentcolor' => '#1f5f7a',
            'loginwelcome' => 'Welcome to Riverside College', 'loginhelp' => 'This is a made-up school. Forgot your password? Call the help desk at (618) 555-0109.'] as $name => $value) {
            set_config($name, $value, 'theme_studentrecords');
        }
        self::image('theme_studentrecords', 'logo', 'mark.png');
    }

    /**
     * Sections of a term, each with a Moodle course that has its Transcript fields filled in and its teachers enrolled.
     *
     * @param int $offset
     */
    public static function sections(int $offset): void {
        global $DB;
        $term = self::$terms[$offset];
        $category = self::$g->create_category(['name' => $term->name]);
        $short = strtoupper(substr($term->name, 0, 2)) . substr($term->name, -2);
        foreach (self::SECTIONS[$offset] as $entry) {
            [$code, $section] = explode('/', $entry . '/01');
            [$title, $hours, $level, , $days, $from, $to, $room, $teacher, $second] = self::COURSES[$code];
            // The Course code field is unique, so only one course can have the plain code: the running section 01.
            $plain = $offset === 0 && $section === '01';
            $course = self::$at->create_course_with_fields([
                'fullname' => $code . ' - ' . $title . ($offset === 0 ? '' : ' (' . $term->name . ')') .
                    ($section === '01' ? '' : ', section ' . $section),
                'shortname' => $code . '-' . $section . '-' . $short, 'category' => $category->id,
                'summary' => $title . ', ' . $term->name . '. A made-up course.', 'summaryformat' => FORMAT_HTML,
                'startdate' => $term->startdate, 'enddate' => $term->enddate, 'numsections' => 4,
                'code' => $plain ? $code : $code . ' ' . $short . ($section === '01' ? '' : '-' . $section), 'hours' => $hours,
                'hourtype' => $level === 'C' ? 'clock' : 'credit', 'termid' => self::$atterms[$offset]->id]);
            $seats = $level === 'G' ? 12 : 20;
            if ($offset === 1 && $code === 'COM150') {
                $seats = 6;
            }
            // A second section meets in the evening. Next term's BUS101 02 clashes with ENG101, to show the warning for that.
            if ($section !== '01') {
                [$days, $from, $to] = $offset === 1 ? ['mon,wed', '09:30', '10:45'] : ['tue,thu', '18:00', '19:15'];
            }
            $id = records::save(registration::OFFER, ['termid' => $term->id, 'courseid' => self::$courses[$code], 'section' => $section,
                'facultyid' => self::$faculty[$teacher], 'capacity' => $seats, 'moodlecourseid' => $course->id, 'status' => 'open',
                'meetdays' => $days ?: null, 'starttime' => $from ?: null, 'endtime' => $to ?: null, 'room' => $room]);
            audit::log('create', 'offering', $id);
            self::$offers[$offset . '|' . $code . '/' . $section] = registration::offer($id);
            if ($offset === 0) {
                self::course_content($course, $title);
            }
            $teacherid = $DB->get_field('user', 'id', ['username' => $teacher]);
            self::$g->enrol_user($teacherid, $course->id, $teacher === 'pia' ? 'teacher' : 'editingteacher');
            if ($second !== '') {
                self::$g->enrol_user($DB->get_field('user', 'id', ['username' => $second]), $course->id, 'editingteacher');
            }
        }
        if ($offset === 1) {
            // A Moodle course that no section uses yet, for a new section to link to.
            self::$at->create_course_with_fields(['fullname' => 'BIO105 - Environmental Biology (' . $term->name . ')',
                'shortname' => 'BIO105-01-' . $short, 'category' => $category->id, 'startdate' => $term->startdate,
                'enddate' => $term->enddate, 'code' => 'BIO105 ' . $short, 'hours' => 4, 'hourtype' => 'credit',
                'termid' => self::$atterms[$offset]->id]);
        }
    }

    /**
     * Named sections and a few activities, so that a course of the running term looks like one.
     *
     * @param stdClass $course
     * @param string $title
     */
    protected static function course_content(stdClass $course, string $title): void {
        global $DB;
        foreach ([1 => 'Getting started', 2 => 'Weeks 1 to 5', 3 => 'Weeks 6 to 10', 4 => 'Weeks 11 to 16'] as $number => $name) {
            $DB->set_field('course_sections', 'name', $name, ['course' => $course->id, 'section' => $number]);
        }
        self::$g->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Syllabus',
            'intro' => 'What this course covers and how it is graded.',
            'content' => '<p>The syllabus for ' . s($title) . '. This is a made-up course.</p>']);
        // No graded activity here: a second grade item would double the maximum of the course total, and halve every percent.
        self::$g->create_module('page', ['course' => $course->id, 'section' => 3, 'name' => 'Reading list',
            'intro' => 'What to read before the midterm.', 'content' => '<p>Chapters 1 to 6 of the course book.</p>']);
        rebuild_course_cache($course->id, true);
    }

    /**
     * The offering of a course in a term.
     *
     * @param int $offset
     * @param string $entry code, or code/section
     * @return stdClass
     */
    public static function offer(int $offset, string $entry): stdClass {
        return self::$offers[$offset . '|' . (str_contains($entry, '/') ? $entry : $entry . '/01')];
    }

    /**
     * A student's record as it is now.
     *
     * @param string $username
     * @return stdClass
     */
    public static function student(string $username): stdClass {
        return students::get(self::$students[$username]);
    }

    /**
     * A Moodle user's id.
     *
     * @param string $username
     * @return int
     */
    public static function uid(string $username): int {
        global $DB;
        return (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
    }

    /**
     * The registration of a student in a term's section.
     *
     * @param string $username
     * @param int $offset
     * @param string $entry
     * @return int
     */
    public static function reg(string $username, int $offset, string $entry): int {
        global $DB;
        return (int)$DB->get_field(registration::REG, 'id', ['studentid' => self::$students[$username],
            'offerid' => self::offer($offset, $entry)->id], MUST_EXIST);
    }

    /**
     * Let a registration or a withdrawal through in a term whose dates would stop it today. Only the date rules change, and
     * only while the work runs: the student would have done this while the term's dates allowed it.
     *
     * @param int $offset
     * @param string $action add or withdraw
     * @param callable $work
     * @return mixed
     */
    public static function window(int $offset, string $action, callable $work) {
        global $DB;
        $id = self::$terms[$offset]->id;
        $kept = $DB->get_record('local_studentrecords_term', ['id' => $id], 'id, adddropend, withdrawby');
        $today = catalog::date_to_time(catalog::time_to_date(time()));
        $DB->update_record('local_studentrecords_term', (object)['id' => $id,
            'adddropend' => $action === 'add' ? $today + 2 * DAYSECS : $today - 2 * DAYSECS, 'withdrawby' => $today + 2 * DAYSECS]);
        try {
            return $work();
        } finally {
            $DB->update_record('local_studentrecords_term', $kept);
        }
    }

    /**
     * The personal details of a student's file.
     *
     * @param string $username
     * @param array $details
     * @return array fields
     */
    protected static function personal(string $username, array $details): array {
        $street = ['Maple Street', 'River Road', 'Oak Avenue', 'Mill Lane', 'Prairie Drive', 'Church Street', 'Lake Court'];
        $city = self::CITIES[crc32($username) % 5];
        $number = mt_rand(100, 1990);
        $f1 = !empty($details['f1']);
        $dob = catalog::date_to_time(sprintf('%04d-%02d-%02d', mt_rand(1988, 2006), mt_rand(1, 12), mt_rand(1, 28)));
        return ['dob' => $dob, 'phone' => '(618) 555-01' . mt_rand(20, 99),
            'usaddress' => $number . ' ' . $street[mt_rand(0, 6)] . "\n" . $city . ', IL 629' . mt_rand(10, 98),
            'homeaddress' => $f1 ? "2-14-7 Sakura-cho\nKanazawa, Ishikawa 920-0000" : null, 'homecountry' => $f1 ? 'JP' : 'US',
            'residency' => $f1 ? 'international' : (mt_rand(0, 4) ? 'instate' : 'outofstate'),
            'citizenship' => $f1 ? 'other' : (mt_rand(0, 9) ? 'citizen' : 'permanent'), 'firstgen' => ['yes', 'no', 'unknown'][mt_rand(0, 2)],
            'highschool' => $f1 ? 'Kanazawa Minami High School (made up)' : $city . ' High School', 'hsyear' => (string)mt_rand(2010, 2024)];
    }

    /**
     * Make the file of a new student, as Admissions would: an applicant in a program.
     *
     * @param string $username
     * @param string $first
     * @param string $last
     * @param string $program
     * @param int $admit term offset
     * @param string $status
     * @param array $details preferred, middle, load, ferpaoptout, f1
     * @return stdClass the record
     */
    public static function applicant(string $username, string $first, string $last, string $program, int $admit, string $status, array $details = []): stdClass {
        global $DB;
        // An automatic ID has the year it was given in. Those who started earlier show the year they started.
        $year = userdate(min(time(), (int)self::$terms[$admit]->startdate), '%Y', catalog::timezone(), false);
        $f1 = !empty($details['f1']);
        $id = students::save([
            'userid' => self::uid($username), 'studentid' => self::next_id($year), 'legalfirstname' => $first,
            'legalmiddlename' => $details['middle'] ?? null, 'legallastname' => $last, 'preferredname' => $details['preferred'] ?? null,
            'email' => $username . '@example.com', 'programid' => self::$programs[$program], 'admittermid' => self::$terms[$admit]->id,
            'catalogyear' => ferpa::academic_year(catalog::time_to_date((int)self::$terms[$admit]->startdate), 'August 1'), 'status' => $status, 'loadtype' => $details['load'] ?? 'fulltime',
            'advisorid' => self::$faculty[$program === 'AS-BUS' ? 'carl' : ($program === 'MS-LDR' ? 'carl' : 'pia')],
            'ferpaoptout' => $details['ferpaoptout'] ?? 0, 'isf1' => $f1 ? 1 : 0, 'visatype' => $f1 ? 'F-1' : null,
            'sevisid' => $f1 ? 'N0000912345' : null] + self::personal($username, $details));
        self::$students[$username] = $id;
        $student = students::get($id);
        $DB->set_field('user', 'idnumber', $student->studentid, ['id' => $student->userid]);
        records::save('local_studentrecords_emerg', ['studentid' => $id, 'name' => 'Pat ' . $last, 'relationship' => 'Parent',
            'phone' => '(618) 555-01' . mt_rand(20, 99), 'email' => 'family.' . $username . '@example.com', 'active' => 1, 'sortorder' => 1],
            'form', (int)$student->userid);
        records::save('local_studentrecords_educ', ['studentid' => $id, 'school' => $student->highschool,
            'credential' => 'High school diploma', 'completed' => catalog::date_to_time($student->hsyear . '-06-01'),
            'country' => $f1 ? 'JP' : 'US', 'active' => 1], 'form', (int)$student->userid);
        $sex = ['female', 'male'][crc32($first) % 2];
        records::save('local_studentrecords_demog', ['studentid' => $id, 'sex' => $sex, 'hispanic' => ['yes', 'no', 'no', 'no'][mt_rand(0, 3)],
            'races' => ['white', 'black', 'asian', 'white,black', 'aian', 'notreported'][mt_rand(0, 5)]], 'form', (int)$student->userid);
        return $student;
    }

    /**
     * The next student ID of a year.
     *
     * @param string $year
     * @return string
     */
    protected static function next_id(string $year): string {
        global $DB;
        $count = $DB->count_records_select(students::TABLE, $DB->sql_like('studentid', '?'), ['RVC-' . $year . '-%']);
        return admissions::format_id('RVC', $year, $count + 1, 4, '-');
    }

    /**
     * Tick off the checklist of a student.
     *
     * @param stdClass $student
     * @param string[] $missing short names of the documents still missing
     * @param int $received when the others came in
     */
    public static function checklist(stdClass $student, array $missing, int $received): void {
        $items = [];
        foreach (admissions::checklist($student) as $doc) {
            $key = array_search((int)$doc->id, self::$docs, true);
            if ($key === 'essay' && !in_array('essay', $missing, true)) {
                $items[$doc->id] = ['state' => 'waived', 'statedate' => $received, 'note' => 'Not needed for this program'];
            } else if (!in_array($key, $missing, true)) {
                $items[$doc->id] = ['state' => 'received', 'statedate' => $received, 'note' => ''];
            }
        }
        admissions::save_checklist($student, $items);
    }

    /**
     * Who starts in a term: applied, was admitted and has a file.
     *
     * @param int $offset
     */
    public static function admit(int $offset): void {
        $names = array_keys(array_filter(self::STUDENTS, fn($s) => $s[3] === $offset));
        clock::at(self::day($offset, -75), 'nina', function () use ($names, $offset) {
            foreach ($names as $username) {
                [$first, $last, $program, , , $details] = self::STUDENTS[$username];
                $student = self::applicant($username, $first, $last, $program, $offset, 'applicant', $details);
                self::checklist($student, [], time());
            }
        });
        clock::at(self::day($offset, -60), 'nina', function () use ($names) {
            foreach ($names as $username) {
                admissions::record_decision(self::student($username), ['decision' => 'admitted', 'decisiondate' => time(),
                    'termid' => self::student($username)->admittermid, 'reason' => '', 'conditions' => '']);
            }
        });
        self::transfers($names, $offset);
    }

    /**
     * The classes of a term: everyone registers, in three groups a few days apart.
     *
     * @param int $offset
     */
    public static function registrations(int $offset): void {
        $groups = [];
        foreach (self::HISTORY as $username => $terms) {
            if (isset($terms[$offset])) {
                $groups[crc32($username) % 3][] = $username;
            }
        }
        ksort($groups);
        foreach ($groups as $i => $names) {
            // Next term's registration is under way now.
            $when = $offset === 1 ? self::ago(21 - 3 * $i) : self::day($offset, -24 + 6 * $i);
            clock::at($when, 'rosa', function () use ($names, $offset) {
                self::window($offset, 'add', function () use ($names, $offset) {
                    foreach ($names as $username) {
                        foreach (array_keys(self::HISTORY[$username][$offset]) as $entry) {
                            $student = self::student($username);
                            try {
                                // Alma takes the next class while the one before it still runs: staff let that through.
                                $reason = $username === 'alma' && $entry === 'LDR510' ? 'Finishing LDR501 this term' : '';
                                registration::add($student, (int)self::offer($offset, $entry)->id, $reason !== '', $reason);
                            } catch (moodle_exception $e) {
                                throw new coding_exception("$username can't take $entry in term $offset: " . $e->getMessage());
                            }
                        }
                        // The first registration makes the admitted student enrolled.
                        if (self::student($username)->status === 'admitted') {
                            students::save(['id' => self::$students[$username], 'status' => 'enrolled',
                                'statusnote' => 'Registered for ' . self::$terms[$offset]->name]);
                        }
                    }
                });
            });
        }
    }

    /**
     * Pay a student's balance.
     *
     * @param string $username
     * @param int $offset
     * @param float|null $amount default all of it
     */
    public static function pay(string $username, int $offset, ?float $amount = null): void {
        $student = self::student($username);
        $amount = $amount ?? billing::balance((int)$student->id);
        if ($amount > 0.004) {
            $method = ['check', 'card', 'banktransfer', 'cash'][crc32($username . $offset) % 4];
            payments::record($student, $amount, $method, ['termid' => self::$terms[$offset]->id,
                'reference' => $method === 'check' ? 'Check ' . mt_rand(1100, 4800) : ($method === 'card' ? 'Card ending ' . mt_rand(1000, 9999) : '')]);
        }
    }

    /**
     * Make the new students of a term active on its first day.
     *
     * @param int $offset
     */
    public static function first_day(int $offset): void {
        clock::at(self::day($offset, 0), 'rosa', function () use ($offset) {
            foreach (array_keys(self::$students) as $username) {
                $student = self::student($username);
                if ($student->status === 'enrolled' && !empty(self::HISTORY[$username][$offset])) {
                    students::save(['id' => $student->id, 'status' => 'active', 'statusnote' => 'Started classes']);
                }
            }
        });
    }

    /**
     * Final grades of a term, entered by the Registrar.
     *
     * @param int $offset
     * @param string[] $leave section codes whose grades come another way
     */
    public static function grades(int $offset, array $leave = []): void {
        clock::at(self::after($offset, 5), 'rosa', function () use ($offset, $leave) {
            foreach (self::HISTORY as $username => $terms) {
                foreach ($terms[$offset] ?? [] as $entry => $grade) {
                    if ($grade !== null && !in_array($entry, $leave, true)) {
                        registration::set_grade(self::reg($username, $offset, $entry), $grade, null, 'staff');
                    }
                }
            }
        });
    }

    /**
     * Run and confirm the standing of a term.
     *
     * @param int $offset
     */
    public static function standing(int $offset): void {
        global $DB;
        clock::at(self::after($offset, 20), 'rosa', function () use ($DB, $offset) {
            standing::run((int)self::$terms[$offset]->id);
            standing::confirm(array_keys($DB->get_records(standing::TABLE, ['termid' => self::$terms[$offset]->id, 'state' => 'proposed'])));
        });
    }

    /**
     * Past terms, oldest first.
     */
    public static function history(): void {
        foreach ([-3, -2, -1] as $offset) {
            self::admit($offset);
            self::registrations($offset);
            clock::at(self::day($offset, -5), 'felix', function () use ($offset) {
                foreach (self::HISTORY as $username => $terms) {
                    if (isset($terms[$offset])) {
                        self::pay($username, $offset);
                    }
                }
            });
            self::first_day($offset);
            if ($offset === -1) {
                self::summer_events();
            }
            // One section of the summer has its grades posted by its teacher, and one spring section takes them from Moodle.
            $leave = ['-1' => ['COM150'], '-2' => ['ACC201']][(string)$offset] ?? [];
            self::grades($offset, $leave);
            if ($offset === -2) {
                self::from_moodle();
            }
            if ($offset === -1) {
                self::teacher_posts();
            }
            if ($offset !== -1) {
                self::standing($offset);
            }
        }
    }

    /**
     * Spring's ACC201: the course totals are in the gradebook and the Registrar fetches them.
     */
    protected static function from_moodle(): void {
        $offer = self::offer(-2, 'ACC201');
        clock::at(self::after(-2, 4), 'rosa', function () use ($offer) {
            foreach (self::HISTORY as $username => $terms) {
                if (isset($terms[-2]['ACC201'])) {
                    self::$at->set_course_total((int)$offer->moodlecourseid, self::uid($username), self::PERCENT[$terms[-2]['ACC201']]);
                }
            }
            registration::grades_from_moodle((int)$offer->id);
        });
    }

    /**
     * Summer's COM150: Dr. Novak posts the final grades, and later asks to change one.
     */
    protected static function teacher_posts(): void {
        $offer = self::offer(-1, 'COM150');
        $courseid = (int)$offer->moodlecourseid;
        clock::at(self::after(-1, 3), 'irene', function () use ($courseid) {
            foreach (self::HISTORY as $username => $terms) {
                if (isset($terms[-1]['COM150'])) {
                    self::$at->set_course_total($courseid, self::uid($username), self::PERCENT[$terms[-1]['COM150']]);
                    posting::post($courseid, self::uid($username));
                }
            }
        });
        clock::at(self::after(-1, 40), 'irene', function () {
            srpost::request_change(self::reg('theo', -1, 'COM150'), 'B+', 'The final speech was scored from the wrong rubric.');
        });
    }

    /**
     * What happened during the summer term: someone left, someone went on leave.
     */
    protected static function summer_events(): void {
        // Wes withdrew from his class, got half the tuition back, and left the college.
        clock::at(self::day(-1, 12), 'rosa', function () {
            self::window(-1, 'withdraw', fn() => registration::end(self::reg('wes', -1, 'CIS120'), 'withdraw', false,
                'Moving out of state'));
            students::save(['id' => self::$students['wes'], 'status' => 'withdrawn', 'withdrawdate' => time(), 'lastattended' => time() - 3 * DAYSECS,
                'statusnote' => 'Moved out of state']);
        });
        clock::at(self::day(-1, 14), 'felix', function () {
            $id = self::refund_of('wes');
            refunds::refigure($id, self::day(-1, 12));
            refunds::decide($id, true, 'Figured on the day he withdrew.');
        });
        clock::at(self::day(-1, 20), 'felix', function () {
            $student = self::student('wes');
            refunds::payout($student, -billing::balance((int)$student->id), 'check', ['reference' => 'Check 7002']);
        });
        // Leah went on leave and asked for her money back, which Finance refused.
        clock::at(self::day(-1, 18), 'rosa', function () {
            self::window(-1, 'withdraw', fn() => registration::end(self::reg('leah', -1, 'CIS120'), 'withdraw', false, 'Family leave'));
            students::save(['id' => self::$students['leah'], 'status' => 'onleave', 'statusnote' => 'Family leave, back next year']);
        });
        clock::at(self::day(-1, 21), 'felix', function () {
            $id = self::refund_of('leah');
            refunds::refigure($id, self::day(-1, 18));
            refunds::decide($id, false, 'Her request came in after the refund date.');
        });
    }

    /**
     * The newest refund of a student.
     *
     * @param string $username
     * @return int
     */
    public static function refund_of(string $username): int {
        global $DB;
        $rows = $DB->get_records(refunds::REFUNDS, ['studentid' => self::$students[$username]], 'id DESC', 'id', 0, 1);
        return (int)reset($rows)->id;
    }

    /**
     * Give a school plan to a student and date its down payment.
     *
     * @param string $username
     * @param string $key
     * @param int $when
     * @return int
     */
    protected static function plan(string $username, string $key, int $when): int {
        global $DB;
        $template = (array)$DB->get_record(plans::PLANS, ['id' => self::$plans[$key]], '*', MUST_EXIST);
        $student = self::student($username);
        $id = plans::create($student, (int)self::$terms[0]->id, self::$plans[$key], $template);
        $DB->set_field(plans::INSTS, 'duedate', catalog::date_to_time(catalog::time_to_date($when)), ['stuplanid' => $id, 'seq' => 0]);
        plans::send_agreement($student, $id);
        return $id;
    }

    /**
     * The running term up to today: registrations, money, aid, holds and who left.
     */
    public static function current(): void {
        self::admit(0);
        self::registrations(0);
        clock::at(self::day(0, -5), 'felix', function () {
            foreach (['marco', 'priya', 'eli', 'yuki', 'kira', 'alma', 'reed', 'cleo', 'dante', 'sean', 'aiden', 'brooke', 'kofi',
                'jada', 'caleb', 'daisy', 'emil', 'lila', 'fiona', 'gabe', 'hana', 'isaac'] as $username) {
                self::pay($username, 0);
            }
            $first = self::day(0, -5);
            foreach (['ben', 'carmen', 'dara'] as $username) {
                self::plan($username, 'monthly', $first);
            }
        });
        clock::at(self::day(0, -4), 'felix', function () {
            foreach (['ben', 'carmen', 'dara'] as $username) {
                $student = self::student($username);
                $plans = plans::for_student((int)$student->id);
                $plan = reset($plans);
                $down = reset($plan->insts);
                payments::record($student, (float)$down->amount, 'card', ['termid' => self::$terms[0]->id,
                    'reference' => 'Down payment', 'note' => 'Plan down payment']);
            }
        });
        self::aid();
        clock::at(self::day(0, -2), 'felix', function () {
            foreach (['sam', 'uma', 'theo', 'nadia', 'vince'] as $username) {
                foreach (payments::pending((int)self::$students[$username]) as $row) {
                    payments::grant((int)$row->id, 'Aid is on its way');
                }
            }
        });
        self::first_day(0);
        // Ben keeps up with his plan. Carmen's aid has paid hers off, and Dara has stopped paying.
        foreach ([31, 62] as $day) {
            clock::at(self::day(0, $day), 'felix', function () {
                $student = self::student('ben');
                $next = plans::next_due((int)$student->id);
                if ($next) {
                    payments::record($student, (float)$next->amount - (float)$next->paid, 'card', ['termid' => self::$terms[0]->id,
                        'reference' => 'Plan payment']);
                }
            });
        }
        self::leavers();
        self::holds();
    }

    /**
     * Aid: year details, awards in each status, money paid out.
     */
    protected static function aid(): void {
        $year = aidhelp::award_year(time());
        $term = (int)self::$terms[0]->id;
        clock::at(self::day(0, -40), 'gwen', function () use ($year, $term) {
            foreach ([
                ['carmen', 16400, 2100, 'dependent', 2, 'complete', 1], ['dan', 16400, 900, 'independent', 2, 'none', 1],
                ['nell', 15800, 1200, 'dependent', 2, 'none', 1], ['sam', 15800, 3200, 'dependent', 1, 'none', 1],
                ['uma', 16400, 1500, 'independent', 1, 'none', 0], ['theo', 16400, 800, 'dependent', 2, 'selected', 0],
                ['nadia', 16400, 2500, 'dependent', 2, 'none', 0], ['vince', 15800, 2800, 'independent', 1, 'none', 1],
                ['eli', 16400, 4200, 'dependent', 1, 'complete', 0], ['ben', 16400, 1900, 'dependent', 2, 'none', 0],
                ['marco', 16400, 700, 'independent', 3, 'complete', 1], ['cleo', 7200, 600, 'independent', 1, 'none', 0],
                ['yuki', 16400, 9000, 'dependent', 2, 'none', 0],
            ] as [$username, $coa, $sai, $dependency, $level, $verification, $counseling]) {
                aidhelp::save_year(self::student($username), ['awardyear' => $year, 'coa' => $coa, 'sai' => $sai, 'dependency' => $dependency,
                    'gradelevel' => $level, 'verification' => $verification, 'counseling' => $counseling]);
            }
            $awards = [
                ['carmen', 'pell', 2000, 'accepted'], ['carmen', 'dlsub', 1750, 'accepted'], ['dan', 'pell', 1850, 'accepted'],
                ['dan', 'dlsub', 1732, 'accepted'], ['dan', 'dlunsub', 990, 'accepted'], ['dan', 'fseog', 400, 'accepted'],
                ['nell', 'pell', 1600, 'accepted'], ['nell', 'dlsub', 1500, 'accepted'], ['sam', 'dlsub', 1500, 'accepted'],
                ['sam', 'dlunsub', 2000, 'accepted'], ['uma', 'pell', 1500, 'accepted'], ['uma', 'state', 500, 'accepted'],
                ['theo', 'pell', 1800, 'accepted'], ['nadia', 'dlsub', 1200, 'accepted'], ['nadia', 'fws', 1500, 'accepted'],
                ['vince', 'dlunsub', 1000, 'accepted'], ['eli', 'pell', 1500, 'declined'], ['eli', 'institutional', 750, 'offered'],
                ['yuki', 'institutional', 1000, 'accepted'], ['yuki', 'private', 500, 'offered'], ['ben', 'dlsub', 1000, 'cancelled'],
                ['ben', 'pell', 1200, 'offered'], ['marco', 'pell', 1100, 'accepted'], ['cleo', 'pell', 900, 'accepted'],
            ];
            foreach ($awards as [$username, $fund, $amount, $status]) {
                aid::save_award(self::student($username), ['awardyear' => $year, 'termid' => $term, 'fund' => $fund, 'amount' => $amount,
                    'status' => $status, 'note' => $status === 'cancelled' ? 'Student asked to cancel it' : '']);
            }
        });
        clock::at(self::day(0, 3), 'gwen', function () {
            foreach (['carmen', 'dan', 'nell', 'marco', 'yuki', 'uma'] as $username) {
                foreach (aid::awards((int)self::$students[$username]) as $award) {
                    $paidout = $award->status === 'accepted' && aid::credit_kind($award->fund) !== null &&
                        !($username === 'dan' && $award->fund === 'fseog') && !($username === 'uma' && $award->fund === 'pell');
                    if ($paidout) {
                        aid::disburse((int)$award->id, (float)$award->amount, time());
                    }
                }
            }
        });
        clock::at(self::day(0, 4), 'felix', function () {
            foreach (['dan', 'nell'] as $username) {
                self::pay($username, 0);
            }
        });
        clock::at(self::day(0, 9), 'gwen', function () use ($year) {
            aidhelp::send_letter(self::student('carmen'), $year);
            aidhelp::send_letter(self::student('sam'), $year);
        });
    }

    /**
     * Who left the running term, with aid paid out, and what the school does about it.
     */
    protected static function leavers(): void {
        global $DB;
        foreach (['dan' => 40, 'nell' => 26] as $username => $day) {
            clock::at(self::day(0, $day), 'rosa', function () use ($username, $day) {
                self::window(0, 'withdraw', function () use ($username) {
                    foreach (array_keys(self::HISTORY[$username][0]) as $entry) {
                        registration::end(self::reg($username, 0, $entry), 'withdraw', false, 'Left the college');
                    }
                });
                students::save(['id' => self::$students[$username], 'status' => 'withdrawn', 'withdrawdate' => self::day(0, $day),
                    'lastattended' => self::day(0, $day - 2), 'statusnote' => 'Told the Registrar about leaving']);
            });
        }
        // The refunds of those withdrawals, figured on the day they left.
        clock::at(self::day(0, 43), 'felix', function () use ($DB) {
            foreach (['dan' => 40, 'nell' => 26] as $username => $day) {
                foreach ($DB->get_records(refunds::REFUNDS, ['studentid' => self::$students[$username]]) as $refund) {
                    refunds::refigure((int)$refund->id, self::day(0, $day));
                    if ($DB->get_field(refunds::REFUNDS, 'status', ['id' => $refund->id]) === 'proposed') {
                        refunds::decide((int)$refund->id, true, 'Figured on the day the student left.');
                    }
                }
            }
        });
        // Nell's return of aid went to Finance. Dan's worksheet is left for Financial Aid to open.
        clock::at(self::day(0, 30), 'gwen', function () {
            aidhelp::send_return(self::student('nell'), (int)self::$terms[0]->id);
        });
    }

    /**
     * Holds of different kinds.
     */
    protected static function holds(): void {
        clock::at(self::day(0, 35), 'rosa', function () {
            academic::place_hold(self::student('ivy'), ['holdtype' => 'financial', 'reason' => 'Unpaid balance from this term',
                'blocksreg' => 1, 'startdate' => time()]);
            academic::place_hold(self::student('theo'), ['holdtype' => 'records', 'reason' => 'High school transcript copy is not readable',
                'blocksreg' => 0, 'startdate' => time()]);
            academic::place_hold(self::student('sean'), ['holdtype' => 'academic', 'reason' => 'Must meet with his advisor',
                'blocksreg' => 0, 'startdate' => time(), 'enddate' => self::after(0, 30)]);
            academic::place_hold(self::student('uma'), ['holdtype' => 'admissions', 'reason' => 'Waiting for one more transcript',
                'blocksreg' => 0, 'startdate' => time()]);
        });
        $id = clock::at(self::day(0, 20), 'rosa', fn() => academic::place_hold(self::student('ben'), ['holdtype' => 'conduct',
            'reason' => 'Library books not returned', 'blocksreg' => 1, 'startdate' => time()]));
        clock::at(self::day(0, 27), 'rosa', fn() => academic::release_hold($id, 'Books returned'));
    }

    /**
     * Attendance of the running term: meetings all term, marks up to last week.
     */
    public static function attendance(): void {
        $weekday = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5];
        // BIO105 has no meetings yet, so a teacher can add a weekly schedule. PSY101 is online.
        $sections = ['ENG101', 'ENG102', 'MAT110', 'MAT110/02', 'BUS101', 'ACC201', 'ECO201', 'MKT210', 'CIS120', 'PHL101', 'PHL102', 'LDR501'];
        $leaving = ['dan' => 38, 'nell' => 24];
        $rate = ['sean' => 58, 'vince' => 80, 'dara' => 82];
        $last = catalog::time_to_date(time() - 8 * DAYSECS);
        clock::at(self::ago(2), 'admin', function () use ($weekday, $sections, $leaving, $rate, $last) {
            foreach ($sections as $entry) {
                $offer = self::offer(0, $entry);
                clock::become(self::COURSES[explode('/', $entry)[0]][8]);
                $days = array_map(fn($d) => $weekday[$d], explode(',', (string)$offer->meetdays));
                $clock = $offer->unittype === 'clock';
                attendance::add_weekly((int)$offer->id, $days, $clock ? 4 : (count($days) === 1 ? 2.75 : 1.25));
                foreach (attendance::meetings((int)$offer->id) as $meeting) {
                    $day = catalog::time_to_date((int)$meeting->meetingdate);
                    if ($day > $last) {
                        continue;
                    }
                    $marks = [];
                    foreach (attendance::sheet($meeting) as $regid => $row) {
                        $username = array_search((int)$row->sid, self::$students, true);
                        $left = isset($leaving[$username]) ? catalog::time_to_date(self::day(0, $leaving[$username])) : '';
                        if ($left !== '' && $day > $left) {
                            continue;
                        }
                        $chance = $rate[$username] ?? 96;
                        $roll = mt_rand(1, 100);
                        $status = $roll <= $chance ? ($roll > $chance - 4 ? 'late' : 'present') : ($roll > 97 ? 'excused' : 'absent');
                        $marks[$regid] = ['status' => $status, 'hours' => $status === 'late' ? (float)$meeting->hours - 0.25 : (float)$meeting->hours,
                            'note' => $status === 'excused' ? 'Doctor\'s note' : ''];
                    }
                    attendance::save_marks((int)$meeting->id, $marks);
                }
            }
        });
    }

    /**
     * Course totals in the gradebook of the running term, for Get final grades from Moodle and Post final grades.
     */
    public static function gradebook(): void {
        $totals = ['ENG102' => 86, 'ACC201' => 81, 'ECO201' => 77, 'MKT210' => 90, 'CIS120' => 92, 'MAT110' => 74, 'BUS101' => 83];
        $low = ['sean' => 58, 'dara' => 66, 'vince' => 64];
        foreach ($totals as $code => $base) {
            $courseid = (int)self::offer(0, $code)->moodlecourseid;
            $skip = true;
            foreach (self::HISTORY as $username => $terms) {
                if (array_key_exists($code, $terms[0] ?? []) && !in_array($username, ['dan', 'nell'], true)) {
                    // One student in each section has no course total yet.
                    if ($skip && $code !== 'MAT110') {
                        $skip = false;
                        continue;
                    }
                    self::$at->set_course_total($courseid, self::uid($username), $low[$username] ?? $base + mt_rand(-9, 9));
                }
            }
        }
        // Sean is at risk in his evening section too.
        self::$at->set_course_total((int)self::offer(0, 'MAT110/02')->moodlecourseid, self::uid('sean'), 54);
        self::$at->set_course_total((int)self::offer(0, 'MAT110/02')->moodlecourseid, self::uid('uma'), 88);
        // Yuki finished ENG102 early, and Dr. Novak has already posted her grade.
        clock::at(self::ago(3), 'irene', fn() => posting::post((int)self::offer(0, 'ENG102')->moodlecourseid, self::uid('yuki')));
    }

    /**
     * Graduation: one conferred, one approved on condition, one waiting for review. Gina can still apply.
     */
    public static function graduation(): void {
        $hank = clock::at(self::after(-1, 2), 'rosa', fn() => graduation::apply(self::student('hank'), (int)self::$terms[-1]->id, 'Last classes finished'));
        clock::at(self::after(-1, 8), 'mona', fn() => graduation::review($hank, true, 'Audit complete', 'Henry Kofi Osei'));
        clock::at(self::after(-1, 15), 'rosa', fn() => graduation::confer($hank, self::after(-1, 15),
            'Associate of Science in Business Administration', 'Magna cum laude'));
        $priya = clock::at(self::day(0, 21), 'rosa', fn() => graduation::apply(self::student('priya'), (int)self::$terms[0]->id,
            'Finishing with MKT220'));
        clock::at(self::day(0, 30), 'mona', fn() => graduation::review($priya, true, ''));
        clock::at(self::ago(9), 'rosa', fn() => graduation::apply(self::student('marco'), (int)self::$terms[0]->id,
            'Two classes left this term'));
        // Kira works in the office that reviews her own application, so someone else must decide it.
        clock::at(self::ago(8), 'rosa', fn() => graduation::apply(self::student('kira'), (int)self::$terms[1]->id, 'Planning ahead'));
    }

    /**
     * Applicants and the rest of the admissions pipeline.
     */
    public static function pipeline(): void {
        $next = 1;
        clock::at(self::ago(40), 'nina', function () use ($next) {
            foreach (self::PIPELINE as $username => [$first, $last, $program, $status]) {
                $state = $status === 'inquiry' ? 'inquiry' : 'applicant';
                $student = self::applicant($username, $first, $last, $program, $next, $state);
                if ($status === 'inquiry') {
                    continue;
                }
                $missing = ['ada' => ['transcripts', 'id'], 'noah' => ['immunization']][$username] ?? [];
                self::checklist($student, $missing, time());
            }
        });
        clock::at(self::ago(16), 'nina', function () {
            $decide = function (string $username, string $decision, array $extra = []) {
                admissions::record_decision(self::student($username), $extra + ['decision' => $decision, 'decisiondate' => time(),
                    'termid' => self::$terms[1]->id, 'reason' => '', 'conditions' => '']);
            };
            $decide('tara', 'waitlisted', ['reason' => 'The spring phlebotomy class is full.']);
            $decide('evan', 'waitlisted', ['reason' => 'The spring phlebotomy class is full.']);
            $decide('zoe', 'admitted');
            $decide('mina', 'admitted', ['conditions' => 'Send a final high school transcript before classes start.']);
            $decide('kai', 'admitted');
            $decide('rex', 'denied', ['reason' => 'High school GPA below what the program asks for.']);
            $decide('faye', 'deferred', ['reason' => 'Asked to start a term later.', 'termid' => self::$terms[1]->id]);
        });
    }

    /**
     * Next term: who has registered, the full section with its waitlist, and two drops that left a refund to decide.
     */
    public static function next_term(): void {
        // The section is taken on the day it fills up. Registrations come first, then Jonah on his own.
        clock::at(self::ago(20), 'nina', function () {
            [$first, $last, $program, , , $details] = self::STUDENTS['jonah'];
            $student = self::applicant('jonah', $first, $last, $program, 1, 'applicant', $details);
            self::checklist($student, [], time());
            admissions::record_decision($student, ['decision' => 'admitted', 'decisiondate' => time(), 'termid' => self::$terms[1]->id,
                'reason' => '', 'conditions' => '']);
        });
        self::registrations(1);
        clock::at(self::ago(12), 'jonah', function () {
            $student = self::student('jonah');
            foreach (['ENG101', 'MAT110', 'BUS101', 'COM150'] as $code) {
                selfreg::add($student, (int)self::offer(1, $code)->id);
            }
            selfreg::confirm($student, (int)self::$terms[1]->id);
        });
        clock::at(self::ago(11), 'rosa', fn() => students::save(['id' => self::$students['jonah'], 'status' => 'enrolled',
            'statusnote' => 'Registered online']));
        foreach (['zoe' => 9, 'mina' => 8, 'kai' => 7] as $username => $days) {
            clock::at(self::ago($days), $username, function () use ($username) {
                selfreg::wait(self::student($username), (int)self::offer(1, 'COM150')->id);
            });
        }
        clock::at(self::ago(6), 'zoe', function () {
            foreach (['ENG101', 'BUS101'] as $code) {
                selfreg::add(self::student('zoe'), (int)self::offer(1, $code)->id);
            }
        });
        clock::at(self::ago(3), 'rosa', function () {
            registration::end(self::reg('eli', 1, 'ENG101'), 'drop', false, 'Changed his mind');
            registration::end(self::reg('kira', 1, 'PSY101'), 'drop', false, 'Too much with work');
        });
        // Vince drops today, which opens a seat: the first on the waitlist has two days to take it.
        clock::at(time(), 'rosa', function () {
            registration::end(self::reg('vince', 1, 'COM150'), 'drop', false, 'Too many hours at work');
        });
    }

    /**
     * A withdrawal this week that was too late for money back.
     */
    public static function late_withdrawal(): void {
        clock::at(self::ago(1), 'rosa', function () {
            registration::end(self::reg('eli', 0, 'BIO105'), 'withdraw', false, 'Could not keep up with the lab');
        });
    }

    /**
     * Grade changes: one approved, one rejected, one left waiting (Theo's, from Dr. Novak).
     */
    public static function grade_changes(): void {
        $approved = clock::at(self::after(-2, 30), 'rosa', fn() => academic::request_change(self::reg('gina', -2, 'ECO201'), 'B+', null,
            'The final exam was added up wrong.'));
        clock::at(self::after(-2, 32), 'omar', fn() => academic::decide_change($approved, true, 'Checked against the exam.'));
        $rejected = clock::at(self::after(-3, 30), 'tomas', fn() => academic::request_change(self::reg('ben', -3, 'BUS101'), 'B-', null,
            'Late work accepted after the term.'));
        clock::at(self::after(-3, 33), 'omar', fn() => academic::decide_change($rejected, false, 'Late work can\'t change a final grade.'));
        // One on Kira's own grade, and one she asked for herself: someone else must decide both.
        clock::at(self::ago(6), 'rosa', fn() => academic::request_change(self::reg('kira', -2, 'LDR510'), 'A-', null,
            'A missing paper turned up.'));
        clock::at(self::ago(5), 'kira', fn() => academic::request_change(self::reg('aiden', -1, 'ACC202'), 'B', null,
            'The Registrar\'s office entered the wrong letter.'));
    }

    /**
     * The transfer credit of the students who start in a term.
     *
     * @param string[] $names
     * @param int $offset
     */
    protected static function transfers(array $names, int $offset): void {
        clock::at(self::day($offset, -50), 'rosa', function () use ($names) {
            foreach ($names as $username) {
                $kind = self::STUDENTS[$username][5]['transfer'] ?? '';
                if ($kind === '') {
                    continue;
                }
                $student = self::student($username);
                $save = function (array $record) use ($student) {
                    records::save('local_studentrecords_xfer', $record + ['studentid' => $student->id, 'active' => 1, 'awarded' => time(),
                        'countsprogram' => 1, 'ingpa' => 0, 'note' => ''], 'form', (int)$student->userid);
                    audit::log('create', 'student', (int)$student->id, (int)$student->userid, 'transfer credit');
                };
                if ($kind === 'block') {
                    foreach (self::BLOCK as [$course, $credits]) {
                        $save(['institution' => 'Lakeshore Community College (made up)', 'extcourse' => $course, 'credits' => $credits]);
                    }
                } else {
                    $save(['institution' => 'Prairie Community College (made up)', 'extcourse' => 'ENGL 1010 College Writing', 'credits' => 3,
                        'grade' => 'B', 'equivcourseid' => self::$courses['ENG101'], 'ingpa' => 1, 'note' => 'Counts for ENG101']);
                    $save(['institution' => 'Prairie Community College (made up)', 'extcourse' => 'HUM 1100 World Cultures', 'credits' => 3]);
                    $save(['institution' => 'Prairie Community College (made up)', 'extcourse' => 'PE 1010 Fitness', 'credits' => 1,
                        'countsprogram' => 0, 'note' => 'Does not count toward the program']);
                }
            }
        });
    }

    /**
     * FERPA, release authorizations, a letter, documents, saved exports and a few charges of other kinds.
     */
    public static function paperwork(): void {
        // Acknowledgments of this year's notice.
        foreach (['ben', 'carmen', 'priya', 'yuki'] as $i => $username) {
            clock::at(self::day(0, 2 + $i), $username, fn() => ferpa::acknowledge(self::student($username), 'online'));
        }
        clock::at(self::day(0, 6), 'rosa', function () {
            ferpa::acknowledge(self::student('gina'), 'paper', time());
            ferpa::acknowledge(self::student('marco'), 'paper', time());
        });
        // Who may hear what.
        clock::at(self::day(0, 8), 'rosa', function () {
            ferpa::add_release(self::student('ben'), ['recipient' => 'Grace Okoye', 'relationship' => 'Mother', 'scope' => ['grades', 'financial'],
                'details' => 'May call the Business Office about his bill.', 'fromdate' => time(), 'todate' => self::after(1, 30)]);
            ferpa::add_release(self::student('nadia'), ['recipient' => 'Farah Family Trust', 'relationship' => 'Sponsor', 'scope' => ['financial'],
                'details' => '', 'fromdate' => time(), 'todate' => null]);
            $revoked = ferpa::add_release(self::student('theo'), ['recipient' => 'Karl Bauer', 'relationship' => 'Father', 'scope' => ['all'],
                'details' => '', 'fromdate' => time(), 'todate' => null]);
            ferpa::revoke_release($revoked);
        });
        // An enrollment letter and two more for the list.
        clock::at(self::day(0, 24), 'rosa', fn() => letters::issue(self::student('carmen'), 'Riverbend Insurance Group (made up)'));
        clock::at(self::day(0, 33), 'rosa', fn() => letters::issue(self::student('yuki'), ''));
        // Documents on student files.
        foreach (['carmen' => ['High school diploma', 'Photo ID'], 'yuki' => ['Passport page', 'Form I-20', 'Financial statement'],
            'lucy' => ['High school diploma'], 'hank' => ['Transfer evaluation']] as $username => $titles) {
            foreach ($titles as $title) {
                self::document($username, $title);
            }
        }
        // Saved exports.
        clock::at(self::ago(30), 'rosa', fn() => outside::add_starters());
        // Charges and credits of other kinds.
        clock::at(self::day(0, 12), 'felix', function () {
            billing::add_line(['studentid' => self::$students['ben'], 'txntype' => 'charge', 'amount' => 15, 'description' => 'Replacement ID card',
                'termid' => self::$terms[0]->id, 'qualified' => 0, 'refundable' => 0]);
            billing::add_line(['studentid' => self::$students['marco'], 'txntype' => 'credit', 'method' => 'scholarship', 'amount' => -250,
                'description' => 'Riverside Merit Scholarship', 'termid' => self::$terms[0]->id]);
            billing::add_line(['studentid' => self::$students['yuki'], 'txntype' => 'credit', 'method' => 'waiver', 'amount' => -60,
                'description' => 'Technology fee waived', 'termid' => self::$terms[0]->id]);
            $line = billing::add_line(['studentid' => self::$students['priya'], 'txntype' => 'charge', 'amount' => 50, 'description' => 'Graduation fee',
                'termid' => self::$terms[0]->id, 'qualified' => 1, 'refundable' => 0]);
            billing::reverse($line, 'Added to the wrong term');
        });
    }

    /**
     * A small made-up PDF on a student's file.
     *
     * @param string $username
     * @param string $title
     */
    protected static function document(string $username, string $title): void {
        $pdf = new pdf('P', 'mm', 'LETTER');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, $title, 0, 1);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'Sample document for a made-up student of a made-up school.', 0, 1);
        $file = ['contextid' => context_system::instance()->id, 'component' => 'local_studentrecords', 'filearea' => 'studentdocs',
            'itemid' => self::$students[$username], 'filepath' => '/', 'filename' => strtolower(str_replace(' ', '-', $title)) . '.pdf'];
        get_file_storage()->create_file_from_string($file, $pdf->Output('', 'S'));
    }

    /**
     * Official transcripts: one issued from a student's page, and requests in each state but issued.
     */
    public static function transcripts(): void {
        $ask = function (string $username, array $data, int $days) {
            return clock::at(self::ago($days), $username, fn() => requests::create(self::uid($username), $data));
        };
        clock::at(self::after(-1, 20), 'rosa', function () {
            $issue = requests::issue_direct(self::uid('hank'), 'Bluebird Accounting Services (made up)');
            self::$report['Transcript issued to Hank'] = $issue->transcriptno;
        });
        $ask('gina', ['delivery' => 'email', 'recipient' => 'Admissions, Lakeshore State University (made up)',
            'email' => 'admissions@lakeshore.example.com', 'timing' => 'now'], 4);
        clock::at(self::ago(3), 'felix', fn() => self::pay('gina', 0));
        $ask('carmen', ['delivery' => 'mail', 'recipient' => 'Riverbend Insurance Group (made up)',
            'address' => "Tuition Benefits\n400 Main Street\nFairmount, IL 62910", 'timing' => 'now'], 3);
        $ask('priya', ['delivery' => 'pickup', 'recipient' => 'Priya Nair', 'timing' => 'grades'], 7);
        $ask('marco', ['delivery' => 'email', 'recipient' => 'Graduate Office, Prairie University (made up)',
            'email' => 'grad@prairie.example.com', 'timing' => 'degree'], 6);
        $ask('ivy', ['delivery' => 'pickup', 'recipient' => 'Ivy Stone', 'timing' => 'now'], 5);
        $ask('kira', ['delivery' => 'pickup', 'recipient' => 'Kira Olsen', 'timing' => 'now'], 2);
        $cancelled = $ask('theo', ['delivery' => 'mail', 'recipient' => 'Theodore Bauer', 'address' => "18 River Road\nAshby, IL 62920",
            'timing' => 'now'], 11);
        clock::at(self::ago(10), 'rosa', fn() => requests::cancel($cancelled, 'Student asked to cancel it'));
    }

    /**
     * Academic Transcript's own data: students with no file in Student Records, so that the pages for own data have rows.
     */
    public static function own_data(): void {
        global $DB;
        $program = (int)$DB->get_field('local_academictranscript_program', 'id', ['name' => 'Business Administration']);
        $grades = ['ruth' => ['A', 'A-', 'B+', 'A'], 'hugo' => ['B', 'C+', 'B-', 'B'], 'lena' => ['A-', 'B+', 'A', 'I'], 'arlo' => ['C', 'B-', 'F', 'P']];
        clock::at(self::day(0, 5), 'rosa', function () use ($DB, $program, $grades) {
            $i = 0;
            foreach ($grades as $username => $marks) {
                $userid = self::uid($username);
                $i++;
                $DB->set_field('user', 'idnumber', 'RVC-OLD-' . (1000 + $i), ['id' => $userid]);
                self::$at->create_student_record(['userid' => $userid, 'studentid' => 'RVC-OLD-' . (1000 + $i),
                    'dob' => catalog::date_to_time((1990 + $i) . '-0' . $i . '-1' . $i), 'programid' => $program,
                    'qualification' => $username === 'ruth' ? 'Associate of Science in Business Administration' : '',
                    'awarddate' => $username === 'ruth' ? self::after(-1, 15) : null, 'admitterm' => self::$atterms[-3]->id,
                    'status' => $username === 'ruth' ? 'graduated' : 'active']);
                foreach ([[-3, 'ENG101'], [-3, 'BUS101'], [-2, 'ACC201'], [-1, 'COM150']] as $n => [$offset, $code]) {
                    $offer = self::offer($offset, $code);
                    self::$at->create_posted_grade(['userid' => $userid, 'courseid' => (int)$offer->moodlecourseid,
                        'termid' => self::$atterms[$offset]->id, 'coursecode' => $code, 'coursename' => self::COURSES[$code][0],
                        'hours' => self::COURSES[$code][1], 'grade' => $marks[$n], 'percent' => null, 'postedby' => self::uid('irene'),
                        'timeposted' => self::after($offset, 4), 'note' => $marks[$n] === 'I' ? 'Final paper outstanding' : '']);
                }
                self::$at->create_transfer(['userid' => $userid, 'institution' => 'Prairie Community College (made up)', 'coursecode' => 'HIS 101',
                    'title' => 'United States History I', 'hours' => 3, 'dateaccepted' => self::day(-3, -30)]);
            }
            // Two of them are in a class now, one with a hand-entered code and one with a term set by hand.
            $course = (int)self::offer(0, 'ENG102')->moodlecourseid;
            foreach (['hugo', 'lena'] as $username) {
                self::$g->enrol_user(self::uid($username), $course, 'student');
            }
            self::$at->create_code_row(['userid' => self::uid('lena'), 'courseid' => $course, 'code' => 'I',
                'note' => 'Medical leave for two weeks', 'reason' => 'Agreed with the student', 'usermodified' => self::uid('irene')]);
            self::$at->create_code_row(['userid' => self::uid('hugo'), 'courseid' => $course, 'termid' => self::$atterms[0]->id,
                'reason' => 'Term set by the Registrar', 'usermodified' => self::uid('rosa')]);
        });
        // A posted grade with a change waiting for approval.
        $posted = $DB->get_record('local_academictranscript_posted', ['userid' => self::uid('hugo'), 'coursecode' => 'ACC201']);
        clock::at(self::ago(5), 'tomas', fn() => posting::request_change((int)$posted->id, 'B', '', 'Late homework was graded after posting.'));
    }

    /**
     * The real dates go on the terms, and the daily work runs once.
     */
    public static function finish(): void {
        global $DB;
        foreach (self::$termfinish as $offset => $fields) {
            records::save('local_studentrecords_term', $fields + ['id' => self::$terms[$offset]->id]);
        }
        clock::become('admin');
        plans::daily();
        // Students who have opened a course, for the last Moodle access shown next to attendance.
        $sql = "SELECT r.id, s.userid, o.moodlecourseid FROM {" . registration::REG . "} r
                  JOIN {" . registration::OFFER . "} o ON o.id = r.offerid
                  JOIN {" . students::TABLE . "} s ON s.id = r.studentid
                 WHERE o.termid = ? AND r.status = 'registered' AND o.moodlecourseid IS NOT NULL";
        foreach ($DB->get_records_sql($sql, [self::$terms[0]->id]) as $row) {
            $DB->insert_record('user_lastaccess', (object)['userid' => $row->userid, 'courseid' => $row->moodlecourseid,
                'timeaccess' => time() - mt_rand(1, 9) * DAYSECS]);
        }
        clock::at(time(), 'admin', fn() => null);
    }
}

mt_srand(2041);
\core\session\manager::set_user(get_admin());
$started = time();
riverside::$g = new testing_data_generator();
riverside::$at = riverside::$g->get_plugin_generator('local_academictranscript');

riverside::site();
riverside::staff();
riverside::calendar();
clock::$deliverfrom = riverside::day(0, -10);

clock::at(riverside::day(-3, -120), 'admin', function () {
    riverside::school();
    riverside::people();
    riverside::money();
    riverside::transcript();
    riverside::theme();
    foreach (array_keys(riverside::SECTIONS) as $offset) {
        riverside::sections($offset);
    }
});

riverside::history();
riverside::current();
riverside::attendance();
riverside::gradebook();
riverside::graduation();
riverside::grade_changes();
riverside::pipeline();
riverside::next_term();
riverside::late_withdrawal();
riverside::paperwork();
riverside::transcripts();
riverside::own_data();
riverside::finish();

// Cron once, so that Moodle's own tasks are done and nothing warns that cron has never run.
passthru('php /work/moodle/admin/cli/cron.php --keep-alive=0 > /dev/null 2>&1');

theme_reset_all_caches();
foreach (riverside::$report as $label => $value) {
    echo "$label: $value\n";
}
echo 'Seed finished in ' . (time() - $started) . " seconds.\n";
