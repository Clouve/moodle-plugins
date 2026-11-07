#!/usr/bin/env python3
"""Student Records: payment plans, as the Finance officer and as a student, with the daily task of billing."""
# Snapshot: seed
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import (  # noqa: E402
    SR, account, billing_task, close_menu, crop, day, main_shot, mark, mark_next, mark_row, row_link, shows, start, submit,
    term_names, tool)

CUSTOM = 'Custom plan (for this student only)'
TERM, NEXT = term_names()


def plan_section(b, name, last='table'):
    """Save the Payment plans section of an account, down to its table or its note."""
    first = mark(b, 'a', 'Payment plans', 'h3')
    crop(b, name, first, mark_next(b, 'z', 'ol.sr-plan', last), pad=2)


def custom_plan(b, name, due, down='10', payments=None, note='Agreed with the Business Office.', term=NEXT):
    """Give a custom plan with fixed due dates."""
    b.click_text('Give a payment plan')
    b.select('#id_termid', term)
    b.select('#id_planid', CUSTOM)
    b.fill('#id_name', name)
    b.fill('#id_downvalue', down)
    b.fill('#id_numpay', str(payments or len(due)))
    b.select('#id_duemode', 'fixed dates')
    b.fill('#id_duedates', ', '.join(due))
    b.fill('#id_latefee', '10')
    b.fill('#id_note', note)


def school_plan(b):
    """A student whose course waits gets a school plan for next term and pays the down payment. The course opens."""
    account(b, 'Uma Reddy')
    shows(b, 'Course access waits for payment.')
    b.click_text('Give a payment plan')
    b.select('#id_termid', NEXT)
    main_shot(b, 'plans-give-form')
    submit(b)
    shows(b, 'Saved.', 'body')
    shows(b, 'On a payment plan')
    shows(b, 'Payment plan fee: Four-payment plan')
    down = b.js("""return [...document.querySelectorAll('tr')].find(r => r.cells[0] &&
        r.cells[0].innerText.trim() === 'Down payment').cells[2].innerText""").replace('$', '').replace(',', '')
    b.click_text('Record a payment')
    b.select('#id_method', 'Card at the office')
    b.fill('#id_amount', down)
    b.fill('#id_reference', 'Down payment')
    b.select('#id_termid', NEXT)
    submit(b)
    assert 'Course access waits for payment.' not in b.text('#region-main'), 'the course still waits'
    plan_section(b, 'plans-on-account')


def one_plan_at_a_time(b):
    """A second plan is refused, and an account with nothing owed can't have one."""
    account(b, 'Uma Reddy')
    b.click_text('Give a payment plan')
    shows(b, 'This student already has an active payment plan', 'body')
    crop(b, 'plans-one-active', '.alert-danger', pad=4)
    account(b, 'Gina Marsh')
    b.click_text('Give a payment plan')
    assert b.js("return document.querySelector('#id_termid').selectedIndex") == 0, 'the form starts on another term'
    submit(b)
    shows(b, 'Nothing is owed, so there is nothing to put on a plan.', 'body')


def custom_and_cancel(b):
    """A custom plan needs a note. Then it is cancelled."""
    account(b, 'Theodore Bauer (Theo)')
    custom_plan(b, 'Hardship plan', [day(30), day(60), day(90)], note='')
    submit(b)
    shows(b, 'A custom plan needs a note saying why.')
    account(b, 'Theodore Bauer (Theo)')
    custom_plan(b, 'Hardship plan', [day(30), day(60), day(90)], note='Family hardship, agreed with the Bursar.')
    crop(b, 'plans-custom-form', '#page-header', '#id_submitbutton', pad=4)
    submit(b)
    shows(b, 'Hardship plan')
    b.click_text('Cancel plan', 'a')
    b.fill('#id_reason', 'He paid the balance another way.')
    main_shot(b, 'plans-cancel-form')
    submit(b)
    shows(b, 'Cancelled')
    assert 'On a payment plan' not in b.text('#region-main'), 'still on a plan'
    plan_section(b, 'plans-cancelled', '.small.text-muted.mb-3')


def no_down_payment(b):
    """A down payment of 0 counts as paid at once, so a course that waits opens with the plan."""
    account(b, 'Yuki Mori')
    shows(b, 'Course access waits for payment.')
    custom_plan(b, 'Sponsor letter on file', [day(30)], down='0')
    submit(b)
    assert 'Course access waits for payment.' not in b.text('#region-main'), 'the course still waits'


def late_start(b):
    """A plan given after its due dates have passed starts late."""
    account(b, 'Eli Rosen')
    b.click_text('Give a payment plan')
    b.select('#id_termid', TERM)
    submit(b)
    billing_task()
    billing_task()
    tool('adhoc')
    account(b, 'Eli Rosen')
    assert b.text('#region-main').count('Late fee: plan payment due') == 2, 'late fees'
    plan_section(b, 'plans-given-late')


def reminder(b):
    """A plan payment due within the reminder days gets a reminder from the daily task."""
    account(b, 'Samuel Ito (Sam)')
    custom_plan(b, 'Short plan', [day(3), day(33)], term=TERM)
    submit(b)
    billing_task()
    tool('adhoc')


def reversed_payments(b):
    """A payment of a finished plan is reversed: the plan reopens, unless another plan is active."""
    account(b, 'Carmen Luz Vega')
    shows(b, 'Paid in full')
    row_link(b, 'Plan down payment', 'Reverse')
    b.fill('#id_reason', 'The card payment was disputed.')
    submit(b)
    shows(b, f'Four-payment plan - {TERM} - Active')
    b.click_text('Record a payment')
    b.fill('#id_amount', '1003.75')
    b.fill('#id_reference', 'Check 5120')
    b.fill('#id_note', 'Replaces the disputed card payment.')
    submit(b)
    shows(b, f'Four-payment plan - {TERM} - Paid in full')
    b.click_text('Give a payment plan')
    b.select('#id_termid', NEXT)
    submit(b)
    row_link(b, 'Replaces the disputed card payment.', 'Reverse')
    b.fill('#id_reason', 'The bank returned the check.')
    submit(b)
    crop(b, 'plans-not-reopened', '.alert-warning', pad=4)
    shows(b, 'Closed, with a payment reversed')


def late_plan(b):
    """What late payments look like to Finance."""
    account(b, 'Dara Whitman')
    plan_section(b, 'plans-late-account')
    close_menu(b)
    b.go(b.url())
    crop(b, 'plans-late-lines', mark_row(b, 'c', 'Late fee: plan payment due'),
         mark_row(b, 'd', 'Late fee: plan payment due', last=True), pad=0)


def student(b, username, shots):
    """A student's own plan."""
    b.login(username)
    b.go(SR + '/index.php')
    b.go(b.js("return [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'My account').href"))
    shots(b)


def main():
    b = start()
    try:
        b.login('felix')
        school_plan(b)
        one_plan_at_a_time(b)
        custom_and_cancel(b)
        no_down_payment(b)
        late_start(b)
        reminder(b)
        reversed_payments(b)
        late_plan(b)
        b.logout()
        student(b, 'sam', lambda p: crop(p, 'plans-student-steps', mark(p, 'a', 'Payment plans', 'h3'), 'ol.sr-plan', pad=6))
        b.go('/message/output/popup/notifications.php')
        crop(b, 'plans-reminder-message', '.control-area .content-item-container',
             '.control-area .content-item-container:nth-child(2)', pad=2)
    finally:
        b.close()
    phone = start(width=390, height=844, mobile=True)
    try:
        student(phone, 'dara', lambda p: crop(p, 'plans-student-phone', mark(p, 'a', 'Payment plans', 'h3'), 'ol.sr-plan', pad=6))
    finally:
        phone.close()


main()
