<?php

namespace local_studentrecords\task;

use local_studentrecords\local\billing;
use local_studentrecords\local\payments;

#[\PHPUnit\Framework\Attributes\CoversClass(recover_payments::class)]
/**
 * Tests for the task that records online payments that never reached the account.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\task\recover_payments
 */
final class recover_payments_test extends \advanced_testcase {
    /**
     * A student who paid is not left waiting for the daily task: this one runs every few minutes.
     */
    public function test_it_runs_every_few_minutes(): void {
        $tasks = \core\task\manager::load_default_scheduled_tasks_for_component('local_studentrecords');

        $found = array_filter($tasks, fn($task) => $task instanceof recover_payments);

        $this->assertCount(1, $found);
        $this->assertSame('*/5', reset($found)->get_minute());
        $this->assertNotEmpty(reset($found)->get_name());
    }

    /**
     * The task records a payment that lost its delivery. Billing being off doesn't matter: the money was taken.
     */
    public function test_it_records_a_lost_payment(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_studentrecords');
        $paymentgenerator = $this->getDataGenerator()->get_plugin_generator('core_payment');
        $account = $paymentgenerator->create_payment_account(['gateways' => 'paypal']);
        $generator->set_settings(['billing' => 1, 'paymentaccount' => $account->get('id'), 'recoverfrom' => time() - DAYSECS]);
        $student = $generator->create_user_student();
        $generator->create_charge(['studentid' => $student->id, 'amount' => 1000]);
        $this->setUser($student->userid);
        $reqid = payments::create_request($student, 400);
        \core_payment\helper::save_payment(
            (int)$account->get('id'),
            'local_studentrecords',
            payments::AREA,
            $reqid,
            (int)$student->userid,
            400,
            billing::CURRENCY,
            'paypal'
        );
        $generator->set_settings(['billing' => 0]);

        ob_start();
        (new recover_payments())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('1', $output);
        $this->assertSame(600.0, billing::balance((int)$student->id));
    }
}
