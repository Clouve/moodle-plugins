#!/usr/bin/env python3
"""Student Records: class meetings, taking attendance and what a student sees, as a teacher and as students."""
# Snapshot: seed
import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (click_js, course_link, crop, flash, img, notifications_shot, open_course, open_offering,  # noqa: E402
                       person, phone, SR, submit, switch, terms, tool)

WEEKLY = 'form:has(input[name=mode][value=weekly])'
ONE = 'form:has(input[name=mode][value=one])'

# A teacher's classes.
b = person('tomas')
b.go(f'{SR}/index.php?view=teacher')
crop(b, 'attendance-my-classes', '#page-header h1', '#region-main', pad=6)
b.go(f'{SR}/index.php?view=atrisk')
crop(b, 'attendance-at-risk', '#page-header h1', '#region-main', pad=6)
open_course(b, 'BUS101 - Introduction to Business')
crop(b, 'attendance-course-teacher', 'section.sr-sidecard', pad=6)

# A section with no meetings yet. This teacher holds the non-editing teacher role.
switch(b, 'pia')
open_course(b, 'BIO105 - Environmental Biology')
course_link(b, 'Attendance')
print(b.url(), b.text('#region-main')[:200])
b.click_text('BIO105 01, ' + b.text('#region-main a').split(', ')[-1], '#region-main a')
print(b.url())
crop(b, 'attendance-no-meetings', '#region-main h2', '#region-main', pad=6)

# A weekly schedule.
b.js('''for (const day of ['day2', 'day4']) {
            document.querySelector(`form:has(input[name=mode][value=weekly]) input[type=checkbox][name=${day}]`).click();
        }''')
b.fill(WEEKLY + ' input[name=hours]', '1.75')
crop(b, 'attendance-add-weekly', WEEKLY, pad=6)
submit(b, WEEKLY + ' input[type=submit]')
print('weekly', flash(b)[:100])

# One more meeting, with a topic.
b.fill(ONE + ' input[name=topic]', 'Field trip to the river')
b.fill(ONE + ' input[name=hours]', '3')
crop(b, 'attendance-add-meeting', ONE, pad=6)
submit(b, ONE + ' input[type=submit]')
print('one', flash(b)[:100])
crop(b, 'attendance-meetings', '#region-main h2', '#region-main table tbody tr:nth-child(8)', pad=6)

# Take attendance for the first meeting.
b.click_text('Take attendance', '#region-main table a')
print(b.url())
click_js(b, 'button[data-action=sr-allpresent]')
rows = b.js('''return Array.from(document.querySelectorAll('fieldset.sr-att-row')).map(row => row.querySelector('legend').innerText);''')
print(rows)


def mark(b, name, status, hours='', note=''):
    """Change one row of the sheet."""
    b.js('''for (const row of document.querySelectorAll('fieldset.sr-att-row')) {
                if (row.querySelector('legend').innerText.trim() !== arguments[0]) { continue; }
                row.querySelector(`input.sr-seg-input[value=${arguments[1]}]`).click();
                if (arguments[2] !== '') { row.querySelector('input.sr-att-hours').value = arguments[2]; }
                row.querySelector('.sr-att-note input').value = arguments[3];
            }''', name, status, hours, note)


mark(b, rows[1], 'late', '1.25', 'Came 30 minutes late')
mark(b, 'Vince Marlow', 'absent')
mark(b, rows[2], 'excused', '', "Doctor's note")
time.sleep(0.4)
crop(b, 'attendance-sheet', '#region-main h2', '#region-main p.small', pad=6)
submit(b, 'form.sr-att button[type=submit]')
print('saved', flash(b)[:100])
crop(b, 'attendance-meetings-marked', '#region-main h2', '#region-main table tbody tr:nth-child(4)', pad=6)

# Records staff reach the same pages from Course offerings.
switch(b, 'rosa')
names = terms(b)
open_offering(b, names[-2], 'BIO105')
b.click_text('Attendance', '#region-main a')
print('staff route', b.url(), b.text('#region-main h2'))

# The first absence takes the rate from nothing to under the minimum, so the warning goes out at the next cron run.
tool('cron')
switch(b, 'vince')
notifications_shot(b, 'attendance-low-warning')

# What a student sees. This one is under the minimum.
switch(b, 'sean')
b.go(f'{SR}/index.php?view=student')
crop(b, 'attendance-student-home', '#page-header h1', '.sr-tiles', pad=6)
open_course(b, 'BUS101 - Introduction to Business')
crop(b, 'attendance-course-student', 'section.sr-sidecard', pad=6)
switch(b, 'sean')
phone(b)
open_course(b, 'BUS101 - Introduction to Business')
b.shot(img('attendance-course-phone'), full=False)
b.close()
