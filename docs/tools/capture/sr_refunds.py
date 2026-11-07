#!/usr/bin/env python3
"""Student Records: refunds, from a drop by the Registrar to the money paid back, as the Finance officer."""
# Snapshot: seed
import os
import sys
from datetime import date, timedelta

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import (  # noqa: E402
    ADMINPASS, SR, start, account, click_js, close_menu, crop, main_shot, mark, mark_next, mark_row, open_menu, press, row_link,
    set_date, shows, submit, term_dates, term_names)


def a_drop(b):
    """The Registrar drops a student from a class of next term."""
    b.login('rosa')
    b.go(SR + '/students.php?q=Sean')
    b.click_text('Sean Doyle', 'table a')
    b.click_text('Registration and grades', 'a')
    row_link(b, 'COM150', 'Drop')
    b.fill('#id_note', 'Changed his mind.')
    submit(b)
    shows(b, 'Saved.', 'body')
    b.logout()


def own_refund(b):
    """A person who approves refunds can't decide their own."""
    b.login('kira')
    b.go(SR + '/refunds.php')
    row_link(b, 'Kira Olsen', 'Review')
    shows(b, 'You cannot decide a refund on your own account. Ask someone else who approves refunds.')
    crop(b, 'refunds-own', mark(b, 'a', 'Step 1', 'h2'), mark(b, 'z', 'You cannot decide', 'p'), pad=10)
    b.logout()


def the_list(b):
    """The list, as Finance sees it."""
    b.login('felix')
    b.go(SR + '/refunds.php')
    close_menu(b)
    b.go(SR + '/refunds.php')
    crop(b, 'refunds-list', '.nav-tabs', mark_next(b, 'z', '.nav-tabs', 'table'), pad=4)
    open_menu(b)


def review(b):
    """One refund: how it was worked out and the decision."""
    row_link(b, 'Sean Doyle', 'Review')
    crop(b, 'refunds-review', '#page-header', '.sr-columns', pad=4)
    shows(b, 'How the refund was worked out')


def figure_again(b):
    """A refund with nothing back is figured on an earlier date, and now has money back."""
    start = date.fromisoformat(term_dates()[term_names()[0]][0]) + timedelta(days=20)
    b.go(SR + '/refunds.php?status=none')
    row_link(b, 'Eli Rosen', 'Review')
    shows(b, 'Nothing back')
    b.click('details.sr-details summary')
    set_date(b, 'eventdate', start.year, start.month, start.day)
    crop(b, 'refunds-figure-again', 'details.sr-details', pad=4)
    press(b, 'Figure again on another date')
    shows(b, 'Saved.', 'body')
    shows(b, 'Waiting for approval')
    crop(b, 'refunds-figured-again', mark(b, 'a', 'How the refund was worked out', 'h2'), '.sr-costtotal', pad=8)


def approve(b):
    """Approve the refund, and see the lines on the account."""
    b.select('#id_decision', 'Approve the refund')
    b.fill('#id_note', 'Last day in class confirmed by his teacher.')
    press(b, 'Save decision')
    shows(b, 'Approved: record how the money was paid back.')
    crop(b, 'refunds-approved', '.sr-col-side', pad=4)
    b.click_text('Open the account', 'a')
    close_menu(b)
    b.go(b.url())
    crop(b, 'refunds-lines', mark_row(b, 'a', 'Refund 50%'), pad=0)
    open_menu(b)


def reject(b):
    """Rejecting needs a note."""
    b.go(SR + '/refunds.php')
    row_link(b, 'Vincent Marlow', 'Review')
    b.select('#id_decision', 'Reject the refund')
    press(b, 'Save decision')
    shows(b, 'Give a reason.')
    b.select('#id_decision', 'Reject the refund')
    b.fill('#id_note', 'He asked to stay in the class.')
    press(b, 'Save decision')
    shows(b, 'Rejected')
    shows(b, 'He asked to stay in the class.')


def out_of_date(b):
    """A charge reversed after the refund was figured: the approval stops, and the refund is figured again."""
    account(b, 'Kira Olsen')
    row_link(b, 'tuition: PSY101', 'Reverse')
    b.fill('#id_reason', 'Added by mistake.')
    submit(b)
    b.go(SR + '/refunds.php')
    row_link(b, 'Kira Olsen', 'Review')
    b.select('#id_decision', 'Approve the refund')
    press(b, 'Save decision')
    shows(b, 'A charge in this refund was reversed or refunded after the refund was figured. Figure the refund again.', 'body')
    b.click('details.sr-details summary')
    press(b, 'Figure again on another date')
    shows(b, 'Saved.', 'body')


def pay_back(b):
    """An approved refund: the money is paid back outside Moodle, then recorded."""
    b.go(SR + '/refunds.php?status=approved')
    row_link(b, 'Daniel Reyes (Dan)', 'Review')
    b.click_text('Record money paid back', 'a')
    owed = b.text('#fitem_id_owed').split('$')[1].replace(',', '').strip()
    b.select('#id_method', 'Check')
    b.fill('#id_amount', str(float(owed) + 1))
    submit(b)
    shows(b, 'Enter an amount above zero and not more than the school owes')
    b.fill('#id_amount', owed)
    b.fill('#id_reference', 'Check 7118')
    b.fill('#id_note', 'Mailed to his home address.')
    main_shot(b, 'refunds-payout-form')
    submit(b)
    shows(b, 'Money paid back: Check')
    assert 'Record money paid back' not in b.text('.sr-actions'), 'the button should be gone at $0.00'
    # The refund stays as it is.
    b.go(SR + '/refunds.php?status=approved')
    row_link(b, 'Daniel Reyes (Dan)', 'Review')
    shows(b, 'Approved')
    shows(b, 'Record money paid back')

    # An approved refund that didn't take the account below zero: nothing is owed.
    b.go(SR + '/refunds.php?status=approved')
    row_link(b, 'Eli Rosen', 'Review')
    b.click_text('Record money paid back', 'a')
    shows(b, '$0.00', '#fitem_id_owed')
    b.fill('#id_amount', '10')
    submit(b)
    shows(b, 'Enter an amount above zero and not more than the school owes ($0.00).')

    # A credit balance that has no refund behind it.
    account(b, 'Marco Ruiz')
    crop(b, 'refunds-credit-balance', '#page-header', '.sr-tiles, .sr-tile-row, .alert, h2.sr-h2', pad=4)


def return_of_aid(b):
    """A return of aid from Financial Aid: Finance approves it, and nothing is paid to the student."""
    b.go(SR + '/refunds.php')
    row_link(b, 'Nell Hughes', 'Review')
    crop(b, 'refunds-aid-return', '#page-header', '.sr-columns', pad=4)
    assert not b.has('details.sr-details'), 'a return of aid can not be figured again'
    b.select('#id_decision', 'Approve the refund')
    b.fill('#id_note', 'Checked against the official worksheet.')
    press(b, 'Save decision')
    shows(b, 'send this money to the Department of Education through G5')
    assert 'Record money paid back' not in b.text('.sr-col-side'), 'no payout for a return of aid'
    crop(b, 'refunds-aid-return-approved', '.sr-col-side', pad=4)
    b.click_text('Open the account', 'a')
    shows(b, 'Federal aid returned: Direct Subsidized Loan')


def no_approver(b):
    """A person who manages accounts and may not approve refunds. The site administrator takes the capability off the role."""
    b.logout()
    b.login('admin', ADMINPASS)
    b.go('/admin/roles/manage.php')
    b.click_text('Finance (Student Records)', 'a')
    b.click_text('Edit', 'a, button, input[type=submit]')
    b.js("document.querySelector('[name=\"local/studentrecords:approverefund\"][value=\"1\"]').checked = false;")
    click_js(b, '#id_savechanges, input[name=savechanges], #savechanges')
    b.settle()
    b.logout()
    b.login('felix')
    b.go(SR + '/refunds.php')
    row_link(b, 'Sean Doyle', 'Review')
    shows(b, 'Waiting for someone who may approve refunds.')


def main():
    b = start()
    try:
        a_drop(b)
        own_refund(b)
        the_list(b)
        review(b)
        figure_again(b)
        approve(b)
        reject(b)
        out_of_date(b)
        pay_back(b)
        return_of_aid(b)
        no_approver(b)
    finally:
        b.close()


main()
