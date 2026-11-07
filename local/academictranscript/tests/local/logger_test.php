<?php

namespace local_academictranscript\local;

#[\PHPUnit\Framework\Attributes\CoversClass(logger::class)]
/**
 * Tests for the activity log.
 *
 * @package    local_academictranscript
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_academictranscript\local\logger
 */
final class logger_test extends \advanced_testcase {
    /**
     * The activity log page prints a language string for each action, so every known action needs one.
     */
    public function test_every_action_has_a_language_string(): void {
        $manager = get_string_manager();

        foreach (logger::ACTIONS as $action) {
            $this->assertTrue($manager->string_exists('logaction_' . $action, 'local_academictranscript'), $action);
        }
    }

    /**
     * A transaction whose commit stops with the given error.
     *
     * @param \Throwable $error
     * @return \moodle_transaction
     */
    private function failing_transaction(\Throwable $error): \moodle_transaction {
        global $DB;
        return new class ($DB, $error) extends \moodle_transaction {
            /** @var \Throwable The error of the commit. */
            private \Throwable $error;

            /**
             * Keep the error.
             *
             * @param \moodle_database $database
             * @param \Throwable $error
             */
            public function __construct($database, \Throwable $error) {
                parent::__construct($database);
                $this->error = $error;
            }

            /**
             * Fail as a commit does.
             */
            public function allow_commit() {
                throw $this->error;
            }
        };
    }

    /**
     * Something that fails after the data is saved, with no transaction left open, is only logged.
     */
    public function test_commit_logs_a_failure_after_the_data_is_saved(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        logger::commit($this->failing_transaction(new \moodle_exception('error')), 7);

        $this->assertTrue($DB->record_exists('local_academictranscript_log', ['action' => 'after_saving', 'studentid' => 7]));
    }

    /**
     * A database that refuses the commit leaves the transaction open: whatever the error is, the caller must hear about it.
     */
    public function test_commit_throws_when_the_database_refuses_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $error = new \dml_write_exception('The database refused the commit');
        $open = $DB->start_delegated_transaction();

        try {
            logger::commit($this->failing_transaction($error));
            $this->fail('A commit the database refused went through.');
        } catch (\dml_write_exception $e) {
            $this->assertSame($error, $e);
        } finally {
            $open->allow_commit();
        }

        $this->assertSame(0, $DB->count_records('local_academictranscript_log', ['action' => 'after_saving']));
    }

    /**
     * A transaction that was already closed can't commit: no transaction is open, and the error says so. It still stops the
     * caller.
     */
    public function test_commit_of_a_closed_transaction_throws(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $transaction = $DB->start_delegated_transaction();
        $transaction->allow_commit();

        try {
            logger::commit($transaction);
            $this->fail('A closed transaction committed.');
        } catch (\dml_transaction_exception $e) {
            $this->assertStringContainsString('already disposed', $e->getMessage());
        }

        $this->assertSame(0, $DB->count_records('local_academictranscript_log', ['action' => 'after_saving']));
    }
}
