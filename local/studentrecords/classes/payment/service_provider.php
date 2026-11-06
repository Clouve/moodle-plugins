<?php

namespace local_studentrecords\payment;

use local_studentrecords\local\payments;

/**
 * Moodle payment system callbacks: a student pays (part of) their account balance online through any payment
 * provider the school turned on for the payment account chosen in Billing setup. Currency: US dollars.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class service_provider implements \core_payment\local\callback\service_provider {
    /**
     * The amount, currency and payment account of an open payment request.
     *
     * @param string $paymentarea
     * @param int $itemid payment request id
     * @return \core_payment\local\entities\payable
     */
    public static function get_payable(string $paymentarea, int $itemid): \core_payment\local\entities\payable {
        if ($paymentarea !== payments::AREA) {
            throw new \coding_exception('Unknown payment area ' . $paymentarea);
        }
        $req = payments::open_request($itemid);
        return new \core_payment\local\entities\payable(
            (float)$req->amount,
            \local_studentrecords\local\billing::CURRENCY,
            (int)$req->accountid
        );
    }

    /**
     * After paying, the student goes back to their account.
     *
     * @param string $paymentarea
     * @param int $itemid payment request id
     * @return \moodle_url
     */
    public static function get_success_url(string $paymentarea, int $itemid): \moodle_url {
        global $DB;
        $studentid = $DB->get_field(payments::REQ, 'studentid', ['id' => $itemid], MUST_EXIST);
        return new \moodle_url('/local/studentrecords/account.php', ['id' => $studentid, 'paid' => 1]);
    }

    /**
     * The payment provider confirmed the payment: record it on the student account.
     *
     * @param string $paymentarea
     * @param int $itemid payment request id
     * @param int $paymentid Moodle payment id
     * @param int $userid the user who paid
     * @return bool
     */
    public static function deliver_order(string $paymentarea, int $itemid, int $paymentid, int $userid): bool {
        return $paymentarea === payments::AREA && payments::deliver($itemid, $paymentid, $userid);
    }
}
