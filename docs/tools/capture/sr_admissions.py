#!/usr/bin/env python3
"""Student Records: the application checklist, decisions and Admissions reports, as the Registrar and the admissions officer."""
# Snapshot: seed
import datetime
import os
import sys
import tempfile
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (clear_notices, close_menu, crop, expand_all, flash, main_shot, notifications_shot,  # noqa: E402
                       open_file, person, set_date, SR, submit, switch, tool)


def set_doc(b, name, state, note=''):
    """Set one document on the Update checklist form."""
    b.js('''for (const set of document.querySelectorAll('#region-main form fieldset')) {
                const legend = set.querySelector('legend');
                if (legend && legend.textContent.trim().startsWith(arguments[0])) {
                    const select = set.querySelector('select[name^=state_]');
                    for (const option of select.options) {
                        if (option.text.trim() === arguments[1]) { select.value = option.value; }
                    }
                    select.dispatchEvent(new Event('change', {bubbles: true}));
                    set.querySelector('input[name^=note_]').value = arguments[2];
                }
            }''', name, state, note)
    time.sleep(0.3)


def pick(b, show, name):
    """Open a group of the Admissions list and select one applicant."""
    b.go(f'{SR}/admissions.php?show={show}')
    b.click_text(name, '#region-main table a')


def decide(b, button, note=''):
    """Type a note in the side panel and press one of its buttons."""
    if note:
        b.fill('#admnote', note)
    b.click_text(button, '#region-main .sr-col-side button')
    print(button, flash(b)[:100])


# The checklist is set up by staff who can configure. A later term, for the deferral below.
b = person('rosa')
b.go(f'{SR}/checklist.php')
close_menu(b)
b.go(f'{SR}/checklist.php')
main_shot(b, 'admissions-checklist-setup')
b.click_text('Add checklist document')
b.fill('#id_name', 'Resume')
b.fill('#id_description', 'A one-page summary of work experience.')
b.select('#id_required', 'No')
b.select('#id_levelid', 'Graduate')
crop(b, 'admissions-checklist-document', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('reqdoc', flash(b)[:100])
year = datetime.date.today().year + 1
b.go(f'{SR}/terms.php?edit=0')
b.fill('#id_name', f'Fall {year}')
set_date(b, 'startdate', year, 8, 23)
set_date(b, 'enddate', year, 12, 10)
submit(b)
print('term', flash(b)[:100])

switch(b, 'nina')
b.go(f'{SR}/index.php')
main_shot(b, 'admissions-staff-home')

# The list opens on Ready for decision.
b.go(f'{SR}/admissions.php')
close_menu(b)
b.go(f'{SR}/admissions.php')
main_shot(b, 'admissions-list')

# Track documents.
b.go(f'{SR}/admissions.php?show=missing')
main_shot(b, 'admissions-list-missing')
open_file(b, 'Whitlock', 'admissions')
b.click_text('Update checklist')
set_doc(b, 'Official transcripts from earlier schools', 'Received', 'Sent by Elm Hollow High School')
set_doc(b, 'Government photo ID', 'Waived', 'Seen in person at the front desk')
crop(b, 'admissions-update-checklist', '#region-main h2', '#id_note_3', pad=8)
submit(b)
print('checklist', flash(b)[:100])

# Decide from the list.
pick(b, 'ready', 'Lucy Tran')
crop(b, 'admissions-decide-panel', '#region-main .sr-col-side', pad=6)
decide(b, 'Admit')
pick(b, 'ready', 'Raj Malhotra')
decide(b, 'Waitlist', 'The evening class is full for this term.')
pick(b, 'missing', 'Noah Kim')
decide(b, 'Deny')
crop(b, 'admissions-deny-no-note', '#region-main .alert', pad=6)
decide(b, 'Deny', 'The immunization record never came, and classes need it.')

# The waitlist: two in the certificate program and one in the master's program.
b.go(f'{SR}/admissions.php?show=waitlist')
main_shot(b, 'admissions-list-waitlist')
pick(b, 'waitlist', 'Tara Quinn')
decide(b, 'Admit')

# A decision with conditions, on the file.
open_file(b, 'Whitlock', 'admissions')
b.click_text('Record a decision')
b.select('#id_decision', 'Admitted')
b.fill('#id_conditions', 'A GPA of at least 2.0 in the first term.')
b.fill('#id_reason', 'Strong essay, and transcripts in order.')
crop(b, 'admissions-record-decision', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('decision', flash(b)[:100])

# A deferral moves the admit term and ends the wait.
open_file(b, 'Shaw', 'admissions')
b.click_text('Record a decision')
b.select('#id_decision', 'Deferred')
b.select('#id_termid', f'Fall {year}')
b.fill('#id_reason', 'Asked to start in the fall.')
submit(b)
print('deferred', flash(b)[:100])
open_file(b, 'Shaw', 'admissions')
clear_notices(b)
crop(b, 'admissions-deferred-file', '.sr-file-header', '#region-main', pad=4)

# From Admitted to Enrolled, by hand.
open_file(b, 'Lund')
b.click_text('Edit profile')
expand_all(b)
b.select('#id_status', 'Enrolled')
b.fill('#id_statusnote', 'Registered for classes')
submit(b)
print('enrolled', flash(b)[:60])

# The reports.
b.go(f'{SR}/admissions.php?report=pipeline')
main_shot(b, 'admissions-report-pipeline')
b.go(f'{SR}/admissions.php?report=missing')
main_shot(b, 'admissions-report-missing')
b.go(f'{SR}/admissions.php?report=decisions')
b.select('#addecision', 'Denied')
b.click('#region-main form input[type=submit]')
main_shot(b, 'admissions-report-decisions')
with tempfile.TemporaryDirectory() as folder:
    path = os.path.join(folder, 'pipeline.csv')
    b.fetch(f'{SR}/admissions.php?report=pipeline&download=csv', path)
    print(open(path, encoding='utf-8-sig').read()[:400])
b.go(f'{SR}/admissions.php')
print(b.text('nav.sr-seg'))

# The messages wait for cron. Then the applicant sees them in Moodle's notifications.
tool('cron')
switch(b, 'lucy')
notifications_shot(b, 'admissions-applicant-messages')
b.close()
