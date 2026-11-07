#!/usr/bin/env python3
"""Student Records: the FERPA notice, release authorizations, enrollment letters and documents, as the Registrar and as students."""
# Snapshot: seed
import os
import sys
import tempfile
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (choose_user, clear_notices, close_menu, crop, expand_all, flash, img, main_shot,  # noqa: E402
                       open_file, person, SR, submit, switch, tick, upload)
from wd import pdf_page  # noqa: E402

# A small made-up PDF, to upload as a person would.
PDF = (b'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n'
       b'3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n')

# A student reads the notice and acknowledges it.
b = person('dara')
b.go(f'{SR}/index.php')
b.click_text('My student record', 'a')
b.js('document.querySelector("details.sr-details").open = true;')
time.sleep(0.4)
crop(b, 'ferpa-student-prompt', 'details.sr-details .alert', pad=6)
b.click_text('Please read the FERPA annual notice ' + b.text('details .alert a').split()[-1], 'details a')
print(b.url())
main_shot(b, 'ferpa-notice')
b.click_text('I have read this notice', 'button, input[type=submit]')
print('ack', flash(b)[:80])
b.go(f'{SR}/ferpa.php')
crop(b, 'ferpa-acknowledged', '#region-main .alert', pad=6)

switch(b, 'rosa')
b.go(f'{SR}/students.php')
close_menu(b)

# A paper acknowledgment.
open_file(b, 'Ito', 'privacy')
b.click_text('Record paper acknowledgment')
crop(b, 'ferpa-paper-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('paper', flash(b)[:60])

# A release authorization.
open_file(b, 'Ito', 'privacy')
b.click_text('Add release authorization')
b.fill('#id_recipient', 'Mari Ito')
b.fill('#id_relationship', 'Mother')
choose_user(b, '#fitem_id_scope input[role=combobox]', 'Enrollment')
choose_user(b, '#fitem_id_scope input[role=combobox]', 'Financial')
b.fill('#id_details', 'May call about his bill and his class schedule.')
crop(b, 'ferpa-release-form', '#region-main h2', '#id_submitbutton', pad=6)
submit(b)
print('release', flash(b)[:60])
open_file(b, 'Ito', 'privacy')
crop(b, 'ferpa-privacy-tab', '#region-main .alert', '#region-main', pad=4)

# Revoke one.
open_file(b, 'Okoye', 'privacy')
b.click_text('Revoke', '#region-main table a')
print(b.text('#region-main')[:200])
crop(b, 'ferpa-revoke-confirm', '#region-main .modal-dialog, #region-main [role=alertdialog], #region-main .box', pad=6)
b.click_text('Continue', '#region-main button, #region-main input[type=submit], #region-main a')
print('revoke', flash(b)[:60])
open_file(b, 'Okoye', 'privacy')
clear_notices(b)
crop(b, 'ferpa-revoked', '.sr-tabs-wrap', '#region-main', pad=4)

# Withhold directory information.
open_file(b, 'Ito')
b.click_text('Edit profile')
expand_all(b)
crop(b, 'ferpa-withhold-field', '#id_h_privacy', pad=6)
tick(b, '#id_ferpaoptout')
submit(b)
print('withhold', flash(b)[:60])
open_file(b, 'Ito')
crop(b, 'ferpa-withhold-file', '#region-main .alert', '.sr-tabs-wrap', pad=6)

# An enrollment verification letter.
open_file(b, 'Ito', 'privacy')
b.click_text('Enrollment verification letter')
b.fill('#id_recipient', 'Riverbend Insurance Group (made up)')
main_shot(b, 'letter-form')
submit(b)
print('letter', flash(b)[:120])
crop(b, 'letter-issued', '#region-main .alert', pad=6)
open_file(b, 'Ito', 'privacy')
href = b.js('''return document.querySelector('#region-main a[href*="letter.php?letter="]').href;''')
print(href[-30:])
with tempfile.TemporaryDirectory() as folder:
    pdf = os.path.join(folder, 'letter.pdf')
    b.fetch(href, pdf)
    pdf_page(pdf, 1, img('letter-pdf'), dpi=70)

# Documents, on the staff side.
open_file(b, 'Ito', 'documents')
main_shot(b, 'documents-empty')
b.click_text('Add documents')
expand_all(b)
with tempfile.TemporaryDirectory() as folder:
    path = os.path.join(folder, 'immunization-record.pdf')
    with open(path, 'wb') as out:
        out.write(PDF)
    upload(b, '.filemanager .fp-btn-add a', path)
time.sleep(0.8)
crop(b, 'documents-upload', '#id_h_docs', '#fitem_id_notes', pad=6)
submit(b)
print('docs', flash(b)[:60])

# Remove it again, on the edit form.
open_file(b, 'Ito', 'documents')
print(b.text('#region-main ul')[:80])
b.click_text('Add documents')
expand_all(b)
b.js('''document.querySelector('.filemanager .fp-file a, .filemanager .fp-filename-icon a').click();''')
time.sleep(1.2)
b.js('''document.querySelector('.moodle-dialogue-base[aria-hidden=false] .fp-file-delete').click();''')
time.sleep(0.8)
b.js('''Array.from(document.querySelectorAll('button')).find(button => button.offsetParent !== null && button.innerText.trim() === 'Yes').click();''')
time.sleep(1.2)
submit(b)
print('removed', flash(b)[:60])
open_file(b, 'Ito', 'documents')
print(b.text('#region-main')[-120:])

open_file(b, 'Mori', 'documents')
clear_notices(b)
crop(b, 'documents-tab', '.sr-file-header', '#region-main', pad=4)

# The student's side.
switch(b, 'yuki')
b.go(f'{SR}/mydocuments.php')
main_shot(b, 'documents-student')
b.close()
