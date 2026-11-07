#!/usr/bin/env python3
"""Student Records: holds, Standing rules and the standing run, as the Registrar, and what a student sees of a hold."""
# Snapshot: seed
import datetime
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (clear_notices, close_menu, crop, flash, main_shot, mark_row, open_file, person,  # noqa: E402
                       set_date, SR, submit, switch, terms, tool)

b = person('rosa')
names = terms(b)
now, last = names[-2], names[-3]
b.go(f'{SR}/students.php')
close_menu(b)

# Place a hold that blocks registration.
open_file(b, 'Ruiz', 'holds')
b.click_text('Place a hold')
b.select('#id_holdtype', 'Records')
b.fill('#id_reason', 'Immunization form is out of date.')
crop(b, 'holds-place-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('hold', flash(b)[:60])

# A hold with an end date that doesn't block registration.
open_file(b, 'Ruiz', 'holds')
b.click_text('Place a hold')
b.select('#id_holdtype', 'Other')
b.fill('#id_reason', 'Meet the Dean of Students before the end of the month.')
b.select('#id_blocksreg', 'No')
end = datetime.date.today() + datetime.timedelta(days=21)
set_date(b, 'enddate', end.year, end.month, end.day)
submit(b)
print('hold 2', flash(b)[:60])
open_file(b, 'Ruiz', 'holds')
clear_notices(b)
crop(b, 'holds-tab', '.sr-file-header', '#region-main', pad=4)

# What the student sees while the hold is on.
tool('cron')
switch(b, 'marco')
b.go(f'{SR}/index.php?view=student')
crop(b, 'holds-student-home', '.sr-tiles', pad=6)
b.go(f'{SR}/myregistration.php')
crop(b, 'holds-student-registration', '#region-main .sr-col-main table thead', '#region-main .sr-col-main table tbody tr:nth-child(3)', pad=6)

# Release one.
switch(b, 'rosa')
b.go(f'{SR}/students.php')
close_menu(b)
open_file(b, 'Ruiz', 'holds')
b.js('''for (const row of document.querySelectorAll('#region-main table tbody tr')) {
            if (row.innerText.includes('Immunization')) { row.querySelector('a').click(); }
        }''')
b.settle()
b.fill('#id_releasenote', 'New form received.')
crop(b, 'holds-release-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('release', flash(b)[:60])
open_file(b, 'Ruiz', 'holds')
clear_notices(b)
crop(b, 'holds-tab-released', '.sr-file-header', '#region-main', pad=4)

# Standing rules.
b.go(f'{SR}/saprules.php')
main_shot(b, 'standing-rules')
b.click_text('Add a rule')
b.select('#id_levelid', 'Certificate')
b.fill('#id_mincompletion', '67')
b.fill('#id_maxtimeframe', '150')
crop(b, 'standing-rule-form', '#fitem_id_levelid', '#id_submitbutton', pad=6)
submit(b)
print('rule', flash(b)[:60])

# Run standing for a term that is over.
b.go(f'{SR}/standing.php')
termid = b.js('''for (const option of document.querySelectorAll('select[name=termid] option')) {
                     if (option.text.trim() === arguments[0]) { return option.value; }
                 }
                 return 0;''', last)
b.go(f'{SR}/standing.php?termid={termid}')
b.click_text('Run standing for this term', 'button, input[type=submit]')
print('run', flash(b)[:100])
crop(b, 'standing-proposed', '#page-header h1', mark_row(b, '#region-main form table', 'Sean Doyle'), pad=6)
# Change one, and leave one out.
b.js('''for (const row of document.querySelectorAll('#region-main form table tbody tr')) {
            if (row.innerText.includes('Sean Doyle')) { row.querySelector('select').value = 'probation'; }
            if (row.innerText.includes('Wesley Bell')) { row.querySelector('input[type=checkbox]').click(); }
        }''')
b.fill('#stnote', 'Reviewed by the Registrar.')
b.click_text('Confirm selected', 'button')
print('confirm', flash(b)[:100])
crop(b, 'standing-confirmed', '#page-header h1', mark_row(b, '#region-main form table', 'Sean Doyle'), pad=6)
b.click_text('Run standing for this term', 'button, input[type=submit]')
print('rerun', flash(b)[:100])

# In the running term, classes in progress aren't measured.
termid = b.js('''for (const option of document.querySelectorAll('select[name=termid] option')) {
                     if (option.text.trim() === arguments[0]) { return option.value; }
                 }
                 return 0;''', now)
b.go(f'{SR}/standing.php?termid={termid}')
b.click_text('Run standing for this term', 'button, input[type=submit]')
print('run now', flash(b)[:100])
print(b.text('#region-main table')[:500])
b.close()
