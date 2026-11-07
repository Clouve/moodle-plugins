#!/usr/bin/env python3
"""Student Records: the federal aid module, as the Financial Aid officer, and an award letter, as a student."""
# Snapshot: seed
import os
import sys
from datetime import date

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import (  # noqa: E402
    SR, aid_student, close_menu, crop, main_shot, mark, mark_next, open_menu, press, row_link, shows, start, submit, term_names,
    tick)

TERM = term_names()[0]
TODAY = date.today()
FIRST = TODAY.year if TODAY.month >= 7 else TODAY.year - 1
YEAR = f'{FIRST}-{str(FIRST + 1)[2:]}'


def set_day(b, css, day):
    """Set a date box."""
    b.js("document.querySelector(arguments[0]).value = arguments[1];", css, day)


def home_and_list(b):
    """Aid home, and the list of students."""
    b.go(SR + '/index.php?view=aid')
    crop(b, 'aid-home', '#page-header', '#region-main', pad=4)
    b.go(SR + '/aid.php')
    crop(b, 'aid-students', '#page-header', 'tbody tr:nth-child(6)', pad=4)
    b.go(SR + '/aid.php?report=verification')
    crop(b, 'aid-verification', '.nav-tabs', mark_next(b, 'z', '.nav-tabs', 'table'), pad=4)


def a_student(b):
    """One student's page, with awards, aid paid out and year details."""
    close_menu(b)
    aid_student(b, 'Carmen Luz Vega')
    crop(b, 'aid-student', '#page-header h1', mark_next(b, 'z', mark(b, 'a', 'Disbursements', 'h2'), 'table'), pad=4)
    open_menu(b)


def new_student(b):
    """Year details, an award, the student's choice and a disbursement by hand."""
    aid_student(b, 'Gina Marsh')
    b.fill('#aycoa', '16400')
    b.fill('#aysai', '1500')
    b.select('#aydependency', 'independent')
    b.select('#aygradelevel', 'second year')
    b.select('#ayverification', 'Selected for verification')
    tick(b, '#aycounseling')
    crop(b, 'aid-year-details', 'details.sr-details', pad=4)
    press(b, 'Save changes')
    shows(b, 'Saved.', 'body')

    b.click_text('Add an award', 'a')
    form = b.url()
    b.fill('#id_awardyear', '26-27')
    b.fill('#id_amount', '1500')
    submit(b)
    shows(b, 'Write the award year like 2026-27.')
    shows(b, 'Choose a term. Federal aid is counted by term.')
    b.go(form)
    b.fill('#id_awardyear', YEAR)
    b.select('#id_termid', TERM)
    b.select('#id_fund', 'Federal Pell Grant')
    b.select('#id_status', 'Offered')
    b.fill('#id_amount', '1500')
    b.fill('#id_note', 'From the aid software, packaged this week.')
    main_shot(b, 'aid-award-form')
    submit(b)
    shows(b, 'Federal Pell Grant')
    assert 'Record a disbursement' not in b.text('#region-main'), 'only an accepted award can be disbursed'

    row_link(b, 'Federal Pell Grant', 'Edit')
    b.select('#id_status', 'Accepted')
    submit(b)
    row_link(b, 'Federal Pell Grant', 'Record a disbursement')
    b.fill('#id_amount', '1600')
    submit(b)
    shows(b, 'Enter an amount above zero and not more than what is left to disburse ($1,500.00).')
    b.fill('#id_amount', '750')
    main_shot(b, 'aid-disburse-form')
    submit(b)
    shows(b, 'Aid disbursement: Federal Pell Grant')
    shows(b, 'Record a disbursement')


def the_run(b):
    """The disbursement run of a term, a hold taken off, and the ready lines sent."""
    b.go(SR + '/aid.php?report=run')
    b.select('#aidterm', TERM)
    b.click_text('Show', 'button')
    crop(b, 'aid-run', '.nav-tabs', mark_next(b, 'z', '.nav-tabs', 'table'), pad=4)
    shows(b, 'Held · verification not complete')
    shows(b, 'Paid by payroll')
    shows(b, 'Stopped · withdrew')

    aid_student(b, 'Theodore Bauer (Theo)')
    b.click('details.sr-details summary')
    b.select('#ayverification', 'Verification complete')
    press(b, 'Save changes')
    b.go(SR + '/aid.php?report=run')
    b.select('#aidterm', TERM)
    b.click_text('Show', 'button')
    b.js("[...document.querySelectorAll('button')].find(e => e.innerText.startsWith('Send ')).click();")
    b.settle()
    shows(b, 'disbursement(s) sent to student accounts', 'body')
    crop(b, 'aid-run-sent', '.alert-success', pad=4)
    shows(b, 'Held')


def letter(b):
    """Send the award letter, and open it."""
    aid_student(b, 'Samuel Ito (Sam)')
    press(b, 'Send award letter')
    crop(b, 'aid-letter-sent', '.alert', pad=4)
    b.click_text('View letter', 'a')
    crop(b, 'aid-letter', '#page-header', '#region-main', pad=4)
    b.click_text('Printable version', 'a')
    shows(b, 'To accept or decline an award, or if your situation changes, contact the financial aid office.', 'body')


def reversal(b):
    """Reverse a disbursement."""
    aid_student(b, 'Uma Reddy')
    row_link(b, 'Aid disbursement: Federal Pell Grant', 'Reverse')
    b.fill('#id_reason', 'Sent to the wrong term.')
    main_shot(b, 'aid-reverse-form')
    submit(b)
    shows(b, 'Saved.', 'body')
    crop(b, 'aid-reversed', '#region-main table', mark_next(b, 'z', mark(b, 'b', 'Disbursements', 'h2'), 'table'), pad=4)
    shows(b, 'Record a disbursement')


def reports(b):
    """Every aid report."""
    first = f'{FIRST}-07-01'
    b.go(SR + '/aid.php?report=recipients')
    crop(b, 'aid-report-recipients', '.nav-tabs', 'tbody tr:nth-child(6)', pad=4)
    b.go(SR + '/aid.php?report=disbursements')
    set_day(b, '#id_from', first)
    b.click('input[type=submit][value=Show]')
    crop(b, 'aid-report-disbursements', '.nav-tabs', 'tbody tr:nth-child(5)', pad=4)
    b.go(SR + '/aid.php?report=enrollment')
    b.select('select[name=termid]', TERM)
    b.click('input[type=submit][value=Show]')
    crop(b, 'aid-report-enrollment', '.nav-tabs', 'tbody tr:nth-child(6)', pad=4)
    b.go(SR + '/aid.php?report=withdrawals')
    b.select('select[name=termid]', TERM)
    b.click('input[type=submit][value=Show]')
    crop(b, 'aid-report-withdrawals', '.nav-tabs', mark_next(b, 'z', '.nav-tabs', 'table'), pad=4)
    b.go(SR + '/aid.php?report=ninetyten')
    crop(b, 'aid-report-ninetyten', '.nav-tabs', mark_next(b, 'z', '.nav-tabs', 'table'), pad=4)


def limits(b):
    """What the Financial Aid role can't open."""
    aid_student(b, 'Carmen Luz Vega')
    page = b.url().split('id=')[1].split('&')[0]
    b.go(SR + '/account.php?id=' + page)
    shows(b, 'Sorry, but you do not currently have permissions to do that', 'body')
    b.go(SR + '/standing.php')
    shows(b, 'Sorry, but you do not currently have permissions to do that', 'body')


def student_letter(b):
    """The student can open the letter from its address. No page links to it."""
    b.login('sam')
    b.go(SR + '/index.php')
    b.go(b.js("return [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'My account').href"))
    assert 'aidletter.php' not in b.js("return document.body.innerHTML"), 'a page links to the letter'
    page = b.url().split('id=')[1].split('&')[0]
    b.go(SR + '/aidletter.php?id=' + page + '&year=' + YEAR)
    shows(b, 'Award letter for', 'body')


def main():
    b = start()
    try:
        b.login('gwen')
        home_and_list(b)
        a_student(b)
        new_student(b)
        the_run(b)
        letter(b)
        reversal(b)
        close_menu(b)
        reports(b)
        limits(b)
        b.logout()
        student_letter(b)
    finally:
        b.close()


main()
