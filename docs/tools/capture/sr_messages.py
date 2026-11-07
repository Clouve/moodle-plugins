#!/usr/bin/env python3
"""Student Records: messages, test mode and the message log, as the Registrar, and a message as the student sees it."""
# Snapshot: seed
import os
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import SR, Browser as WD, crop, main_shot, pick, row, submit, tool  # noqa: E402


def log_rows(b, n=6):
    """The newest rows of the message log, for the output."""
    b.go(SR + '/messages.php?tab=log')
    print(b.js('''return [...document.querySelectorAll('#region-main table tbody tr')].slice(0, arguments[0])
        .map(r => [...r.cells].map(c => c.innerText).join(' | '))''', n))


def log_row(b, n):
    """A selector for the nth row of the message log."""
    return f'#region-main table tbody tr:nth-child({n})'


def following_term(b):
    """The name of the term after the running one, from the offerings page."""
    b.go(SR + '/offerings.php')
    return b.js("return document.querySelector('#offterm').options[5].text")


def open_student(b):
    """Open Gina Marsh's file."""
    b.go(SR + '/students.php?search=Gina')
    b.click_text('Gina Marsh', '#region-main a')
    return b.url()


def place_hold(b, reason):
    """Place a hold on Gina Marsh's file."""
    b.go(open_student(b) + '&tab=holds')
    b.click_text('Place a hold', 'a')
    b.fill('#id_reason', reason)
    b.select('#id_blocksreg', 'No')
    submit(b)


def register(b, section):
    """Register Gina Marsh in a section of next term."""
    student = open_student(b)
    number = student.split('id=')[1]
    b.go(f'{SR}/register.php?id={number}&action=add')
    pick(b, 'offerid', section)
    submit(b)


def main():
    b = WD()
    b.login('rosa')
    b.go(SR + '/staff.php')
    b.click_text('Messages', '#page-wrapper nav a, .drawer a, a.sr-nav-link, a')
    print(b.url())
    if 'messages.php' not in b.url():
        b.go(SR + '/messages.php')
    main_shot(b, 'messages-list')

    # One message, with the school's own text.
    b.go(SR + '/messages.php?key=holdplaced')
    main_shot(b, 'messages-edit')
    b.fill('#id_body', 'Hello {firstname},\n\nThe office has placed a hold on your student record. '
           'Please log in to see it, and call us at {office_phone} with questions: {link}')
    submit(b)
    crop(b, 'messages-own-text', row(b, 'Hold placed', 0), pad=0)

    # A test of that message.
    b.go(SR + '/messages.php?key=holdplaced')
    b.click_text('Send me a test', 'button')
    time.sleep(0.5)
    b.settle()
    print(b.text('#user-notifications'))

    # A message written during other work waits for cron, then goes. A hold is written with no such work, so it goes at once.
    term = following_term(b)
    register(b, term + ': ACC201')
    place_hold(b, 'Library books not returned')
    log_rows(b, 3)
    crop(b, 'messages-log-waiting', '#region-main table thead', log_row(b, 3), pad=0)
    tool('cron')
    log_rows(b, 3)
    crop(b, 'messages-log', '#region-main .nav', log_row(b, 4), pad=0)
    b.close()

    # What the student sees.
    b = WD()
    b.login('gina')
    b.go('/message/output/popup/notifications.php')
    time.sleep(1.5)
    b.settle()
    print(b.text('#region-main')[:500])
    # The list only: the text of a message holds a link with the address of the site.
    b.js('''const items = document.querySelectorAll('.content-item-container');
            items[items.length - 1].id = 'shot-last';''')
    crop(b, 'messages-notification', '#region-main h2', '#shot-last', width=340)
    b.go('/message/notificationpreferences.php')
    time.sleep(1.2)
    b.settle()
    print(b.js('''const rows = [...document.querySelectorAll('#region-main tr')];
        const first = rows.findIndex(r => r.innerText.includes('Student account: payment reminders'));
        rows[first - 1].id = 'shot-first';
        const mine = rows.slice(first).filter(r => /^(Student|Registration:)/.test(r.innerText.trim()));
        mine[mine.length - 1].id = 'shot-last';
        return rows.slice(first - 1, first + 5).map(r => r.innerText.replace(/\\s+/g, ' ').slice(0, 70));'''))
    crop(b, 'messages-preferences', '#shot-first', '#shot-last')
    b.close()

    # Test mode with a test inbox.
    b = WD()
    b.login('rosa')
    b.go(SR + '/messages.php')
    b.fill('#msginbox', 'test-inbox@example.com')
    b.click_text('Save changes', 'button')
    b.click_text('Turn test mode on', 'input[type=submit], button')
    print(b.text('#user-notifications'))
    crop(b, 'messages-test-mode', '#region-main .alert-warning', '#region-main .sr-filter + p')

    # A message goes to the inbox.
    place_hold(b, 'Parking fine not paid')
    b.go(SR + '/messages.php?key=holdreleased')
    b.select('#id_on', 'No')
    submit(b)
    tool('adhoc')
    log_rows(b, 3)
    crop(b, 'messages-log-test', '#region-main table thead', log_row(b, 3), pad=0)

    # A message that is off goes nowhere, and the log says so.
    b.go(open_student(b) + '&tab=holds')
    b.click_text('Release hold', 'a')
    b.fill('#id_releasenote', 'Paid at the front desk')
    submit(b)
    log_rows(b, 1)

    # Back to normal.
    b.go(SR + '/messages.php')
    b.click_text('Turn test mode off', 'input[type=submit], button')
    print(b.text('#user-notifications'))
    b.close()


if __name__ == '__main__':
    main()
