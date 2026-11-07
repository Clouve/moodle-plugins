<?php

namespace local_studentrecords;

use local_studentrecords\local\aid;
use local_studentrecords\local\aidhelp;
use local_studentrecords\local\billing;
use local_studentrecords\local\catalog;
use local_studentrecords\local\payments;
use local_studentrecords\local\refunds;
use local_studentrecords\local\students;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the money and message pages: who may open what, wrong sesskeys, and names printed once.
 *
 * A page is run the way Moodle runs it: it reads its request from $_GET or $_POST and its output is collected. A page
 * that redirects, or stops with an error, ends with an exception, as every page does in a PHPUnit test.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @coversNothing
 */
final class money_pages_test extends \advanced_testcase {
    /** @var \local_studentrecords_generator */
    private $generator;

    /**
     * Billing on, with a payment account.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $paymentgenerator = $this->getDataGenerator()->get_plugin_generator('core_payment');
        $account = $paymentgenerator->create_payment_account(['gateways' => 'paypal']);
        $this->generator->set_settings(['billing' => 1, 'fedaid' => 1, 'paymentaccount' => $account->get('id'),
            'schoolname' => 'Riverside & Hill College']);
    }

    /**
     * Forget the admin tree that the pages built for the user of the test. Moodle keeps it in a global that a test reset
     * does not clear.
     */
    protected function tearDown(): void {
        global $ADMIN;
        $ADMIN = null;
        parent::tearDown();
    }

    /**
     * Run a page and return what it printed.
     *
     * @param string $file the page, from the plugin folder
     * @return string
     */
    private function run_page(string $file): string {
        global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SITE, $COURSE, $SESSION;

        ob_start();
        try {
            require(__DIR__ . '/../' . $file);
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }

    /**
     * The exception a piece of code stops with.
     *
     * @param callable $work
     * @return \moodle_exception
     */
    private function failure(callable $work): \moodle_exception {
        try {
            $work();
        } catch (\moodle_exception $exception) {
            return $exception;
        }
        $this->fail('The code did not throw a moodle_exception.');
    }

    /**
     * A user with a role made of capabilities, at site level.
     *
     * @param string[] $capabilities
     * @return \stdClass
     */
    private function user_with(array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * A student who owes money and has paid some, with a receipt.
     *
     * @return array [student, payment line id]
     */
    private function student_with_a_payment(): array {
        $this->setAdminUser();
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 500]);
        return [$student, payments::record($student, 100, 'check')];
    }

    /**
     * A student can't open another student's account, statement, receipt, payment page or award letter by changing a
     * number.
     *
     * @dataProvider other_students_page_provider
     * @param string $page
     * @param string $param the request field that takes the id
     * @param string $errorcode
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('other_students_page_provider')]
    public function test_student_cannot_open_another_students_page(string $page, string $param, string $errorcode): void {
        [$student, $lineid] = $this->student_with_a_payment();
        $other = $this->generator->create_user_student();
        $this->setUser($other->userid);
        $_GET[$param] = ['id' => $student->id, 'userid' => $student->userid, 'line' => $lineid][$param === 'id' &&
            $page === 'receipt.php' ? 'line' : $param];

        $error = $this->failure(fn() => $this->run_page($page));

        $this->assertSame($errorcode, $error->errorcode);
    }

    /**
     * Data for test_student_cannot_open_another_students_page.
     *
     * @return array
     */
    public static function other_students_page_provider(): array {
        return [
            'account' => ['account.php', 'id', 'nopermissions'],
            'account by user' => ['account.php', 'userid', 'nopermissions'],
            'statement' => ['statement.php', 'id', 'nopermissions'],
            'receipt' => ['receipt.php', 'id', 'nopermissions'],
            'pay' => ['pay.php', 'id', 'err_payownonly'],
            'aid letter' => ['aidletter.php', 'id', 'nopermissions'],
        ];
    }

    /**
     * A student can't take over someone else's payment request by changing its number on their own payment page.
     */
    public function test_student_cannot_open_another_students_payment_request(): void {
        [$student] = $this->student_with_a_payment();
        $this->setUser($student->userid);
        $reqid = payments::create_request($student, 50);
        $other = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $other->id, 'amount' => 500]);
        $this->setUser($other->userid);
        $_GET = ['id' => $other->id, 'req' => $reqid];

        $error = $this->failure(fn() => $this->run_page('pay.php'));

        $this->assertInstanceOf(\dml_missing_record_exception::class, $error);
    }

    /**
     * A student opens their own account and sees their own lines, without staff buttons or staff notes.
     */
    public function test_student_opens_their_own_account(): void {
        global $DB;
        [$student, $lineid] = $this->student_with_a_payment();
        $DB->set_field(billing::TXN, 'note', 'Only for staff', ['id' => $lineid]);
        $this->setUser($student->userid);
        $_GET = ['id' => $student->id];

        $html = $this->run_page('account.php');

        $this->assertStringContainsString('$400.00', $html);
        $this->assertStringNotContainsString('Only for staff', $html);
        $this->assertStringNotContainsString('action=reverse', $html);
    }

    /**
     * A student who is not staff can't use the staff actions of their own account page.
     */
    public function test_student_cannot_add_a_credit_to_their_own_account(): void {
        [$student] = $this->student_with_a_payment();
        $this->setUser($student->userid);
        $_GET = ['id' => $student->id, 'action' => 'credit'];

        $error = $this->failure(fn() => $this->run_page('account.php'));

        $this->assertSame('nopermissions', $error->errorcode);
    }

    /**
     * Staff can't reverse a line of another student through this student's page.
     */
    public function test_reverse_stays_on_the_account_of_the_page(): void {
        [$student, $lineid] = $this->student_with_a_payment();
        $other = $this->generator->create_user_student();
        $_GET = ['id' => $other->id, 'action' => 'reverse', 'item' => $lineid];

        $error = $this->failure(fn() => $this->run_page('account.php'));

        $this->assertInstanceOf(\dml_missing_record_exception::class, $error);
        $this->assertSame(400.0, billing::balance((int)$student->id));
    }

    /**
     * A student with an aid disbursement and a charge on the account.
     *
     * @return array [student, aid line id, charge line id]
     */
    private function student_with_aid(): array {
        $this->setAdminUser();
        $student = $this->generator->create_user_student();
        $chargeid = $this->generator->create_charge(['studentid' => $student->id, 'amount' => 800]);
        $award = aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'pell', 'amount' => 500, 'status' => 'accepted']);
        return [$student, aid::disburse($award, 500, time()), $chargeid];
    }

    /**
     * What the reverse form sends.
     *
     * @param array $request
     * @return array
     */
    private function reverse_form(array $request): array {
        return $request + ['reason' => 'Student not eligible', 'sesskey' => sesskey(),
            '_qf__local_studentrecords_form_reverse' => 1, 'submitbutton' => 'Reverse'];
    }

    /**
     * The end of a link to reverse a line.
     *
     * @param int $lineid
     * @return string
     */
    private function reverse_link(int $lineid): string {
        return 'action=reverse&amp;item=' . $lineid . '"';
    }

    /**
     * Finance without the manage aid permission isn't offered the reversal of an aid line, and still is of other lines.
     * With the permission, both are offered.
     */
    public function test_account_offers_the_reversal_of_an_aid_line_with_manageaid_only(): void {
        [$student, $aidline, $charge] = $this->student_with_aid();
        $finance = ['local/studentrecords:viewbilling', 'local/studentrecords:managebilling'];
        $_GET = ['id' => $student->id];

        $this->setUser($this->user_with($finance));
        $html = $this->run_page('account.php');
        $this->assertStringContainsString($this->reverse_link($charge), $html);
        $this->assertStringNotContainsString($this->reverse_link($aidline), $html);

        $this->setUser($this->user_with(array_merge($finance, ['local/studentrecords:manageaid'])));
        $html = $this->run_page('account.php');
        $this->assertStringContainsString($this->reverse_link($charge), $html);
        $this->assertStringContainsString($this->reverse_link($aidline), $html);
    }

    /**
     * Finance can't get to the reversal of an aid line by asking for it directly, neither the form nor a POST.
     */
    public function test_account_refuses_a_direct_reversal_of_an_aid_line(): void {
        global $DB;
        [$student, $aidline] = $this->student_with_aid();
        $this->setUser($this->user_with(['local/studentrecords:viewbilling', 'local/studentrecords:managebilling']));
        $_GET = ['id' => $student->id, 'action' => 'reverse', 'item' => $aidline];

        $form = $this->failure(fn() => $this->run_page('account.php'));
        $_POST = $this->reverse_form($_GET);
        $post = $this->failure(fn() => $this->run_page('account.php'));

        $this->assertSame('nopermissions', $form->errorcode);
        $this->assertSame('nopermissions', $post->errorcode);
        $this->assertNull($DB->get_field(billing::TXN, 'reversedby', ['id' => $aidline]));
        $this->assertSame(300.0, billing::balance((int)$student->id));
    }

    /**
     * Staff who hold both permissions reverse an aid line on the account page.
     */
    public function test_account_reverses_an_aid_line_with_manageaid(): void {
        global $DB;
        $this->preventResetByRollback();
        [$student, $aidline] = $this->student_with_aid();
        $this->setUser($this->user_with(['local/studentrecords:viewbilling', 'local/studentrecords:managebilling',
            'local/studentrecords:manageaid']));
        $_GET = ['id' => $student->id, 'action' => 'reverse', 'item' => $aidline];
        $_POST = $this->reverse_form($_GET);

        $error = $this->failure(fn() => $this->run_page('account.php'));

        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertNotNull($DB->get_field(billing::TXN, 'reversedby', ['id' => $aidline]));
        $this->assertSame(800.0, billing::balance((int)$student->id));
    }

    /**
     * Financial Aid staff can't open the student account, so they reverse a disbursement on the aid page. The page lists
     * each disbursement and shows what was reversed.
     */
    public function test_aid_page_reverses_a_disbursement(): void {
        global $DB;
        $this->preventResetByRollback();
        [$student, $aidline] = $this->student_with_aid();
        $p = 'local_studentrecords';
        $this->setUser($this->user_with(aid::ROLECAPS));
        $page = ['report' => 'students', 'id' => $student->id, 'awardyear' => '2029-30'];

        $_GET = $page;
        $html = $this->run_page('aid.php');
        $this->assertStringContainsString($this->reverse_link($aidline), $html);
        $this->assertStringNotContainsString(get_string('reversed', $p), $html);

        $_GET = $page + ['action' => 'reverse', 'item' => $aidline];
        $form = $this->run_page('aid.php');
        $this->assertStringContainsString(get_string('reverseline', $p), $form);
        $this->assertStringContainsString(get_string('reverseaidinfo', $p), $form);
        $this->assertStringNotContainsString(get_string('reverseinfo', $p), $form);

        $_POST = $this->reverse_form($_GET);
        $error = $this->failure(fn() => $this->run_page('aid.php'));
        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertNotNull($DB->get_field(billing::TXN, 'reversedby', ['id' => $aidline]));
        $this->assertSame(800.0, billing::balance((int)$student->id));

        $_POST = [];
        $_GET = $page;
        $html = $this->run_page('aid.php');
        $this->assertStringContainsString(get_string('reversed', $p), $html);
        $this->assertStringNotContainsString($this->reverse_link($aidline), $html);
    }

    /**
     * Someone who may only see aid gets the list without the action, and a direct POST changes nothing.
     */
    public function test_aid_page_needs_manageaid_to_reverse(): void {
        global $DB;
        [$student, $aidline] = $this->student_with_aid();
        $this->setUser($this->user_with(['local/studentrecords:viewaid']));
        $page = ['report' => 'students', 'id' => $student->id, 'awardyear' => '2029-30'];

        $_GET = $page;
        $html = $this->run_page('aid.php');
        $this->assertStringContainsString(s(get_string('aiddisbdesc', 'local_studentrecords', (object)[
            'fund' => get_string('fund_pell', 'local_studentrecords'), 'year' => '2029-30'])), $html);
        $this->assertStringNotContainsString($this->reverse_link($aidline), $html);

        $_GET = $page + ['action' => 'reverse', 'item' => $aidline];
        $_POST = $this->reverse_form($_GET);
        $error = $this->failure(fn() => $this->run_page('aid.php'));

        $this->assertSame('nopermissions', $error->errorcode);
        $this->assertNull($DB->get_field(billing::TXN, 'reversedby', ['id' => $aidline]));
    }

    /**
     * The aid page reverses aid lines of the student of the page and nothing else: the manage aid permission is no way to
     * reverse a charge or a payment.
     */
    public function test_aid_page_reverses_only_aid_lines_of_its_student(): void {
        global $DB;
        [$student, $aidline, $charge] = $this->student_with_aid();
        $other = $this->generator->create_user_student();
        $this->setUser($this->user_with(aid::ROLECAPS));
        $page = ['report' => 'students', 'id' => $student->id];

        $_GET = $page + ['action' => 'reverse', 'item' => $charge];
        $_POST = $this->reverse_form($_GET);
        $notaid = $this->failure(fn() => $this->run_page('aid.php'));
        $_GET = ['id' => $other->id, 'action' => 'reverse', 'item' => $aidline] + $page;
        $_POST = $this->reverse_form($_GET);
        $wrongstudent = $this->failure(fn() => $this->run_page('aid.php'));

        $this->assertSame('err_notaidline', $notaid->errorcode);
        $this->assertInstanceOf(\dml_missing_record_exception::class, $wrongstudent);
        $this->assertSame(0, $DB->count_records_select(billing::TXN, 'reverses IS NOT NULL'));
    }

    /**
     * The charge of an approved return of aid is listed on the aid page with its Reverse link, Finance alone isn't offered it
     * on the account page, and Financial Aid staff can reverse it.
     */
    public function test_aid_page_lists_and_reverses_the_charge_of_a_return_of_aid(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->setAdminUser();
        [$student, $termid] = $this->student_who_left();
        refunds::decide(aidhelp::send_return($student, $termid), true, 'Sent');
        $lineid = (int)$DB->get_field_select(billing::TXN, 'id', 'studentid = ? AND refundid IS NOT NULL', [$student->id]);
        $balance = billing::balance((int)$student->id);
        $amount = (float)$DB->get_field(billing::TXN, 'amount', ['id' => $lineid]);

        $this->setUser($this->user_with(['local/studentrecords:viewbilling', 'local/studentrecords:managebilling']));
        $_GET = ['id' => $student->id];
        $this->assertStringNotContainsString($this->reverse_link($lineid), $this->run_page('account.php'));

        $this->setUser($this->user_with(aid::ROLECAPS));
        $page = ['report' => 'students', 'id' => $student->id, 'awardyear' => '2029-30'];
        $_GET = $page;
        $html = $this->run_page('aid.php');
        $this->assertStringContainsString($this->reverse_link($lineid), $html);
        $this->assertStringContainsString(s(get_string('ret_chargedesc', 'local_studentrecords', get_string(
            'fund_dlunsub',
            'local_studentrecords'
        ))), $html);

        $_GET = $page + ['action' => 'reverse', 'item' => $lineid];
        $_POST = $this->reverse_form($_GET);
        $error = $this->failure(fn() => $this->run_page('aid.php'));

        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertNotNull($DB->get_field(billing::TXN, 'reversedby', ['id' => $lineid]));
        $this->assertSame(round($balance - $amount, 2), billing::balance((int)$student->id));
    }

    /**
     * The award letter button of the aid page reports what happened to the message, not that it went: here it is switched off.
     */
    public function test_aid_page_says_what_happened_to_the_award_letter(): void {
        global $DB;
        $this->setAdminUser();
        $this->generator->set_settings(['msgon_aidletter' => 0]);
        $student = $this->generator->create_user_student();
        $_GET = ['report' => 'students', 'id' => $student->id, 'awardyear' => '2029-30'];
        $_POST = ['action' => 'letter', 'sesskey' => sesskey()];

        $error = $this->failure(fn() => $this->run_page('aid.php'));

        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertTrue($DB->record_exists('local_studentrecords_log', ['objecttype' => 'aid', 'objectid' => $student->id,
            'info' => 'award letter 2029-30: off']));
    }

    /**
     * A wrong sesskey doesn't reverse a disbursement.
     */
    public function test_aid_page_reversal_needs_the_sesskey(): void {
        global $DB;
        [$student, $aidline] = $this->student_with_aid();
        $this->setUser($this->user_with(aid::ROLECAPS));
        $_GET = ['report' => 'students', 'id' => $student->id, 'action' => 'reverse', 'item' => $aidline];
        $_POST = ['sesskey' => 'not-the-sesskey'] + $this->reverse_form($_GET);

        $error = $this->failure(fn() => $this->run_page('aid.php'));

        $this->assertSame('invalidsesskey', $error->errorcode);
        $this->assertNull($DB->get_field(billing::TXN, 'reversedby', ['id' => $aidline]));
    }

    /**
     * A completed plan whose payment was reversed can't reopen next to an active plan. Its heading doesn't say it is paid in
     * full, and Finance reads why under the plan.
     */
    public function test_account_shows_a_plan_that_could_not_reopen(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->setAdminUser();
        $p = 'local_studentrecords';
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 1000]);
        $terms = ['downtype' => 'percent', 'downvalue' => 25, 'numpay' => 2, 'frequency' => 'monthly', 'duemode' => 'relative',
            'firstdue' => 30, 'planfee' => 0, 'latefee' => 0, 'gracedays' => 0];
        $term = (int)$this->generator->create_term()->id;
        $fall = \local_studentrecords\local\plans::create($student, $term, null, $terms + ['name' => 'Fall'], 'Custom');
        $DB->set_field(\local_studentrecords\local\plans::STUPLANS, 'timeapproved', time() - 60 * DAYSECS, ['id' => $fall]);
        $line = payments::record($student, 1000, 'check');
        $DB->set_field(billing::TXN, 'timecreated', time() - 50 * DAYSECS, ['id' => $line]);
        \local_studentrecords\local\plans::sync($student);
        $_GET = ['id' => $student->id];
        $this->assertStringContainsString(get_string('planstatus_completed', $p), $this->run_page('account.php'));

        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 800]);
        \local_studentrecords\local\plans::create($student, $term, null, $terms + ['name' => 'Spring'], 'Custom');
        billing::reverse($line, 'Check returned');
        \core\notification::fetch();
        $html = $this->run_page('account.php');

        $this->assertStringContainsString(get_string('planstatus_reversed', $p), $html);
        $this->assertStringNotContainsString(get_string('planstatus_completed', $p), $html);
        $note = get_string('plan_notreopened', $p, (object)['plan' => 'Fall', 'active' => 'Spring']);
        $this->assertStringContainsString(s($note), $html);
    }

    /**
     * Finance sees at once that a student on a plan can't get another one: the page goes back to the account, where the plan
     * and its Cancel link are, instead of opening a form that can't be saved.
     */
    public function test_a_student_on_a_plan_gets_no_plan_form(): void {
        $this->setAdminUser();
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 800]);
        $term = $this->generator->create_term();
        $_GET = ['id' => $student->id, 'action' => 'plan'];
        $this->assertStringContainsString(get_string('giveplan', 'local_studentrecords'), $this->run_page('account.php'));

        \local_studentrecords\local\plans::create($student, (int)$term->id, null, ['name' => 'Plan', 'downtype' => 'percent',
            'downvalue' => 25, 'numpay' => 2, 'frequency' => 'monthly', 'duemode' => 'relative', 'firstdue' => 30, 'planfee' => 0,
            'latefee' => 0, 'gracedays' => 0], 'Custom');
        $error = $this->failure(fn() => $this->run_page('account.php'));

        $this->assertSame('redirecterrordetected', $error->errorcode);
    }

    /**
     * Staff pages stay closed to a student.
     *
     * @dataProvider staff_page_provider
     * @param string $page
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('staff_page_provider')]
    public function test_student_cannot_open_staff_pages(string $page): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $error = $this->failure(fn() => $this->run_page($page));

        $this->assertSame('nopermissions', $error->errorcode);
    }

    /**
     * Data for test_student_cannot_open_staff_pages.
     *
     * @return array
     */
    public static function staff_page_provider(): array {
        return [
            'billing' => ['billing.php'],
            'reports' => ['billingreports.php'],
            'setup' => ['billingsetup.php'],
            'refunds' => ['refunds.php'],
            'aid' => ['aid.php'],
            'messages' => ['messages.php'],
        ];
    }

    /**
     * A refund waiting for approval, for a student who dropped.
     *
     * @return array [student, refund id]
     */
    private function refund_waiting(): array {
        $this->setAdminUser();
        refunds::save_rule(['sortorder' => 1, 'name' => 'Always', 'ruletype' => 'after', 'value' => 0, 'tuitionpct' => 100,
            'feespct' => 100, 'allfees' => 0]);
        $student = $this->generator->create_user_student();
        $this->generator->create_fee(['name' => 'Tuition', 'kind' => 'tuition', 'basis' => 'course', 'amount' => 800]);
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course()->id,
            'termid' => $this->generator->create_term()->id]);
        $regid = $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        return [$student, refunds::propose($regid, 'drop')];
    }

    /**
     * The form fields of a refund decision.
     *
     * @param int $refundid
     * @return array
     */
    private function decision(int $refundid): array {
        return ['id' => $refundid, 'decision' => 'approve', 'note' => 'OK', 'sesskey' => sesskey(),
            '_qf__local_studentrecords_form_refunddecide' => 1, 'submitbutton' => 'Save'];
    }

    /**
     * Someone who manages billing but may not approve refunds sees no decision form, and a direct POST changes nothing.
     */
    public function test_refund_decision_needs_approverefund(): void {
        global $DB;
        [$student, $refundid] = $this->refund_waiting();
        $this->setUser($this->user_with(['local/studentrecords:viewbilling', 'local/studentrecords:managebilling']));
        $_GET = ['id' => $refundid];

        $html = $this->run_page('refunds.php');
        $this->assertStringContainsString(get_string('refundneedsapprover', 'local_studentrecords'), $html);
        $this->assertStringNotContainsString('name="decision"', $html);

        $_POST = $this->decision($refundid);
        $this->run_page('refunds.php');

        $this->assertSame('proposed', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));
        $this->assertSame(800.0, billing::balance((int)$student->id));
    }

    /**
     * Someone who may approve refunds approves one from the page.
     */
    public function test_refund_decision_from_the_page(): void {
        global $DB;
        [$student, $refundid] = $this->refund_waiting();
        $this->setUser($this->user_with(['local/studentrecords:viewbilling', 'local/studentrecords:approverefund']));
        $_GET = ['id' => $refundid];
        $_POST = $this->decision($refundid);

        $error = $this->failure(fn() => $this->run_page('refunds.php'));

        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertSame('approved', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));
        $this->assertSame(0.0, billing::balance((int)$student->id));
    }

    /**
     * A refund that is out of date comes back to the page as an error a person can read.
     */
    public function test_out_of_date_refund_comes_back_to_the_page(): void {
        global $DB;
        $this->preventResetByRollback();
        [$student, $refundid] = $this->refund_waiting();
        billing::reverse((int)$DB->get_field(billing::TXN, 'id', ['studentid' => $student->id]), 'Wrong student');
        $_GET = ['id' => $refundid];
        $_POST = $this->decision($refundid);

        $error = $this->failure(fn() => $this->run_page('refunds.php'));

        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertSame('proposed', $DB->get_field(refunds::REFUNDS, 'status', ['id' => $refundid]));
    }

    /**
     * A return of aid has no registration to figure from, so the page offers no way to figure it again.
     */
    public function test_return_of_aid_shows_no_refigure_form(): void {
        global $DB;
        [$student, $refundid] = $this->refund_waiting();
        $_GET = ['id' => $refundid];
        $this->assertStringContainsString('name="refigure"', $this->run_page('refunds.php'));

        $DB->set_field(refunds::REFUNDS, 'reason', 'aidreturn', ['id' => $refundid]);
        $html = $this->run_page('refunds.php');

        $this->assertStringNotContainsString('name="refigure"', $html);
        $this->assertStringContainsString(s(get_string('refundreason_aidreturn', 'local_studentrecords')), $html);
        $this->assertSame(800.0, billing::balance((int)$student->id));
    }

    /**
     * A wrong sesskey is refused with an error, not ignored.
     *
     * @dataProvider sesskey_provider
     * @param string $page
     * @param array $request
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sesskey_provider')]
    public function test_wrong_sesskey_is_refused(string $page, array $request): void {
        $this->setAdminUser();
        $_POST = $request + ['sesskey' => 'not-the-sesskey'];

        $error = $this->failure(fn() => $this->run_page($page));

        $this->assertSame('invalidsesskey', $error->errorcode);
    }

    /**
     * Data for test_wrong_sesskey_is_refused.
     *
     * @return array
     */
    public static function sesskey_provider(): array {
        return [
            'charge a term' => ['billing.php', ['chargeterm' => 1, 'confirm' => 1]],
            'reset a message' => ['messages.php', ['reset' => 'text', 'key' => 'welcome']],
            'send a test' => ['messages.php', ['sendtest' => 1, 'key' => 'welcome']],
            'save the test inbox' => ['messages.php', ['saveinbox' => 1, 'testinbox' => 'a@example.com']],
            'test mode' => ['messages.php', ['testmode' => 1]],
            'disbursement run' => ['aid.php', ['report' => 'run', 'termid' => 1, 'send' => 1]],
        ];
    }

    /**
     * Saving aid year details, sending an award letter and sending a return of aid need the sesskey too.
     */
    public function test_wrong_sesskey_on_the_aid_student_page(): void {
        $this->setAdminUser();
        $student = $this->generator->create_user_student();
        foreach (
            [['report' => 'students', 'id' => $student->id, 'action' => 'aidyear', 'saveyear' => 1],
            ['report' => 'students', 'id' => $student->id, 'action' => 'letter'],
            ['report' => 'returns', 'id' => $student->id, 'termid' => 1, 'sendreturn' => 1]] as $request
        ) {
            $_POST = $request + ['sesskey' => 'not-the-sesskey'];
            $error = $this->failure(fn() => $this->run_page('aid.php'));
            $this->assertSame('invalidsesskey', $error->errorcode);
        }
    }

    /**
     * An approved return of aid goes to the Department of Education. The page says so, and it offers no way to record money
     * paid to the student.
     */
    public function test_an_approved_return_of_aid_is_no_refund_to_the_student(): void {
        $this->setAdminUser();
        [$student, $termid] = $this->student_who_left();
        $id = aidhelp::send_return($student, $termid);
        refunds::decide($id, true, 'Sent');
        $p = 'local_studentrecords';

        $_GET = ['id' => $id];
        $html = $this->run_page('refunds.php');

        $this->assertStringContainsString(get_string('ref_step2_aidopen', $p), $html);
        $this->assertStringContainsString(get_string('ref_dueaid', $p), $html);
        $this->assertStringNotContainsString(get_string('recordpayout', $p), $html);
        $this->assertStringNotContainsString('action=payout', $html);
    }

    /**
     * A student who left a term after aid was paid.
     *
     * @param array $record more fields of the student, such as programid
     * @return array student, term id
     */
    private function student_who_left(array $record = []): array {
        $term = $this->generator->create_term(['startdate' => '2030-01-01', 'enddate' => '2030-04-10']);
        $student = $this->generator->create_user_student($record);
        $student = students::get(students::save(['id' => $student->id, 'status' => 'withdrawn',
            'withdrawdate' => catalog::date_to_time('2030-01-20')]));
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 3000, 'termid' => $term->id]);
        $award = aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'dlunsub', 'amount' => 1000,
            'status' => 'accepted', 'termid' => (int)$term->id]);
        aid::disburse($award, 1000, time());
        return [$student, (int)$term->id];
    }

    /**
     * The worksheet page asks for what it can't know, and the figures follow what staff typed. Each detail is filled in again
     * and goes with the Approve button, so what is sent is what staff saw.
     */
    public function test_return_of_aid_page_follows_the_details(): void {
        global $DB;
        $this->setAdminUser();
        [$student, $termid] = $this->student_who_left();
        $p = 'local_studentrecords';

        $_GET = ['report' => 'returns', 'termid' => $termid, 'id' => $student->id];
        $html = $this->run_page('aid.php');
        foreach (aidhelp::WORKSHEETFIELDS as $name) {
            if (!in_array($name, ['hoursdone', 'hoursperiod'], true)) {
                $this->assertStringContainsString('name="' . $name . '"', $html, $name);
            }
        }
        $this->assertStringNotContainsString('name="hoursdone"', $html);
        $this->assertStringContainsString(get_string('aid_approvesend', $p), $html);

        $_GET += ['charges' => '500', 'determined' => '2030-02-01'];
        $html = $this->run_page('aid.php');
        $this->assertStringContainsString('value="500.00"', $html);
        $this->assertStringContainsString('name="charges" value="500"', preg_replace('/type="hidden" /', '', $html));
        $this->assertStringContainsString(s(get_string('aid_schoolreturns', $p, billing::money(400))), $html);

        $_GET = [];
        $_POST = ['report' => 'returns', 'termid' => $termid, 'id' => $student->id, 'sendreturn' => 1, 'sesskey' => sesskey(),
            'charges' => '500', 'determined' => '2030-02-01', 'expected' => 40000];
        $this->failure(fn() => $this->run_page('aid.php'));
        $refund = $DB->get_record(refunds::REFUNDS, ['studentid' => $student->id, 'reason' => 'aidreturn'], '*', MUST_EXIST);
        $this->assertEquals(400, $refund->amount);
        $this->assertEquals(catalog::date_to_time('2030-02-01') + 45 * DAYSECS, $refund->duedate);
    }

    /**
     * A student who earned more than was paid out is due a disbursement: the page says so and offers nothing to send.
     */
    public function test_return_of_aid_page_shows_a_post_withdrawal_disbursement(): void {
        $this->setAdminUser();
        [$student, $termid] = $this->student_who_left();
        $p = 'local_studentrecords';

        $_GET = ['report' => 'returns', 'termid' => $termid, 'id' => $student->id, 'couldloans' => '9000'];
        $html = $this->run_page('aid.php');

        $this->assertStringContainsString(s(get_string('aid_pwdtitle', $p, billing::money(1000))), $html);
        $this->assertStringNotContainsString(get_string('aid_approvesend', $p), $html);
    }

    /**
     * A clock-hour program asks for hours instead of break days, and can't be sent without them.
     */
    public function test_return_of_aid_page_asks_for_clock_hours(): void {
        $this->setAdminUser();
        $program = $this->generator->create_program(['unittype' => 'clock']);
        [$student, $termid] = $this->student_who_left(['programid' => $program->id]);
        $p = 'local_studentrecords';

        $_GET = ['report' => 'returns', 'termid' => $termid, 'id' => $student->id];
        $html = $this->run_page('aid.php');
        $this->assertStringContainsString('name="hoursperiod"', $html);
        $this->assertStringNotContainsString('name="breakdays"', $html);
        $this->assertStringContainsString(get_string('aid_ws_needhours', $p), $html);
        $this->assertStringNotContainsString(get_string('aid_approvesend', $p), $html);

        $_GET += ['hoursdone' => '45', 'hoursperiod' => '900'];
        $html = $this->run_page('aid.php');
        $this->assertStringNotContainsString(get_string('aid_ws_needhours', $p), $html);
        $this->assertStringContainsString(get_string('aid_approvesend', $p), $html);
    }

    /**
     * A part-time student's loans are held to the reduced limits, and the page says why. A graduate program isn't held to
     * the undergraduate limits at all, and the page says that too.
     */
    public function test_aid_year_page_explains_the_loan_limits(): void {
        global $DB;
        $this->setAdminUser();
        $p = 'local_studentrecords';
        $term = $this->generator->create_term();
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course(['hours' => 6])->id,
            'termid' => $term->id]);
        $graduate = $this->generator->create_program(['level' => 'Graduate']);
        $this->generator->set_settings(['gradlevels' => $graduate->levelid]);
        $award = fn($student) => aid::save_award($student, ['awardyear' => '2026-27', 'fund' => 'dlunsub', 'amount' => 5000,
            'status' => 'accepted', 'termid' => (int)$term->id]);

        $parttime = $this->generator->create_user_student();
        $this->generator->create_registration(['studentid' => $parttime->id, 'offerid' => $offer->id]);
        $award($parttime);
        $_GET = ['report' => 'students', 'id' => $parttime->id, 'awardyear' => '2026-27'];
        $html = $this->run_page('aid.php');
        $this->assertStringContainsString(get_string('aid_loanreduced', $p, (object)['percent' => 50,
            'limit' => billing::money(2750), 'sublimit' => billing::money(1750)]), $html);
        $this->assertStringContainsString('sr-note-bad', $html);

        $student = $this->generator->create_user_student(['programid' => $graduate->id]);
        $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);
        $award($student);
        $_GET = ['report' => 'students', 'id' => $student->id, 'awardyear' => '2026-27'];
        $html = $this->run_page('aid.php');
        $this->assertStringContainsString(get_string('aid_loangraduate', $p), $html);
        $this->assertStringNotContainsString('sr-note-bad', $html);
    }

    /**
     * A program name with an ampersand is printed once escaped on the aid page.
     */
    public function test_aid_year_page_escapes_the_program_once(): void {
        $this->setAdminUser();
        $program = $this->generator->create_program(['name' => 'Arts & Sciences']);
        $student = $this->generator->create_user_student(['programid' => $program->id]);

        $_GET = ['report' => 'students', 'id' => $student->id];
        $html = $this->run_page('aid.php');

        $this->assertStringContainsString('Arts &amp; Sciences', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * Names are printed once escaped, in the pages and in the term lists of the forms. Selects print their options as they
     * are, so a raw name would run as HTML.
     */
    public function test_names_are_escaped_once(): void {
        $this->setAdminUser();
        $term = $this->generator->create_term(['name' => 'Fall & <b>Winter</b>']);
        $student = $this->generator->create_user_student();
        $this->generator->create_charge(['studentid' => $student->id, 'amount' => 500]);
        $course = $this->getDataGenerator()->create_course();
        $offer = $this->generator->create_offering(['courseid' => $this->generator->create_course(['code' => 'R&D 101'])->id,
            'termid' => $term->id, 'moodlecourseid' => $course->id]);
        $regid = $this->generator->create_registration(['studentid' => $student->id, 'offerid' => $offer->id]);

        foreach (
            [['id' => $student->id], ['id' => $student->id, 'action' => 'payment'], ['id' => $student->id, 'action' => 'charge'],
            ['id' => $student->id, 'action' => 'plan'], ['id' => $student->id, 'action' => 'access', 'item' => $regid]] as $request
        ) {
            $_GET = $request;
            $html = $this->run_page('account.php');
            $this->assertStringNotContainsString('<b>Winter</b>', $html);
            $this->assertStringNotContainsString('&amp;amp;', $html);
            $this->assertStringNotContainsString('&amp;lt;', $html);
        }
        $this->assertStringContainsString('R&amp;D 101', $html);
    }

    /**
     * Every money report opens, on every database.
     *
     * @dataProvider report_provider
     * @param string $page
     * @param array $request
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('report_provider')]
    public function test_reports_open(string $page, array $request): void {
        [$student] = $this->student_with_a_payment();
        $term = $this->generator->create_term(['name' => 'Fall & Winter']);
        $award = aid::save_award($student, ['awardyear' => '2029-30', 'fund' => 'pell', 'amount' => 100, 'status' => 'accepted',
            'termid' => (int)$term->id]);
        aid::disburse($award, 50, time());
        $_GET = (isset($request['termid']) ? ['termid' => $term->id] : []) + $request;

        $html = $this->run_page($page);

        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * Data for test_reports_open.
     *
     * @return array
     */
    public static function report_provider(): array {
        return [
            'billing' => ['billing.php', []],
            'owed' => ['billingreports.php', ['report' => 'owed']],
            'payments' => ['billingreports.php', ['report' => 'payments']],
            'refunds' => ['billingreports.php', ['report' => 'refunds']],
            'late' => ['billingreports.php', ['report' => 'late']],
            'tax' => ['billingreports.php', ['report' => 'tax1098']],
            'setup' => ['billingsetup.php', []],
            'refund list' => ['refunds.php', ['status' => 'all']],
            'messages' => ['messages.php', []],
            'message log' => ['messages.php', ['tab' => 'log']],
            'aid students' => ['aid.php', []],
            'aid run' => ['aid.php', ['report' => 'run', 'termid' => 1]],
            'aid returns' => ['aid.php', ['report' => 'returns', 'termid' => 1]],
            'aid verification' => ['aid.php', ['report' => 'verification']],
            'aid recipients' => ['aid.php', ['report' => 'recipients']],
            'aid disbursements' => ['aid.php', ['report' => 'disbursements']],
            'aid enrollment' => ['aid.php', ['report' => 'enrollment', 'termid' => 1]],
            'aid withdrawals' => ['aid.php', ['report' => 'withdrawals', 'termid' => 1]],
            'aid 90/10' => ['aid.php', ['report' => 'ninetyten']],
        ];
    }

    /**
     * The student opens the online payment page and the page for a request of their own. A request that the balance no
     * longer allows goes back to the account.
     */
    public function test_payment_page(): void {
        [$student] = $this->student_with_a_payment();
        $this->setUser($student->userid);
        $_GET = ['id' => $student->id];
        $this->assertStringContainsString('name="amount"', $this->run_page('pay.php'));

        $reqid = payments::create_request($student, 400);
        $_GET = ['id' => $student->id, 'req' => $reqid];
        $this->assertStringContainsString('gateways-modal-trigger', $this->run_page('pay.php'));

        $this->setAdminUser();
        payments::record($student, 300, 'check');
        $this->setUser($student->userid);
        $error = $this->failure(fn() => $this->run_page('pay.php'));
        $this->assertSame('redirecterrordetected', $error->errorcode);
    }

    /**
     * The school name is printed once escaped on the award letter, and staff and the student can open the letter.
     */
    public function test_award_letter(): void {
        $this->setAdminUser();
        $student = $this->generator->create_user_student();
        $_GET = ['id' => $student->id];

        $html = $this->run_page('aidletter.php');
        $this->assertStringContainsString('Riverside &amp; Hill College', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);

        $this->setUser($student->userid);
        $this->assertStringContainsString('Riverside &amp; Hill College', $this->run_page('aidletter.php'));
    }

    /**
     * Receipts and statements are made for the student who may have them.
     */
    public function test_receipt_pdf(): void {
        global $DB;
        [, $lineid] = $this->student_with_a_payment();

        $this->assertStringStartsWith('%PDF', payments::receipt_pdf($DB->get_record(billing::TXN, ['id' => $lineid])));
    }
}
