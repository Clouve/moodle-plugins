#!/usr/bin/env python3
"""Student Records: Billing setup as the Finance officer, and the Finance role as a site administrator."""
# Snapshot: seed
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import ADMINPASS, SR, start, account, click_js, crop, day, main_shot, mark, pick, row_link, shows, submit  # noqa: E402


def settings(b):
    """The settings at the top of the page."""
    b.go(SR + '/billingsetup.php')
    crop(b, 'billing-setup-settings', '#page-header', '#id_submitbutton', pad=4)


def prices(b):
    """Add a price, see the check on tuition, change a price and switch one off."""
    b.go(SR + '/billingsetup.php')
    crop(b, 'billing-setup-price-list', mark(b, 'a', 'Price list', 'h3'), mark(b, 'z', 'Add a price', 'a'), pad=10)
    b.click_text('Add a price')
    b.fill('#id_name', 'Capstone portfolio fee')
    b.select('#id_kind', 'Fee')
    b.select('#id_basis', 'Whole program')
    b.fill('#id_amount', '150')
    b.select('#id_scope', 'Program')
    pick(b, '#id_programid', 'Leadership')
    b.select('#id_refundable', 'No')
    b.fill('#id_note', 'Charged once, at the first registration in the program.')
    main_shot(b, 'billing-setup-price-form')
    submit(b)
    shows(b, 'Capstone portfolio fee')

    # Tuition can't be left for staff to add.
    b.go(SR + '/billingsetup.php?fee=0')
    b.fill('#id_name', 'Audit tuition')
    b.select('#id_kind', 'Tuition')
    b.select('#id_basis', 'Added by staff')
    b.fill('#id_amount', '100')
    submit(b)
    shows(b, 'Tuition must be charged per hour, per course, per term or per program.')

    # A new price from now on, and a price that is no longer used.
    b.go(SR + '/billingsetup.php')
    row_link(b, 'Technology fee', 'Edit')
    b.fill('#id_amount', '65')
    submit(b)
    row_link(b, 'Replacement ID card', 'Edit')
    b.select('#id_active', 'No')
    submit(b)
    shows(b, '$65.00')
    account(b, 'Theodore Bauer (Theo)')
    b.click_text('Add a charge')
    assert 'Replacement ID card' not in b.text('#id_feeid'), 'a price that is not active is still offered'


def plans(b):
    """Add a plan with fixed dates, and open a plan to change it."""
    b.go(SR + '/billingsetup.php')
    crop(b, 'billing-setup-plans', mark(b, 'a', 'Payment plans', 'h3'), mark(b, 'z', 'Add a payment plan', 'a'), pad=10)
    b.click_text('Add a payment plan')
    b.fill('#id_name', 'Monthly plan for certificates')
    b.select('#id_scope', 'Program')
    pick(b, '#id_programid', 'Phlebotomy')
    b.fill('#id_downvalue', '20')
    b.fill('#id_numpay', '4')
    b.fill('#id_firstdue', '14')
    b.fill('#id_planfee', '25')
    b.fill('#id_latefee', '15')
    b.fill('#id_gracedays', '3')
    main_shot(b, 'billing-setup-plan-form')
    submit(b)
    shows(b, 'Monthly plan for certificates')

    b.click_text('Add a payment plan')
    b.fill('#id_name', 'Three fixed dates')
    b.select('#id_downtype', 'an amount (US$)')
    b.fill('#id_downvalue', '300')
    b.fill('#id_numpay', '3')
    b.select('#id_duemode', 'fixed dates')
    b.fill('#id_duedates', ', '.join(day(n) for n in (30, 60, 90)))
    crop(b, 'billing-setup-plan-fixed', '#fitem_id_downtype', '#fitem_id_duedates', pad=8)
    b.fill('#id_duedates', day(30))
    submit(b)
    shows(b, 'Enter 3 dates written year-month-day')
    b.fill('#id_duedates', ', '.join(day(n) for n in (30, 60, 90)))
    submit(b)
    shows(b, 'Three fixed dates')

    # Switch a plan off. The heading of this form is the known problem on the page.
    row_link(b, 'Two-payment plan', 'Edit')
    b.select('#id_active', 'No')
    submit(b)
    account(b, 'Theodore Bauer (Theo)')
    b.click_text('Give a payment plan')
    assert 'Two-payment plan' not in b.text('#id_planid'), 'a plan that is not active is still offered'


def rules(b):
    """Add a refund rule in its place, and show a rule that needs no number."""
    b.go(SR + '/billingsetup.php')
    crop(b, 'billing-setup-refund-rules', mark(b, 'a', 'Refund rules', 'h3'), mark(b, 'z', 'Add a refund rule', 'a'), pad=10)
    b.click_text('Add a refund rule')
    b.fill('#id_name', 'First 60 days of the term')
    b.fill('#id_sortorder', '6')
    b.select('#id_ruletype', 'the drop is up to a number of days after the term starts')
    b.fill('#id_value', '60')
    b.fill('#id_tuitionpct', '10')
    b.fill('#id_feespct', '0')
    main_shot(b, 'billing-setup-rule-form')
    submit(b)
    row_link(b, 'After that', 'Edit')
    b.fill('#id_sortorder', '7')
    submit(b)
    shows(b, 'First 60 days of the term')


def switch(b):
    """Billing off, what that changes, and on again."""
    b.go(SR + '/billingsetup.php')
    b.fill('#id_payinfo', 'Pay online from your account page, by check to Riverside College, or at the Business Office.')
    b.select('#id_billing', 'No')
    submit(b)
    b.go(SR + '/billing.php')
    shows(b, 'Billing is off: no charges are added when students register.')
    crop(b, 'billing-setup-off', '#page-header', '.alert', pad=4)
    # A course that waited for payment still waits.
    account(b, 'Ivy Stone')
    shows(b, 'Course access waits for payment.')
    b.go(SR + '/billingsetup.php')
    b.select('#id_billing', 'Yes')
    submit(b)


def finance_role(b):
    """Give someone the Finance role, as a site administrator. The role exists on this site."""
    b.logout()
    b.login('admin', ADMINPASS)
    b.go(SR + '/billingsetup.php')
    b.click_text('Give people the Finance role')
    shows(b, 'Felix Romero', 'body')
    b.js('''const option = [...document.querySelectorAll('#addselect option')]
        .find(o => o.text.includes('Doris Hale'));
        option.selected = true;
        option.parentElement.dispatchEvent(new Event('change', {bubbles: true}));''')
    click_js(b, '#add')
    b.settle()
    crop(b, 'billing-setup-assign-role', '#page-header', '#assignform', pad=8)


def main():
    b = start()
    try:
        b.login('felix')
        settings(b)
        prices(b)
        plans(b)
        rules(b)
        switch(b)
        finance_role(b)
    finally:
        b.close()


main()
