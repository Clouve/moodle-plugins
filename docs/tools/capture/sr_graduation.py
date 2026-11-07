#!/usr/bin/env python3
"""Student Records: the degree audit, transfer credit, substitutions and graduation, as the Registrar and as a student."""
# Snapshot: seed
import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (choose_user, clear_notices, close_menu, crop, file_id, flash, main_shot, open_file,  # noqa: E402
                       person, set_date, SR, submit, switch, terms, tool)


def app_link(b, name, label):
    """Follow a link in one row of the Graduation list."""
    href = b.js('''for (const row of document.querySelectorAll('#region-main table tbody tr')) {
                       if (!row.innerText.includes(arguments[0])) { continue; }
                       for (const link of row.querySelectorAll('a')) {
                           if (link.innerText.trim() === arguments[1]) { return link.href; }
                       }
                   }
                   return null;''', name, label)
    if not href:
        raise RuntimeError(f'no {label!r} for {name} at {b.url()}')
    b.go(href)


def apply_for(b, last, term, note):
    """Apply for graduation on the student's Graduation tab."""
    open_file(b, last, 'graduation')
    b.click_text('Apply for graduation')
    b.select('#id_termid', term)
    b.fill('#id_note', note)


b = person('rosa')
names = terms(b)
now = names[-2]
b.go(f'{SR}/students.php')
close_menu(b)

# The audit of a student who has met everything.
gina = file_id(b, 'Marsh')
b.go(f'{SR}/degreeaudit.php?id={gina}')
main_shot(b, 'graduation-audit-complete')
b.js('document.querySelector("details.sr-details").open = true;')
time.sleep(0.4)
crop(b, 'graduation-audit-courses', 'details.sr-details', pad=6)

# Apply, as staff.
apply_for(b, 'Marsh', now, 'All requirements done')
crop(b, 'graduation-apply-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('apply', flash(b)[:80])

# The list.
b.go(f'{SR}/graduation.php')
main_shot(b, 'graduation-list')

# Approve on the audit, with the name on the diploma.
b.go(f'{SR}/degreeaudit.php?id={gina}')
crop(b, 'graduation-approve-panel', '.sr-col-side', pad=6)
b.click_text('Approve graduation', 'button')
print('approve', flash(b)[:80])

# Confer.
b.go(f'{SR}/graduation.php?state=approved')
main_shot(b, 'graduation-list-approved')
app_link(b, 'Gina Marsh', 'Confer')
crop(b, 'graduation-confer-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('confer', flash(b)[:80])
open_file(b, 'Marsh', 'graduation')
clear_notices(b)
crop(b, 'graduation-conferred', '.sr-file-header', '#region-main', pad=4)

# A conditional approval can't be conferred until the audit is complete.
b.go(f'{SR}/graduation.php?state=approved')
app_link(b, 'Priya', 'Confer')
submit(b)
print('conditional', flash(b)[:140])
crop(b, 'graduation-conditional-error', '#region-main .alert-danger', pad=6)

# Review on the Graduation page: this audit is still open.
b.go(f'{SR}/graduation.php')
app_link(b, 'Marco Ruiz', 'Review')
crop(b, 'graduation-review-form', '#region-main h2', '#id_submitbutton', pad=6)
print(b.text('#region-main')[:300])
b.select('#id_decision', 'Approve')
submit(b)
print('review', b.url(), flash(b)[:140])
b.go(f'{SR}/degreeaudit.php?id=' + file_id(b, 'Ruiz'))
main_shot(b, 'graduation-audit-conditional')

# Reject on the Graduation page needs no note.
b.go(f'{SR}/graduation.php')
app_link(b, 'Kira Olsen', 'Review')
b.select('#id_decision', 'Reject')
submit(b)
print('rejected', flash(b)[:80])

# An audit that isn't complete: approving needs a note, and sending back doesn't.
sean = file_id(b, 'Doyle')
apply_for(b, 'Doyle', now, 'Asked to graduate this term')
submit(b)
b.go(f'{SR}/degreeaudit.php?id={sean}')
main_shot(b, 'graduation-audit-open')
b.click_text('Approve graduation', 'button')
print('no note', flash(b)[:120])
b.fill('#danote', 'Needs more credits and a higher GPA.')
b.click_text('Send back with a note', 'button')
print('sent back', flash(b)[:120])
b.go(f'{SR}/graduation.php?state=denied')
main_shot(b, 'graduation-list-denied')

# Cancel.
apply_for(b, 'Reddy', now, 'Asked by mistake')
submit(b)
b.go(f'{SR}/graduation.php')
app_link(b, 'Uma Reddy', 'Cancel application')
b.fill('#id_note', 'The student has a year to go.')
crop(b, 'graduation-cancel-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('cancel', flash(b)[:120])

# Transfer credit.
open_file(b, 'Ito', 'registration')
b.click_text('Add transfer credit')
b.fill('#id_institution', 'Lakeshore Community College (made up)')
b.fill('#id_extcourse', 'ENGL 1020 Composition II')
b.fill('#id_credits', '3')
b.fill('#id_grade', 'B')
choose_user(b, '#fitem_id_equivcourseid input[role=combobox]', 'ENG102')
today = time.localtime()
set_date(b, 'awarded', today.tm_year, today.tm_mon, today.tm_mday)
crop(b, 'graduation-transfer-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('transfer', flash(b)[:80])
open_file(b, 'Reddy', 'registration')
crop(b, 'graduation-transfer-table', '#region-main table:has(caption)', pad=6)
b.js('''for (const table of document.querySelectorAll('#region-main table')) {
            const caption = table.querySelector('caption');
            if (caption && caption.innerText.trim() === 'Transfer credit') { table.id = 'xfer'; }
        }''')
crop(b, 'graduation-transfer-table', '#xfer', pad=6)
open_file(b, 'Osei', 'registration')
print(b.text('#region-main .alert-warning') if b.has('#region-main .alert-warning') else 'no over-the-maximum warning')

# A substitution.
sam = file_id(b, 'Ito')
b.go(f'{SR}/degreeaudit.php?id={sam}')
b.select('#dareq', 'ENG101 English Composition I')
b.select('#dasub', 'CIS120 Computer Applications')
b.fill('#dasubnote', 'Agreed by the program chair.')
crop(b, 'graduation-substitution-form', '#region-main h2.sr-h2:last-of-type', '#dasubnote', pad=6)
b.click_text('Approve substitution', 'button')
print('substitution', flash(b)[:80])
crop(b, 'graduation-substitution-audit', '.sr-reqs', '.sr-col-main', pad=6)

# A staff member's own application.
switch(b, 'kira')
open_file(b, 'Olsen', 'graduation')
b.click_text('Apply for graduation')
submit(b)
print('own apply', flash(b)[:60])
b.go(f'{SR}/graduation.php')
print(b.text('#region-main table')[:300])

# The student's view.
tool('cron')
switch(b, 'priya')
sid = b.js('''return 1;''')
b.go(f'{SR}/index.php')
b.click_text('My student record', 'a')
b.click_text('See degree audit', 'a')
print(b.url())
main_shot(b, 'graduation-student-audit')
b.close()
