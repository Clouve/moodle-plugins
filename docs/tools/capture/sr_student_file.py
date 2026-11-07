#!/usr/bin/env python3
"""Student Records: the list of student records and the student file, as the Registrar, a teacher and a staff member with a file."""
# Snapshot: seed
import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (choose_user, clear_notices, close_menu, course_link, crop, expand_all, flash, main_shot,  # noqa: E402
                       open_course, open_file, person, SR, submit, switch, tick)

b = person('rosa')

# Find a student: the list, filtered. The menu on the left is closed from here on, so that all the tabs of the file fit.
b.go(f'{SR}/students.php')
close_menu(b)
b.go(f'{SR}/students.php')
b.select('#srstatus', 'Applicant')
b.click('#region-main form input[type=submit]')
main_shot(b, 'student-file-list')

# Add a student record for a person who has a Moodle account and no file. In 1.4.6 this form can't be saved, so it is left filled in.
b.go(f'{SR}/students.php')
b.click_text('Add student record')
choose_user(b, '#fitem_id_userid input[role=combobox]', 'June')
expand_all(b)
b.fill('#id_legalfirstname', 'June')
b.fill('#id_legallastname', 'Adler')
b.fill('#id_email', 'june@example.com')
b.select('#id_programid', 'AS-BUS - Business Administration')
b.select('#id_admittermid', 'Spring 2027')
b.fill('#id_catalogyear', '2026-2027')
crop(b, 'student-file-add-form', '#region-main h2', '#fitem_id_preferredname', pad=6)

# An emergency contact.
ian = open_file(b, 'Lowell')
b.click_text('Add an emergency contact')
b.fill('#id_name', 'Frank Lowell')
b.fill('#id_relationship', 'Father')
b.fill('#id_phone', '(618) 555-0177')
crop(b, 'student-file-emergency-form', '#region-main h2', '#fitem_id_notes', pad=6)
submit(b)
print('emerg', flash(b)[:120])

# Demographics.
b.click_text('Edit demographics')
b.select('#id_sex', 'Male')
b.select('#id_hispanic', 'No')
for race, on in [('aian', False), ('asian', True), ('black', False), ('nhpi', False), ('white', True), ('notreported', False)]:
    tick(b, f'#id_race_{race}', on)
crop(b, 'student-file-demographics-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('demog', flash(b)[:120])

# Prior education, on the Admissions tab.
b.go(f'{SR}/student.php?id={ian}&tab=admissions')
b.click_text('Add prior education')
b.fill('#id_school', 'Elm Hollow Community College (made up)')
b.fill('#id_credential', 'Two terms of general studies')
b.select('#id_country', 'United States')
crop(b, 'student-file-education-form', '#region-main h2', '#fitem_id_notes', pad=6)
submit(b)
print('educ', flash(b)[:120])

# The Profile tab of a student with a full record.
open_file(b, 'Vega')
clear_notices(b)
main_shot(b, 'student-file-profile')

# The header alone, with the release check over it.
open_file(b, 'Farah')
crop(b, 'student-file-header', '#region-main .alert', '.sr-tabs-wrap', pad=6)

# A status change with a note, on the edit form.
open_file(b, 'Abara')
b.click_text('Edit profile')
expand_all(b)
b.select('#id_status', 'Active')
b.fill('#id_statusnote', 'Back from leave for the spring term')
crop(b, 'student-file-edit-status', '#fitem_id_programid', '#fitem_id_awarddate', pad=6)
submit(b)
print('status', flash(b)[:120])
sid = open_file(b, 'Abara', 'history')
clear_notices(b)
crop(b, 'student-file-history', '.sr-file-header', '#region-main table tbody tr:nth-child(6)', pad=4)
b.go(f'{SR}/student.php?id={sid}&tab=admissions')
print(b.text('#region-main')[:200])

# The other tabs, each with the student that shows it best.
for name, tab in [('Okoye', 'registration'), ('Doyle', 'attendance'), ('Stone', 'holds'), ('Nair', 'graduation'), ('Okoye', 'privacy'),
                  ('Mori', 'documents'), ('Park', 'admissions')]:
    open_file(b, name, tab)
    clear_notices(b)
    crop(b, f'student-file-tab-{tab}', '.sr-file-header', '#region-main', pad=4)

# View as student.
open_file(b, 'Vega')
b.click_text('View as student')
crop(b, 'student-file-view-as', '#page-header h1', '.sr-viewas .sr-programs', pad=6)

# A teacher opens a student's file from a course. This student withheld directory information.
switch(b, 'tomas')
open_course(b, 'MKT210 - Principles of Marketing')
course_link(b, 'Student records')
crop(b, 'student-file-teacher-list', '#page-header', '#region-main', pad=0)
b.click_text('Nadia Farah', 'a')
crop(b, 'student-file-teacher-view', '#region-main h2', '#region-main table', pad=6)

# A staff member who is also a student.
switch(b, 'kira')
open_file(b, 'Olsen')
print('kira file', b.url(), [t.strip() for t in b.text('.sr-tabs-wrap').split('\n')])
b.click_text('Edit profile')
expand_all(b)
crop(b, 'student-file-own-record', '#fitem_id_ownnote', '#fitem_id_awarddate', pad=6)
b.close()
