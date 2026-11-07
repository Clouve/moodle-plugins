#!/usr/bin/env python3
"""Student Records: a Moodle payment account and PayPal, as a site administrator, and Pay online, as a student."""
# Snapshot: seed
import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import (  # noqa: E402
    ADMINPASS, SR, start, account, crop, img, pick, row_link, shows, submit, tick)

NEWACCOUNT = 'Riverside College tuition'


def payment_account(b):
    """Create a payment account, then turn PayPal on for it with its keys."""
    b.login('admin', ADMINPASS)
    b.go('/payment/accounts.php')
    b.click_text('Create payment account')
    b.fill('#id_name', NEWACCOUNT)
    crop(b, 'pay-account-form', '#page-header', '#id_submitbutton', pad=4)
    submit(b)
    row_link(b, NEWACCOUNT, 'PayPal', '#region-main table')
    tick(b, '#id_enabled')
    b.fill('#id_brandname', 'Riverside College')
    b.fill('#id_clientid', 'made-up-client-id')
    b.fill('#id_secret', 'made-up-secret')
    b.select('#id_environment', 'Sandbox')
    crop(b, 'pay-gateway-form', '#region-main', pad=4)
    submit(b)
    crop(b, 'pay-accounts', '#region-main', pad=4)
    b.logout()


def billing_setup(b):
    """Finance picks the payment account in Billing setup."""
    b.login('felix')
    b.go(SR + '/billingsetup.php')
    b.select('#id_paymentaccount', 'None')
    submit(b)
    shows(b, 'Online payment is off: choose a payment account below.')
    crop(b, 'pay-setup-off', '.alert-info', pad=4)
    b.logout()

    # With no account, the student's button on My home goes back to the account with a message.
    b.login('theo')
    b.go(SR + '/index.php')
    b.click_text('Pay online', 'a')
    shows(b, 'Online payment is not set up yet. Use one of the other ways to pay, or ask the school.', 'body')
    b.logout()

    b.login('felix')
    b.go(SR + '/billingsetup.php')
    pick(b, '#id_paymentaccount', NEWACCOUNT)
    submit(b)
    assert 'Online payment is off' not in b.text('#region-main'), 'the account did not count'
    # Staff can't pay for a student.
    account(b, 'Theodore Bauer (Theo)')
    b.go(b.url().replace('account.php', 'pay.php'))
    shows(b, 'Only the student can pay their own account online.', 'body')
    b.logout()


def pay(b, phone=False):
    """The student pays part of the balance, up to where the payment company takes over."""
    suffix = '-phone' if phone else ''
    b.login('theo')
    b.go(SR + '/index.php')
    b.go(b.js("return [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'My account').href"))
    b.click_text('Pay online', 'a.btn')
    if not phone:
        b.fill('#id_amount', '999999')
        submit(b)
        shows(b, 'Enter an amount from $1.00 up to your balance.')
    if phone:
        b.shot(img('pay-amount-phone'), full=False)
    b.fill('#id_amount', '250')
    if not phone:
        crop(b, 'pay-amount', '#region-main', pad=4)
    submit(b)
    shows(b, 'You are paying $250.00.')
    if phone:
        b.shot(img('pay-confirm-phone'), full=False)
    else:
        crop(b, 'pay-confirm', '#region-main', pad=4)
    b.click_text('Pay $250.00 now', 'button')
    time.sleep(2)
    b.settle()
    shows(b, 'Select payment type', 'body')
    if phone:
        b.shot(img('pay-modal-phone'), full=False)
    else:
        crop(b, 'pay-modal', '.modal.show .modal-content', pad=0)
        # With real keys PayPal's own buttons appear here. With made-up keys the window stays empty, so a real payment ends it.
        b.click_text('Proceed', 'button')
        time.sleep(3)
        # The page a student comes back to after paying.
        b.go(SR + '/index.php')
        b.go(b.js("return [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'My account').href") + '&paid=1')
        crop(b, 'pay-thanks', '.alert-success', pad=4)


def under_a_dollar(b):
    """A balance under $1.00: the button shows, and no amount can be paid."""
    b.login('felix')
    account(b, 'Yuki Mori')
    b.click_text('Record a payment')
    b.fill('#id_amount', '34.50')
    submit(b)
    b.logout()
    b.login('yuki')
    b.go(SR + '/index.php')
    b.go(b.js("return [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'My account').href"))
    shows(b, 'Pay online')
    b.click_text('Pay online', 'a.btn')
    submit(b)
    shows(b, 'Enter an amount from $1.00 up to your balance.')
    crop(b, 'pay-under-a-dollar', '#region-main', pad=4)
    b.logout()


def no_longer_open(b):
    """A request that was left open, and then the balance fell below it."""
    b.login('theo')
    b.go(SR + '/index.php')
    b.go(b.js("return [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'My account').href"))
    b.click_text('Pay online', 'a.btn')
    submit(b)
    shows(b, 'You are paying')
    request = b.url()
    b.logout()
    b.login('felix')
    account(b, 'Theodore Bauer (Theo)')
    b.click_text('Record a payment')
    b.fill('#id_amount', '100')
    submit(b)
    b.logout()
    b.login('theo')
    b.go(request)
    shows(b, 'This payment was already made or is no longer open.', 'body')
    b.logout()


def main():
    b = start()
    try:
        payment_account(b)
        billing_setup(b)
        pay(b)
        b.logout()
        under_a_dollar(b)
        no_longer_open(b)
    finally:
        b.close()
    phone = start(width=390, height=844, mobile=True)
    try:
        pay(phone, True)
    finally:
        phone.close()


main()
