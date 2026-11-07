#!/usr/bin/env python3
"""Student Records: the three ways grades come in, the freeze and grade changes, as the Registrar, a teacher and the approver."""
# Snapshot: seed
import os
import re
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (clear_notices, click_js, close_menu, course_link, crop, flash, main_shot, open_course,  # noqa: E402
                       open_file, open_offering, person, reg_link, SR, submit, switch, term_table, terms, tool)


def row_of(b, name, marker):
    """Mark the request of one student on the Grade changes page, so that a crop or a click can find it."""
    b.js('''for (const row of document.querySelectorAll('tr.sr-change-facts')) {
                if (row.innerText.includes(arguments[0])) {
                    row.id = arguments[1];
                    row.nextElementSibling.id = arguments[1] + 'detail';
                    return;
                }
            }
            throw new Error('no row');''', name, marker)


def decide(b, name, button, note):
    """Write a note on one request and press Approve or Reject."""
    row_of(b, name, 'one')
    b.fill('#onedetail input[name=note]', note)
    submit(b, f'#onedetail button[value={button}]')
    print(button, flash(b)[:120])


b = person('rosa')
names = terms(b)
now, past = names[-2], names[-3]
print(now, past)
b.go(f'{SR}/students.php')
close_menu(b)

# By hand.
open_file(b, 'Vega', 'registration')
reg_link(b, now, 'PSY101', 'Enter final grade')
b.select('#id_grade', 'B+')
b.fill('#id_gradepct', '88')
crop(b, 'final-grades-enter', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('entered', flash(b)[:60])
open_file(b, 'Vega', 'registration')
clear_notices(b)
crop(b, 'final-grades-completed-row', term_table(b, now), pad=4)

# Correcting it before the freeze: the same link again.
reg_link(b, now, 'PSY101', 'Enter final grade')
b.select('#id_grade', 'A-')
b.fill('#id_gradepct', '91')
submit(b)
print('corrected', flash(b)[:60])

# From the gradebook, for a whole section.
open_offering(b, now, 'ACC201')
b.click_text('Get final grades from Moodle', 'button, input[type=submit]')
print('moodle', flash(b)[:140])

# The freeze: a past term has no Enter final grade link, and the form refuses.
sid = open_file(b, 'Okoye', 'registration')
href = b.js('''const link = document.querySelector('#region-main a[href*="gradechange.php?reg="]');
               return link.href;''')
reg = re.search(r'reg=(\d+)', href).group(1)
b.go(f'{SR}/register.php?id={sid}&action=grade&reg={reg}')
submit(b)
print('frozen', flash(b)[:120])
crop(b, 'final-grades-frozen', '#region-main .alert-danger', pad=6)
open_offering(b, past, 'COM150')
b.click_text('Get final grades from Moodle', 'button, input[type=submit]')
print('frozen pull', flash(b)[:120])

# A grade change after the freeze.
open_file(b, 'Okoye', 'registration')
reg_link(b, past, 'CIS120', 'Request grade change')
b.select('#id_newgrade', 'B+')
b.fill('#id_reason', 'The lab practical was left out of the total.')
crop(b, 'final-grades-request-change', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('requested', flash(b)[:120])

# A request that goes stale: a staff correction lands first.
open_file(b, 'Vega', 'registration')
reg_link(b, now, 'PSY101', 'Request grade change')
b.select('#id_newgrade', 'B')
b.fill('#id_reason', 'The midterm was marked twice.')
submit(b)
print('requested 2', flash(b)[:120])
open_file(b, 'Vega', 'registration')
reg_link(b, now, 'PSY101', 'Enter final grade')
b.select('#id_grade', 'A')
b.fill('#id_gradepct', '95')
submit(b)
print('corrected again', flash(b)[:60])

# What the person who asked sees.
b.go(f'{SR}/gradechange.php')
main_shot(b, 'final-grades-changes-waiting')

# A teacher posts a grade. With Student Records as the source, it lands on the registration.
switch(b, 'irene')
open_course(b, 'ENG102 - English Composition II')
course_link(b, 'Post final grades')
print(b.text('#region-main')[:800])
crop(b, 'final-grades-teacher-post', '#region-main h2', '#region-main form table tbody tr:nth-child(4)', pad=6, width=880)
b.js('''const box = Array.from(document.querySelectorAll('#region-main form input[type=checkbox]')).find(i => i.name.includes('post') || i.name.includes('user'));
        box.click();''')
b.js('window.confirm = () => true;')
click_js(b, '#region-main form input[type=submit]')
time.sleep(0.8)
b.settle()
print('posted', flash(b)[:120])

# The second person. This role holds only the approve capability.
switch(b, 'omar')
print('omar lands', b.url())
b.go(f'{SR}/gradechange.php')
close_menu(b)
b.go(f'{SR}/gradechange.php')
print([a.strip() for a in b.js('''return Array.from(document.querySelectorAll('.drawer a')).map(a => a.innerText);''') if a.strip()])
main_shot(b, 'final-grades-approver')
decide(b, 'Okoye', 'approve', 'Checked with the lab sheet.')
decide(b, 'Vega', 'approve', 'Agreed.')
crop(b, 'final-grades-stale', '#region-main .alert-danger', pad=6)
decide(b, 'Vega', 'reject', 'Grade changed since the request.')
decide(b, 'Bauer', 'reject', 'The rubric was the right one.')

# The list of decisions, and a staff member's own grade.
tool('cron')
switch(b, 'rosa')
b.go(f'{SR}/gradechange.php?state=all')
close_menu(b)
b.go(f'{SR}/gradechange.php?state=all')
main_shot(b, 'final-grades-changes-all')
open_offering(b, now, 'ENG102')
main_shot(b, 'final-grades-roster')

# The student sees the grade, and gets a message.
switch(b, 'carmen')
b.go('/message/output/popup/notifications.php')
time.sleep(1.5)
print(b.text('#region-main')[:300])
b.close()
