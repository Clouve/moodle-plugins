#!/usr/bin/env python3
"""Student Records: student accounts, as the Finance officer, and My account, as a student."""
# Snapshot: seed
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import (  # noqa: E402
    SR, account, close_menu, crop, img, main_shot, mark, mark_next, mark_row, pdf_shot, pdf_text, pick, row_link, shows, start,
    submit, term_names, tool)

THEO = 'Theodore Bauer (Theo)'
TERM = term_names()[0]


def the_list(b):
    """The list of accounts, with its filter."""
    b.go(SR + '/billing.php')
    b.select('select[name=filter]', 'Student owes money')
    b.click('input[type=submit][value=Search]')
    crop(b, 'accounts-list', '#page-header', mark_next(b, 'z', mark(b, 'a', 'Student', 'th'), 'tbody tr:last-child'), pad=4)
    crop(b, 'accounts-add-missing', mark(b, 'b', 'Add missing charges for a term', 'h3'), '.singleselect', pad=10)


def charges(b):
    """The top of an account, a charge from the price list, then a charge that isn't on it."""
    account(b, THEO)
    crop(b, 'accounts-top', '#page-header', '.alert-secondary', pad=4)
    b.click_text('Add a charge')
    pick(b, '#id_feeid', 'Graduation fee')
    b.select('#id_termid', TERM)
    b.fill('#id_note', 'Asked for the graduation packet early.')
    main_shot(b, 'accounts-charge-form')
    submit(b)
    shows(b, 'Saved.', 'body')
    b.click_text('Add a charge')
    b.fill('#id_description', 'Library fine')
    b.fill('#id_amount', '12.50')
    b.select('#id_termid', TERM)
    b.select('#id_qualified', 'No')
    b.select('#id_refundable', 'No')
    b.fill('#id_note', 'Book returned six weeks late.')
    crop(b, 'accounts-charge-other', '#fitem_id_feeid', '#fitem_id_note', pad=8)
    submit(b)
    shows(b, 'Library fine')


def credits_and_payments(b):
    """A credit, then a payment with its receipt."""
    account(b, THEO)
    b.click_text('Add a credit')
    b.select('#id_method', 'Scholarship')
    b.fill('#id_description', 'Riverside Merit Scholarship')
    b.fill('#id_amount', '500')
    b.select('#id_termid', TERM)
    b.fill('#id_note', 'Awarded by the scholarship committee.')
    main_shot(b, 'accounts-credit-form')
    submit(b)
    b.click_text('Record a payment')
    b.select('#id_method', 'Check')
    b.fill('#id_amount', '750')
    b.fill('#id_reference', 'Check 4417')
    b.select('#id_termid', TERM)
    b.fill('#id_note', 'Mailed by his employer.')
    main_shot(b, 'accounts-payment-form')
    submit(b)
    shows(b, 'Payment: Check')
    receipt = b.js("return [...document.querySelectorAll('td a')].filter(a => a.href.includes('receipt.php')).pop().href")
    text = pdf_shot(b, receipt, 'accounts-receipt', height=540)
    assert 'Thank you for your payment.' in text and 'Check 4417' in text, text
    return receipt


def paid_in_full(b):
    """A payment of the whole balance opens the courses that wait for it."""
    account(b, 'Jonah Price')
    shows(b, 'Course access waits for payment.')
    balance = b.js("return document.querySelector('.sr-file-name + p strong').innerText").replace('$', '').replace(',', '')
    b.click_text('Record a payment')
    b.select('#id_method', 'Bank transfer or wire')
    b.fill('#id_amount', balance)
    submit(b)
    assert 'Course access waits for payment.' not in b.text('#region-main'), 'courses still wait'


def reversals(b, receipt):
    """A payment that bounced, a registration charge reversed and added back, a line that can't be reversed."""
    account(b, THEO)
    row_link(b, 'Payment: Check', 'Reverse')
    b.fill('#id_reason', 'The bank returned the check.')
    main_shot(b, 'accounts-reverse-form')
    submit(b)
    text = pdf_text(b, receipt)
    assert 'later reversed' in text, text

    row_link(b, 'tuition: PSY101', 'Reverse')
    b.fill('#id_reason', 'Added by mistake.')
    submit(b)
    b.go(SR + '/billing.php')
    b.select('.singleselect select', TERM)
    b.js("document.querySelector('.singleselect form').submit();")
    b.settle()
    crop(b, 'accounts-add-missing-confirm', '#notice', pad=4)
    b.click_text('Continue', 'button, input[type=submit], a')
    shows(b, 'charge lines added.', 'body')
    b.select('.singleselect select', TERM)
    b.js("document.querySelector('.singleselect form').submit();")
    b.settle()
    b.click_text('Continue', 'button, input[type=submit], a')
    shows(b, '0 charge lines added.', 'body')

    account(b, 'Daniel Reyes (Dan)')
    row_link(b, 'tuition: ENG102', 'Reverse')
    b.fill('#id_reason', 'Added by mistake.')
    submit(b)
    shows(b, 'This charge has a refund on it. Reverse the refund lines first.', 'body')
    row_link(b, 'Refund 25%: Undergraduate tuition: ENG102', 'Reverse')
    b.fill('#id_reason', 'Added by mistake.')
    submit(b)
    row_link(b, 'tuition: ENG102', 'Reverse')
    b.fill('#id_reason', 'Added by mistake.')
    submit(b)
    shows(b, 'Saved.', 'body')

    # Finance has no Reverse link on an aid line.
    account(b, 'Marco Ruiz')
    shows(b, 'Federal Pell Grant')
    links = b.js("""return [...document.querySelectorAll('tr')].filter(r => r.innerText.includes('Federal Pell Grant') &&
        r.innerText.includes('Credit')).map(r => r.querySelectorAll('a').length)""")
    assert links and not any(links), links


def course_access(b):
    """A course that waits for payment, opened early with a reason."""
    account(b, 'Ivy Stone')
    crop(b, 'accounts-access-waiting', '.alert-warning', pad=4)
    b.click_text('Give course access now', 'li a')
    b.fill('#id_reason', 'Her sponsor confirmed payment by phone.')
    main_shot(b, 'accounts-access-form')
    submit(b)
    shows(b, 'Saved.', 'body')


def wide_tables(b):
    """What needs the width of the page: the lines of one term, the statement of that term, aid and refunds."""
    close_menu(b)
    account(b, THEO)
    b.select('.singleselect select', TERM)
    b.js("document.querySelector('.singleselect form').submit();")
    b.settle()
    shows(b, 'Only the lines of this term are shown; the balance is for the whole account.')
    crop(b, 'accounts-lines', mark(b, 'a', 'Charges and payments', 'h2'), mark_next(b, 'z', '[data-shot=a]', 'table'), pad=0)
    crop(b, 'accounts-reversed', mark_row(b, 'c', 'Payment: Check'), mark_row(b, 'd', 'Reversal of line'), pad=0)
    statement = b.js("return [...document.querySelectorAll('a')].find(a => a.innerText.trim() === 'Statement (PDF)').href")
    text = pdf_shot(b, statement, 'accounts-statement', dpi=75)
    assert 'Balance due:' in text and 'Only the lines of this term' in text, text
    account(b, 'Daniel Reyes (Dan)')
    crop(b, 'accounts-aid-refunds', mark(b, 'a', 'Financial aid', 'h3'),
         mark_next(b, 'z', mark(b, 'b', 'Refunds', 'h3'), 'table'), pad=6)
    # The Finance role doesn't open the academic file.
    b.go(b.url().replace('account.php', 'student.php'))
    shows(b, 'Sorry, but you do not currently have permissions to do that', 'body')


def student(b, phone):
    """My account, as the student."""
    b.login('theo')
    b.go(SR + '/index.php')
    b.go(b.js("return [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'My account').href"))
    shows(b, 'Charges and payments')
    if phone:
        b.shot(img('accounts-student-phone'), full=False)
        wide = b.js("const box = document.querySelector('.table-responsive'); return box.scrollWidth > box.clientWidth;")
        assert wide, 'the lines should scroll sideways on a phone'
    else:
        close_menu(b)
        b.go(b.url())
        crop(b, 'accounts-student', '.sr-actions', mark(b, 'a', 'Charges and payments', 'h2'), pad=4)


def main():
    b = start()
    try:
        b.login('felix')
        the_list(b)
        charges(b)
        receipt = credits_and_payments(b)
        paid_in_full(b)
        reversals(b, receipt)
        course_access(b)
        wide_tables(b)
        b.logout()
        student(b, False)
    finally:
        b.close()
    tool('adhoc')
    phone = start(width=390, height=844, mobile=True)
    try:
        student(phone, True)
    finally:
        phone.close()


main()
