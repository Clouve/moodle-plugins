#!/usr/bin/env python3
"""Student Records: staff registration, overrides, drops and withdrawals as the Registrar, and self-registration as students."""
# Snapshot: seed
import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (choose_user, clear_notices, close_menu, crop, expand_all, flash, main_shot, mark_row,  # noqa: E402
                       open_file, open_offering, person, phone, press, reg_link, set_date, site, SR, submit,
                       switch, term_table, terms, tick, tool)

b = person('rosa')
names = terms(b)
now, nextterm = names[-2], names[-1]
print(now, nextterm)
b.go(f'{SR}/students.php')
close_menu(b)


def register(b, last, code, override=False, note=''):
    """Open the Register for a course form of a student and pick a section of next term."""
    open_file(b, last, 'registration')
    b.click_text('Register for a course')
    choose_user(b, '#fitem_id_offerid input[role=combobox]', f'{nextterm}: {code}')
    if override:
        tick(b, '#id_override')
    if note:
        b.fill('#id_note', note)


# A plain registration.
register(b, 'Ruiz', 'MKT210 01')
crop(b, 'registration-staff-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('register', flash(b)[:90])

# A prerequisite stops it, and an override needs a reason.
register(b, 'Price', 'ACC201 01')
submit(b)
print('prereq', flash(b)[:90])
crop(b, 'registration-prereq-error', '#region-main h2', '#id_submitbutton', pad=6)
tick(b, '#id_override')
submit(b)
print('no reason', flash(b)[:90])
# The override from a fresh refusal, so that the screenshot shows only the error the guide's steps lead to.
register(b, 'Price', 'ACC201 01')
submit(b)
tick(b, '#id_override')
b.fill('#id_note', 'The dean agreed he can take it with BUS101.')
crop(b, 'registration-override', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('override', flash(b)[:90])
open_file(b, 'Price', 'registration')
clear_notices(b)
crop(b, 'registration-marked-override', '.sr-file-header', '#region-main table', pad=4)

# A hold that blocks registration can't be overridden. The full section can.
register(b, 'Stone', 'ENG102 01', True, 'Trying with an override')
submit(b)
print('hold', flash(b)[:120])
crop(b, 'registration-hold-error', '#region-main .alert-danger', pad=6)
register(b, 'Marsh', 'COM150 01')
submit(b)
print('full', flash(b)[:120])
register(b, 'Marsh', 'COM150 01', True, 'She needs it to graduate.')
submit(b)
print('full override', flash(b)[:120])
open_file(b, 'Marsh', 'registration')
reg_link(b, nextterm, 'COM150', 'Drop')
print('drop form', b.url())
submit(b)
print('drop it again', flash(b)[:120])
register(b, 'Vega', 'MKT210 01')
submit(b)
print('again', flash(b)[:120])

# A course of this term: the add period is over.
open_file(b, 'Rosen', 'registration')
b.click_text('Register for a course')
choose_user(b, '#fitem_id_offerid input[role=combobox]', f'{now}: ENG102')
submit(b)
print('add closed', flash(b)[:120])

# Drop during add/drop. It frees a seat in the full section, and the next student on the waitlist is offered it.
open_file(b, 'Reddy', 'registration')
reg_link(b, nextterm, 'COM150', 'Withdraw')
submit(b)
print('withdraw early', flash(b)[:120])
open_file(b, 'Reddy', 'registration')
reg_link(b, nextterm, 'COM150', 'Drop')
crop(b, 'registration-drop-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('drop', flash(b)[:120])

# This term: the drop period is over, so the student withdraws, or staff override.
open_file(b, 'Bauer', 'registration')
reg_link(b, now, 'ENG102', 'Drop')
submit(b)
print('drop closed', flash(b)[:120])
crop(b, 'registration-drop-closed', '#region-main .alert-danger', pad=6)
open_file(b, 'Bauer', 'registration')
reg_link(b, now, 'ENG102', 'Withdraw')
submit(b)
print('withdraw', flash(b)[:120])
open_file(b, 'Bauer', 'registration')
reg_link(b, now, 'BUS101', 'Drop')
tick(b, '#id_override')
b.fill('#id_note', 'Never attended. Dean agreed.')
submit(b)
print('drop override', flash(b)[:120])

# Withdrawal from the school: the last course, then the status with its two dates.
open_file(b, 'Bauer', 'registration')
reg_link(b, now, 'PSY101', 'Withdraw')
submit(b)
print('withdraw last', flash(b)[:120])
open_file(b, 'Bauer', 'registration')
clear_notices(b)
crop(b, 'registration-withdrawn-tab', '.sr-file-header', term_table(b, now), pad=4)
open_file(b, 'Bauer')
b.click_text('Edit profile')
expand_all(b)
b.select('#id_status', 'Withdrawn')
b.fill('#id_statusnote', 'Left to work full time')
submit(b)
print('no dates', b.js("return Array.from(document.querySelectorAll('.invalid-feedback')).map(e=>e.innerText.trim()).filter(x=>x)"))
expand_all(b)
crop(b, 'registration-withdraw-school-errors', '#fitem_id_status', '#fitem_id_lastattended', pad=6)
today = time.localtime()
set_date(b, 'withdrawdate', today.tm_year, today.tm_mon, today.tm_mday)
set_date(b, 'lastattended', today.tm_year, today.tm_mon, today.tm_mday)
submit(b)
print('withdrawn', flash(b)[:60])

# A later registration in the same section reuses the row, and the W leaves the record.
open_file(b, 'Bauer', 'registration')
b.click_text('Register for a course')
choose_user(b, '#fitem_id_offerid input[role=combobox]', f'{now}: ENG102')
tick(b, '#id_override')
b.fill('#id_note', 'Came back to the class.')
submit(b)
print('again after a withdrawal', flash(b)[:80])
open_file(b, 'Bauer', 'registration')
print(b.text(term_table(b, now))[:300])

# Who is waiting for the full section.
open_offering(b, nextterm, 'COM150')
print(b.url())
main_shot(b, 'registration-section-waitlist')

# Self-registration: a student with two classes picked and a seat held from the waitlist.
switch(b, 'zoe')
b.go(f'{SR}/myregistration.php')
crop(b, 'registration-self-choose', '#page-header h1', '#region-main .sr-col-main table tbody tr:nth-child(6)', pad=6, width=890)
press(b, 'Add COM150 01')
print('zoe', flash(b)[:120])
b.click_text('Review cost and confirm', 'a')
main_shot(b, 'registration-self-review')
b.click_text('Confirm registration', 'button')
main_shot(b, 'registration-self-done')

# A time clash, with a phone-sized screen too.
switch(b, 'kai')
b.go(f'{SR}/myregistration.php')
press(b, 'Add ENG101 01')
press(b, 'Add BUS101 02')
crop(b, 'registration-self-clash', '#region-main .sr-col-side .sr-card', pad=6)
press(b, 'Remove BUS101')
switch(b, 'kai')
phone(b)
b.go(f'{SR}/myregistration.php?q=ENG')
crop(b, 'registration-self-phone', '#page-header h1', '#region-main', pad=6)
b.go(f'{SR}/myregistration.php?step=review')
crop(b, 'registration-self-phone-review', '#page-header h1', '#region-main', pad=6)

# Join a waitlist and leave it.
switch(b, 'gina')
phone(b, False)
b.go(f'{SR}/myregistration.php')
press(b, 'Join the waitlist of COM150 01')
print('gina', flash(b)[:120])
crop(b, 'registration-self-waitlist', '#region-main .sr-col-main table thead', mark_row(b, '#region-main .sr-col-main table', 'COM150'), pad=6)
press(b, 'Leave the waitlist of COM150 01')

# The status stops an inquiry from registering online.
switch(b, 'ian')
b.go(f'{SR}/myregistration.php')
main_shot(b, 'registration-self-status')

# Mina was next on the waitlist. The message waits for cron.
tool('cron')
switch(b, 'mina')
b.go(f'{SR}/myregistration.php')
crop(b, 'registration-seat-held', '#region-main .sr-col-main table thead', mark_row(b, '#region-main .sr-col-main table', 'COM150'), pad=6)
b.go('/message/output/popup/notifications.php')
time.sleep(1.5)
print(b.text('#region-main')[:300])

# Two days pass. The daily task takes Mina off the list, and the next student gets the seat.
tool('php', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers', 'age_seat_offers.php'))
site("php admin/cli/scheduled_task.php --execute='\\local_studentrecords\\task\\waitlist_daily'")
tool('cron')
switch(b, 'mina')
b.go(f'{SR}/myregistration.php')
print('mina after two days:', b.text(mark_row(b, '#region-main .sr-col-main table', 'COM150')))
switch(b, 'kai')
b.go(f'{SR}/myregistration.php')
print('kai after two days:', b.text(mark_row(b, '#region-main .sr-col-main table', 'COM150')))
b.close()
