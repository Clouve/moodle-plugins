#!/usr/bin/env python3
"""Student Records: the loan check and the Return of aid worksheet, as the Financial Aid officer."""
# Snapshot: seed
import os
import sys
from datetime import date, timedelta

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import (  # noqa: E402
    SR, account, aid_student, crop, mark_next, press, row_link, set_date, shows, start, submit, term_dates, term_names)

TERM = term_names()[0]
TODAY = date.today()
FIRST = TODAY.year if TODAY.month >= 7 else TODAY.year - 1
YEAR = f'{FIRST}-{str(FIRST + 1)[2:]}'


def set_day(b, css, day):
    """Set a date box."""
    b.js("document.querySelector(arguments[0]).value = arguments[1];", css, day)


def add_award(b, fund, amount):
    """Add an accepted award of the running term."""
    b.click_text('Add an award', 'a')
    b.fill('#id_awardyear', YEAR)
    b.select('#id_termid', TERM)
    b.select('#id_fund', fund)
    b.select('#id_status', 'Accepted')
    b.fill('#id_amount', amount)
    submit(b)


def loan_checks(b):
    """The three notes of the loan check, and the row it marks."""
    aid_student(b, 'Samuel Ito (Sam)')
    shows(b, 'This student is enrolled for')
    shows(b, 'Loans are over the yearly limit.')
    crop(b, 'aid-loan-over', '.sr-note', mark_next(b, 'z', '.sr-note', 'table'), pad=4)
    aid_student(b, 'Carmen Luz Vega')
    add_award(b, 'Direct Subsidized Loan', '3000')
    shows(b, 'The subsidized loan is over its yearly limit.')
    crop(b, 'aid-loan-sub-over', '.sr-note', pad=4)
    aid_student(b, 'Alma Dunn')
    add_award(b, 'Direct Unsubsidized Loan', '5000')
    shows(b, "This student's program is at a graduate level, so this page does not check their loans against a yearly limit.")
    crop(b, 'aid-loan-graduate', '.sr-note', pad=4)


def withdraw(b, name, courses):
    """The Registrar withdraws a student from every class of the term."""
    b.go(SR + '/students.php?q=' + name.split()[0])
    b.click_text(name, 'table a')
    for course in courses:
        b.click_text('Registration and grades', 'a')
        row_link(b, course, 'Withdraw')
        b.fill('#id_note', 'Left the college.')
        submit(b)
        shows(b, 'Saved.', 'body')


def withdrawn_before_the_term(b):
    """The Registrar marks a student Withdrawn, with a date outside the term. Nothing then says when they left it."""
    b.go(SR + '/students.php?q=Vincent')
    b.click_text('Vincent Marlow (Vince)', 'table a')
    b.go(b.url().split('&')[0] + '&edit=1')
    b.select('#id_status', 'Withdrawn')
    day = date.fromisoformat(term_dates()[TERM][0]) - timedelta(days=5)
    set_date(b, 'withdrawdate', day.year, day.month, day.day)
    set_date(b, 'lastattended', day.year, day.month, day.day)
    submit(b)
    shows(b, 'Saved.', 'body')


def open_list(b):
    """The list of students who withdrew, for the term."""
    b.go(SR + '/aid.php?report=returns')
    b.select('#aidterm', TERM)
    b.click_text('Show', 'button')


def worksheet(b):
    """A worksheet with its details, then with breaks, a later date and aid that could have been disbursed."""
    open_list(b)
    crop(b, 'worksheet-list', '.nav-tabs', mark_next(b, 'z', '.nav-tabs', 'table'), pad=4)
    row_link(b, 'Daniel Reyes (Dan)', 'Open helper')
    shows(b, 'This is a helper, not the final answer.')
    crop(b, 'worksheet-details', '#page-header', 'details.sr-details', pad=4)
    crop(b, 'worksheet-steps', '.sr-columns', pad=4)

    left = b.js("return document.querySelector('#wswithdrew').value")
    later = (date.fromisoformat(left) + timedelta(days=4)).isoformat()
    set_day(b, '#wsdetermined', later)
    b.fill('#wscouldgrants', '400')
    b.fill('#wsbreakdays', '7')
    b.fill('#wsbreaksbefore', '0')
    b.click_text('Update worksheet', 'button')
    shows(b, 'Aid that could have been disbursed')
    shows(b, 'not counting breaks')
    crop(b, 'worksheet-updated', '.sr-columns', pad=4)

    # A detail that makes no sense is left out, and the page says so.
    set_day(b, '#wswithdrew', (date.fromisoformat(term_dates()[TERM][1]) + timedelta(days=30)).isoformat())
    b.click_text('Update worksheet', 'button')
    shows(b, 'These details could not be used, so the worksheet left them out: Withdrawal date.')

    # Aid paid after the date the school determined the withdrawal is left out.
    first = date.fromisoformat(term_dates()[TERM][0])
    set_day(b, '#wswithdrew', (first + timedelta(days=1)).isoformat())
    set_day(b, '#wsdetermined', (first + timedelta(days=1)).isoformat())
    b.click_text('Update worksheet', 'button')
    shows(b, 'was paid out after the date the school determined the withdrawal, so it is left out.')

    # The figures move while the worksheet is open: nothing is sent.
    open_list(b)
    row_link(b, 'Daniel Reyes (Dan)', 'Open helper')
    other = start()
    try:
        other.login('felix')
        account(other, 'Daniel Reyes (Dan)')
        other.click_text('Add a charge')
        other.fill('#id_description', 'Lab replacement fee')
        other.fill('#id_amount', '100')
        other.select('#id_termid', TERM)
        first = date.fromisoformat(term_dates()[TERM][0])
        set_date(other, 'txndate', first.year, first.month, first.day)
        other.fill('#id_note', 'Billed late.')
        submit(other)
    finally:
        other.close()
    press(b, 'Approve and send to Finance')
    shows(b, 'The figures changed since you opened this worksheet. Check them and send it again.', 'body')
    press(b, 'Approve and send to Finance')
    shows(b, 'Sent to Finance as a Return of aid item in Refunds.', 'body')
    crop(b, 'worksheet-sent', '.alert-success', pad=4)


def one_return(b):
    """A return exists for this student and term."""
    open_list(b)
    row_link(b, 'Nell Hughes', 'Open helper')
    press(b, 'Approve and send to Finance')
    shows(b, 'A Return of aid item for this student and term is already in Refunds.', 'body')


def past_sixty(b):
    """A student past 60% of the term: all aid earned, and a post-withdrawal disbursement when more could have been paid."""
    open_list(b)
    row_link(b, 'Marco Ruiz', 'Open helper')
    shows(b, 'over 60%: all aid earned')
    crop(b, 'worksheet-over-sixty', '.sr-columns', pad=4)
    b.fill('#wscouldgrants', '500')
    b.click_text('Update worksheet', 'button')
    shows(b, 'A post-withdrawal disbursement may be due: $500.00')
    crop(b, 'worksheet-pwd', '.sr-columns', pad=4)


def nothing_paid_and_no_date(b):
    """No aid paid out, then aid paid out and no date on file."""
    open_list(b)
    row_link(b, 'Vincent Marlow (Vince)', 'Open helper')
    shows(b, 'No federal aid was paid to this student in this term, so there is nothing to return.')
    aid_student(b, 'Vincent Marlow (Vince)')
    row_link(b, 'Direct Unsubsidized Loan', 'Record a disbursement')
    paid = date.fromisoformat(term_dates()[TERM][0]) + timedelta(days=3)
    set_date(b, 'txndate', paid.year, paid.month, paid.day)
    b.fill('#id_amount', '1000')
    submit(b)
    open_list(b)
    row_link(b, 'Vincent Marlow (Vince)', 'Open helper')
    shows(b, 'Nothing on file says when this student left this term.')
    crop(b, 'worksheet-no-date', '.sr-lead', '.sr-note-bad', pad=4)
    assert not b.has('.sr-col-side'), 'no steps without a date'
    set_day(b, '#wswithdrew', (paid + timedelta(days=20)).isoformat())
    b.click_text('Update worksheet', 'button')
    shows(b, 'School returns')


def clock_hours(b):
    """A clock-hour program needs scheduled hours."""
    open_list(b)
    row_link(b, 'Cleo Banks', 'Open helper')
    shows(b, 'This is a clock-hour program, so the worksheet needs scheduled hours.')
    assert not b.has('.sr-col-side form'), 'a clock-hour worksheet can not be sent without hours'
    b.fill('#wshoursdone', '30')
    b.fill('#wshoursperiod', '60')
    b.click_text('Update worksheet', 'button')
    shows(b, 'Scheduled clock hours: 30 of 60')
    crop(b, 'worksheet-clock', 'details.sr-details', '.sr-columns', pad=4)


def approved_stays(b):
    """A return that Finance approved blocks a new one for the term, even after its charges are reversed."""
    b.logout()
    b.login('felix')
    b.go(SR + '/refunds.php')
    row_link(b, 'Nell Hughes', 'Review')
    press(b, 'Save decision')
    b.logout()
    b.login('gwen')
    aid_student(b, 'Nell Hughes')
    for fund in ('Direct Subsidized Loan', 'Federal Pell Grant'):
        row_link(b, 'Federal aid returned: ' + fund, 'Reverse')
        b.fill('#id_reason', 'Worked out on the wrong date.')
        submit(b)
    open_list(b)
    row_link(b, 'Nell Hughes', 'Open helper')
    press(b, 'Approve and send to Finance')
    shows(b, 'A Return of aid item for this student and term is already in Refunds.', 'body')


def main():
    b = start()
    try:
        b.login('gwen')
        loan_checks(b)
        aid_student(b, 'Cleo Banks')
        row_link(b, 'Federal Pell Grant', 'Record a disbursement')
        paid = date.fromisoformat(term_dates()[TERM][0]) + timedelta(days=3)
        set_date(b, 'txndate', paid.year, paid.month, paid.day)
        b.fill('#id_amount', '900')
        submit(b)
        b.logout()
        b.login('rosa')
        withdraw(b, 'Marco Ruiz', ['ACC202', 'PSY101'])
        withdraw(b, 'Cleo Banks', ['PHL101'])
        withdrawn_before_the_term(b)
        b.logout()
        b.login('gwen')
        worksheet(b)
        one_return(b)
        past_sixty(b)
        nothing_paid_and_no_date(b)
        clock_hours(b)
        approved_stays(b)
    finally:
        b.close()


main()
