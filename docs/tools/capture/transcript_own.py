#!/usr/bin/env python3
"""Academic Transcript on its own data: every role, from the student to the person who checks a code."""
# Snapshot: seed
import os
import re
import subprocess
import sys
import tempfile
import time
import urllib.parse

sys.dont_write_bytecode = True
TOOLS = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, TOOLS)
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from transcript_setup import (ADMINPASS, AT, click_js, confirm_click, crop, img, main_shot, press,  # noqa: E402
                              press_text, upload)
from wd import WD, pdf_page  # noqa: E402

SETTINGS = '/admin/settings.php?section=local_academictranscript'
ENG102 = 'ENG102 - English Composition II'
CIS120 = 'CIS120 - Computer Applications'
LAKESHORE = 'Admissions, Lakeshore State University (made up)'
RIVERBEND = 'Tuition Benefits, Riverbend Insurance Group (made up)'


def switch(b, user, password=None, width=1280, height=900, mobile=False):
    """Sign out, and sign in as someone else in the same browser. The test site has few browser slots."""
    b.logout()
    b.width, b.height, b.mobile = width, height, mobile
    b.metrics(height)
    b.login(user, password)


def course_id(b, fullname):
    """Find a course by its full name, with Moodle's course search. The running term's course has no term in its name."""
    b.go('/course/search.php?' + urllib.parse.urlencode({'search': fullname}))
    href = b.js('''
        for (const link of document.querySelectorAll('a[href*="course/view.php"]')) {
            if (link.innerText.trim() === arguments[0]) {
                return link.href;
            }
        }
        return null;''', fullname)
    return re.search(r'id=(\d+)', href).group(1)


def request(b, delivery, recipient, email='', address=''):
    """Fill in the request form on My transcript."""
    click_js(b, f'label[for=atdel_{delivery}]')
    time.sleep(0.2)
    b.fill('#at_recipient', recipient)
    if email:
        b.fill('#at_email', email)
    if address:
        b.fill('#at_address', address)


def send(b):
    press(b, 'form.at-request button[type=submit]')


def mark_row(b, name, rowid):
    """Give the table row of one person an id, to find things in it."""
    return b.js('''
        document.getElementById(arguments[1])?.removeAttribute('id');
        for (const row of document.querySelectorAll('#region-main tbody tr')) {
            if (row.cells[0].innerText.trim() === arguments[0]) {
                row.id = arguments[1];
                return true;
            }
        }
        return false;''', name, rowid)


def row_link(b, name, text):
    """Open a link in the table row of one person."""
    href = b.js('''
        for (const row of document.querySelectorAll('#region-main tbody tr')) {
            if (row.cells[0].innerText.trim() === arguments[0]) {
                for (const link of row.querySelectorAll('a')) {
                    if (link.innerText.trim() === arguments[1]) {
                        return link.href;
                    }
                }
            }
        }
        return null;''', name, text)
    b.go(href)


def set_code(b, name, code, note='', reason=''):
    """Fill in one row of Transcript codes."""
    mark_row(b, name, 'coderow')
    b.select('#coderow select', code)
    if note:
        b.fill('#coderow input[name^=note]', note)
    if reason:
        b.fill('#coderow input[name^=reason]', reason)


def tick(b, name):
    mark_row(b, name, 'postrow')
    b.js("document.querySelector('#postrow input[type=checkbox]').checked = true;")


def pdf_crop(pdf, page, name, x, y, width, height, dpi=120):
    """Save a part of a page of a real PDF from the site."""
    subprocess.run(['pdftoppm', '-r', str(dpi), '-png', '-singlefile', '-f', str(page), '-l', str(page), '-x', str(x), '-y', str(y),
                    '-W', str(width), '-H', str(height), pdf, img(name)[:-4]], check=True)


def student(b, folder):
    """Students: My transcript, the Unofficial PDF, and a request of each kind."""
    switch(b, 'hugo')
    b.go('/user/profile.php')
    press_text(b, 'Academic transcript')
    main_shot(b, 'my-transcript')
    b.fetch(b.js("return document.querySelector('a[href*=\"action=unofficial\"]').href;"), os.path.join(folder, 'unofficial.pdf'))
    request(b, 'email', LAKESHORE, 'admissions@lakeshore.example.com')
    crop(b, 'request-form-email', 'form.at-request')
    send(b)
    print(b.text('#user-notifications')[:70])
    crop(b, 'request-sent', '#user-notifications .alert', '#region-main .sr-col-side table')
    # The same request again, within a minute.
    request(b, 'email', LAKESHORE, 'admissions@lakeshore.example.com')
    send(b)
    print(b.text('#user-notifications')[:150])

    switch(b, 'lena')
    b.go(AT + '/index.php')
    request(b, 'mail', RIVERBEND)
    crop(b, 'request-form-mail', 'form.at-request')
    send(b)
    print(b.text('#user-notifications')[:60])
    request(b, 'mail', RIVERBEND, address='400 Main Street\nFairmount, IL 62910')
    send(b)

    switch(b, 'arlo')
    b.go(AT + '/index.php')
    request(b, 'pickup', 'Arlo Tate')
    crop(b, 'request-form-pickup', 'form.at-request')
    send(b)

    switch(b, 'ruth', width=390, height=844, mobile=True)
    b.go(AT + '/index.php')
    b.shot(img('my-transcript-phone'), limit=2000)
    return re.search(r'userid=(\d+)', b.js("return document.querySelector('a[href*=\"action=unofficial\"]').href;")).group(1)


def teacher(b):
    """Teachers: Transcript codes, Post final grades, Request change, and issuing from a student's page."""
    switch(b, 'irene', width=1600)
    course = course_id(b, ENG102)
    b.go(f'/course/view.php?id={course}')
    click_js(b, '[data-toggler=drawers][data-action=closedrawer][data-target=theme_boost-drawers-courseindex]')
    time.sleep(0.8)
    click_js(b, '.secondary-navigation .moremenu .dropdownmoremenu > a, .secondary-navigation .moremenu [role=button]')
    time.sleep(0.6)
    crop(b, 'course-more-menu', '.secondary-navigation', '.secondary-navigation .dropdown-menu.show', tall=False)

    b.go(f'{AT}/codes.php?courseid={course}')
    set_code(b, 'Sean Doyle', 'W - Withdrawn')
    press(b, '#region-main form input[type=submit]')
    print(b.text('#region-main .alert-danger')[:100])
    crop(b, 'transcript-codes-reason-error', '#region-main .alert-danger')
    set_code(b, 'Sean Doyle', 'W - Withdrawn', reason='Left the course in week 9')
    set_code(b, 'Hugo Lindqvist', 'P - Pass', note='Completed by portfolio', reason='Agreed with the Registrar')
    crop(b, 'transcript-codes', '#region-main h2', '#region-main form input[type=submit]')
    press(b, '#region-main form input[type=submit]')
    print(b.text('#user-notifications')[:60])

    b.go(f'{AT}/post.php?courseid={course}')
    tick(b, 'Theodore Bauer')
    tick(b, 'Uma Reddy')
    tick(b, 'Lena Ortiz')
    tick(b, 'Hugo Lindqvist')
    tick(b, 'Sean Doyle')
    crop(b, 'post-final-grades-list', '#region-main h2', '#region-main form input[type=submit]', pad=4)
    print(confirm_click(b, '#region-main form input[type=submit]'))
    print(b.text('#user-notifications')[:60])
    b.js("const tables = document.querySelectorAll('#region-main table'); tables[tables.length - 1].id = 'postedtable';")
    crop(b, 'post-final-grades-posted', '#region-main h2', '#postedtable')

    row_link(b, 'Theodore Bauer', 'Request change')
    b.select('#id_newgrade', 'B+ (87-89%)')
    b.fill('#id_reason', 'Final paper was graded after posting.')
    crop(b, 'request-change-form', '#region-main h2', '#region-main form.mform')
    press(b, '#id_submitbutton')
    print(b.text('#user-notifications')[:60])
    row_link(b, 'Theodore Bauer', 'Request change')
    crop(b, 'request-change-pending', '#region-main h2', '#region-main table')

    b.go(f'{AT}/students.php?courseid={course}')
    crop(b, 'students-transcripts-course', '#region-main h2', '#region-main table tbody tr:nth-child(6)')
    row_link(b, 'Hugo Lindqvist', 'View transcript')
    b.fill('#recipient', 'Bluebird Accounting Services (made up)')
    crop(b, 'issue-form', '#region-main h4', 'form[onsubmit]')
    print(confirm_click(b, 'form[onsubmit] input[type=submit]'))
    print(b.text('#user-notifications')[:150])
    crop(b, 'issued-from-student-page', '#user-notifications .alert', '#region-main .alert-success:has(a[href*="issued.php"])', width=1100)
    return course


def registrar(b):
    """The Registrar: the queue, Cancel and Issue, then the pages of the record."""
    switch(b, 'rosa')
    b.go(AT + '/requests.php')
    main_shot(b, 'queue-pending')
    mark_row(b, 'Arlo Tate', 'cancelrow')
    b.js("document.querySelector('#cancelrow details').open = true;")
    b.fill('#cancelrow input[name=note]', 'The student will collect it next term')
    crop(b, 'queue-cancel-open', '#cancelrow')
    press(b, '#cancelrow details button[type=submit]')
    print(b.text('#user-notifications')[:40])

    # The queue is whole only until the first Issue: see the Known problem line in the registrar guide.
    mark_row(b, 'Lena Ortiz', 'issuerow')
    press(b, '#issuerow form button.btn-primary')
    print(b.text('#user-notifications')[:190])
    mark_row(b, 'Hugo Lindqvist', 'issuerow2')
    press(b, '#issuerow2 form button.btn-primary')
    print(b.text('#user-notifications')[:190])
    text = b.text('#region-main')
    print('The page ends with:', text[-120:].replace('\n', ' | '))
    b.width = 1600
    b.metrics(b.height)
    b.go(AT + '/issued.php')
    crop(b, 'issued-list', '#region-main h2', '#region-main table')

    switch(b, 'hugo')
    b.go(AT + '/index.php')
    crop(b, 'my-requests-issued', '#region-main .sr-col-side h2.sr-h2', '#region-main .sr-col-side table')
    switch(b, 'arlo')
    b.go(AT + '/index.php')
    crop(b, 'my-requests-cancelled', '#region-main .sr-col-side h2.sr-h2', '#region-main .sr-col-side table')


def approvals(b, course):
    """Grade change approvals: a person can't decide what they asked for."""
    switch(b, 'mona', width=1600)
    b.go(SETTINGS)
    print('A manager on Transcript settings:', b.text('#region-main')[:70].replace('\n', ' | '))
    b.go(f'{AT}/post.php?courseid={course}')
    row_link(b, 'Lena Ortiz', 'Request change')
    b.select('#id_newgrade', 'B (83-86%)')
    b.fill('#id_reason', 'The final paper is in.')
    press(b, '#id_submitbutton')
    b.go(AT + '/approvals.php')
    mark_row(b, 'Lena Ortiz', 'ownrow')
    crop(b, 'approvals-own-request', '#ownrow')

    switch(b, 'rosa', width=1600)
    b.go(AT + '/approvals.php')
    crop(b, 'approvals-pending', '#region-main h2', '#region-main table')
    mark_row(b, 'Hugo Lindqvist', 'one')
    b.fill('#one input[name=decisionnote]', 'Checked with the teacher')
    press(b, '#one button[value=approve]')
    print(b.text('#user-notifications')[:70])
    mark_row(b, 'Theodore Bauer', 'two')
    b.fill('#two input[name=decisionnote]', 'The paper came in after the deadline')
    press(b, '#two button[value=reject]')
    print(b.text('#user-notifications')[:70])
    mark_row(b, 'Lena Ortiz', 'three')
    press(b, '#three button[value=approve]')
    crop(b, 'approvals-decided', '#region-main h4', '#region-main table')


def records(b, ruth):
    """Student details, transfer credit, course terms, and an Official transcript for the PDF pages."""
    switch(b, 'rosa')
    b.go(AT + '/students.php')
    crop(b, 'students-transcripts-all', '#region-main h2', '#region-main tbody tr:nth-child(8)')
    row_link(b, 'Lena Ortiz', 'View transcript')
    crop(b, 'transcript-staff-view', '#region-main h2', '#region-main table')
    crop(b, 'transcript-staff-buttons', '#region-main .d-flex.flex-wrap.gap-2', 'form[onsubmit]')
    press_text(b, 'Edit student details')
    b.select('#id_status', 'On Leave')
    main_shot(b, 'student-details-form')
    press(b, '#id_submitbutton')
    print(b.text('#user-notifications')[:60])
    press_text(b, 'Transfer credit')
    press_text(b, 'Add transfer credit')
    b.fill('#id_institution', 'Lakeshore State University (made up)')
    b.fill('#id_coursecode', 'MAT 105')
    b.fill('#id_title', 'College Algebra')
    b.fill('#id_hours', '3')
    click_js(b, '#id_dateaccepted_enabled')
    b.fill('#id_note', 'Accepted as MAT110')
    main_shot(b, 'transfer-add-form')
    press(b, '#id_submitbutton')
    main_shot(b, 'transfer-list')

    # Ruth has no student role in a course, so she isn't on Students' transcripts. Her profile has the link.
    b.go(f'/user/profile.php?id={ruth}')
    press_text(b, 'Academic transcript')
    b.fill('#recipient', LAKESHORE)
    confirm_click(b, 'form[onsubmit] input[type=submit]')
    print(b.text('#user-notifications')[:150])
    b.js("""for (const heading of document.querySelectorAll('#region-main h4')) {
                if (heading.innerText === 'Official transcripts issued') {
                    heading.id = 'issuedheading';
                    heading.parentElement.querySelectorAll('table').forEach(t => {
                        if (t.compareDocumentPosition(heading) & Node.DOCUMENT_POSITION_PRECEDING && !document.getElementById('issuedtable')) {
                            t.id = 'issuedtable';
                        }
                    });
                }
            }""")
    crop(b, 'official-issued-table', '#issuedheading', '#issuedtable', pad=3)
    href = b.js("return document.querySelector('.alert-success a[href*=\"issued.php\"]').href;")
    code = re.search(r'Verification code: ([A-Z0-9-]+)', b.text('#user-notifications')).group(1)
    return href, code


def upkeep(b, course):
    """Edit what exists: rename a term, edit a program and a catalog course, hide a mark, hide a transfer row."""
    switch(b, 'rosa')
    b.go(AT + '/terms.php')
    edit = b.js("return document.querySelector('#region-main tbody tr a').href;")
    b.go(edit)
    name = b.js("return document.querySelector('#id_name').value;")
    b.fill('#id_name', name + ' term')
    press(b, '#id_submitbutton')
    print(b.text('#user-notifications')[:30], '|', b.text('#region-main tbody tr')[:60])
    switch(b, 'mona')
    b.go(f'/course/edit.php?id={course}')
    print(b.js("return Array.from(document.querySelectorAll('#id_customfield_transcript_term option')).map(o => o.text);"))
    switch(b, 'rosa')
    b.go(edit)
    b.fill('#id_name', name)
    press(b, '#id_submitbutton')

    b.go(AT + '/programs.php')
    row_link(b, 'Organizational Leadership', 'Edit')
    press(b, '#id_submitbutton')
    print(b.text('#user-notifications')[:30])
    b.go(AT + '/catalog.php')
    row_link(b, 'ACC201', 'Edit')
    press(b, '#id_submitbutton')
    print(b.text('#user-notifications')[:30])

    b.go(AT + '/marks.php')
    mark_row(b, 'NC', 'ncrow')
    b.js("document.querySelector('#ncrow input[name$=\"[active]\"]').checked = false;")
    press(b, '#region-main input[type=submit]')
    print(b.text('#user-notifications')[:30])
    b.go(f'{AT}/codes.php?courseid={course}')
    print(b.url())
    b.go(AT + '/marks.php')
    mark_row(b, 'NC', 'ncrow')
    b.js("document.querySelector('#ncrow input[name$=\"[active]\"]').checked = true;")
    press(b, '#region-main input[type=submit]')

    b.go(AT + '/students.php')
    row_link(b, 'Lena Ortiz', 'View transcript')
    press_text(b, 'Transfer credit')
    row_link(b, 'Prairie Community College (made up)', 'Edit')
    b.select('#id_active', 'No')
    press(b, '#id_submitbutton')
    print(b.text('#region-main table')[:200].replace('\n', ' | '))


def repeats(b, course):
    """A course repeated in the same Moodle course: the Registrar moves the student to the next term, and the teacher posts."""
    switch(b, 'rosa')
    b.go(AT + '/students.php')
    row_link(b, 'Uma Reddy', 'View transcript')
    press_text(b, 'Course terms')
    mark_row(b, ENG102, 'termrow')
    b.select('#termrow select', 'Spring 2027')
    main_shot(b, 'course-terms')
    press(b, '#region-main form input[type=submit]')
    print(b.text('#user-notifications')[:40])
    switch(b, 'irene', width=1600)
    b.go(f'{AT}/post.php?courseid={course}')
    tick(b, 'Uma Reddy')
    confirm_click(b, '#region-main form input[type=submit]')
    print(b.text('#user-notifications')[:40])
    b.go(f'{AT}/students.php?courseid={course}')
    row_link(b, 'Uma Reddy', 'View transcript')
    b.js("""const heads = Array.from(document.querySelectorAll('#region-main h5'));
            const fall = heads.filter(h => h.innerText.startsWith('Fall 2026'))[0];
            const spring = heads.filter(h => h.innerText.startsWith('Spring 2027'))[0];
            fall.id = 'firstattempt';
            spring.nextElementSibling.nextElementSibling.id = 'secondattempt';""")
    crop(b, 'repeat-on-transcript', '#firstattempt', '#secondattempt', width=900)


def people_own_record(b, course_name=CIS120):
    """Kira Olsen is staff and a student."""
    switch(b, 'kira')
    b.go(AT + '/index.php')
    crop(b, 'staff-own-view', '#region-main h2', '#region-main .nav-tabs')
    print(b.text('#region-main')[:200].replace('\n', ' | '))
    print(b.js("return Array.from(document.querySelectorAll('#region-main h4')).map(e=>e.innerText+' => '+(e.nextElementSibling?.innerText||'').slice(0,90))"))
    b.go(AT + '/requests.php')
    mark_row(b, 'Kira Olsen', 'ownrow')
    crop(b, 'queue-own-row', '#ownrow')
    course = course_id(b, course_name)
    b.go(f'{AT}/post.php?courseid={course}')
    mark_row(b, 'Kira Olsen', 'ownpost')
    print(b.text('#ownpost')[:120].replace('\n', ' | '))


def verifier(b, code):
    """Anyone with a code. Nobody is signed in."""
    b.logout()
    b.width, b.height, b.mobile = 1280, 900, False
    b.metrics(900)
    b.go(AT + '/verify.php')
    main_shot(b, 'verify-page')
    b.fill('input[name=code]', code.lower().replace('-', ' '))
    press(b, '#region-main form button[type=submit]')
    main_shot(b, 'verify-valid')
    b.fill('input[name=code]', 'ABCD-EFGH-JKLM-NPQR-STUV-WXYZ')
    press(b, '#region-main form button[type=submit]')
    main_shot(b, 'verify-not-found')


def logs(b):
    """The Activity log and the Course codes check, with the entries of the walk above."""
    switch(b, 'rosa')
    b.go(AT + '/log.php')
    crop(b, 'activity-log', '#region-main h2', '#region-main tbody tr:nth-child(10)')
    b.go('/admin/category.php?category=local_academictranscript_category')
    main_shot(b, 'admin-menu')


def pdfs(b, folder, official):
    """The real PDF files, part by part, and what a change of settings does to a copy already issued."""
    switch(b, 'rosa')
    pdf = os.path.join(folder, 'official.pdf')
    b.go(AT + '/issued.php')
    b.fetch(official, pdf)
    pdf_page(pdf, 1, img('pdf-official-page1'), dpi=100)
    pdf_page(pdf, 2, img('pdf-official-key'), dpi=100)
    pdf_page(os.path.join(folder, 'unofficial.pdf'), 1, img('pdf-unofficial-page1'), dpi=100)
    pdf_crop(pdf, 1, 'pdf-header', 54, 54, 912, 112)
    pdf_crop(pdf, 1, 'pdf-student-box', 54, 168, 912, 108)
    pdf_crop(pdf, 1, 'pdf-record', 54, 280, 912, 236)
    pdf_crop(pdf, 1, 'pdf-summary', 54, 972, 912, 90)
    pdf_crop(pdf, 1, 'pdf-signature-images', 54, 1066, 912, 160)
    pdf_crop(pdf, 1, 'pdf-footer', 54, 1224, 912, 46)
    print(subprocess.run(['pdfsig', pdf], capture_output=True, text=True).stdout)

    # Blank lines, and A4. Both reach a copy that was issued earlier.
    switch(b, 'admin', ADMINPASS)
    b.go(SETTINGS)
    b.select('#id_s_local_academictranscript_signaturemode', 'Blank lines for hand signature and raised seal')
    b.select('#id_s_local_academictranscript_pagesize', 'A4')
    press(b, '#adminsettings button[type=submit]')
    lines = os.path.join(folder, 'lines.pdf')
    b.fetch(official, lines)
    print(subprocess.run(['pdfinfo', lines], capture_output=True, text=True).stdout.split('Page size:')[1][:30])
    b.go(SETTINGS)
    b.select('#id_s_local_academictranscript_pagesize', 'US Letter')
    press(b, '#adminsettings button[type=submit]')
    b.fetch(official, lines)
    pdf_crop(lines, 1, 'pdf-signature-lines', 54, 1066, 912, 160)

    # A made-up certificate: first with the wrong password, then the right one.
    p12 = make_certificate(folder)
    b.go(SETTINGS)
    upload(b, '#admin-signingcert .fp-btn-add a', p12)
    set_password(b, 'not the password')
    press(b, '#adminsettings button[type=submit]')
    print(b.text('#user-notifications')[:20])
    signed = os.path.join(folder, 'signed.pdf')
    b.fetch(official, signed)
    print('wrong password:', subprocess.run(['pdfsig', signed], capture_output=True, text=True).stdout[:320])
    b.go(SETTINGS)
    set_password(b, 'Made-up-1')
    press(b, '#adminsettings button[type=submit]')
    b.fetch(official, signed)
    print('right password:', subprocess.run(['pdfsig', signed], capture_output=True, text=True).stdout[:320])


def make_certificate(folder):
    """A self-signed certificate, made up for this walk, in a .p12 file."""
    key = os.path.join(folder, 'key.pem')
    cert = os.path.join(folder, 'cert.pem')
    p12 = os.path.join(folder, 'signing.p12')
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '30', '-keyout', key, '-out', cert,
                    '-subj', '/CN=Riverside College Registrar (made up)/O=Riverside College'], check=True, capture_output=True)
    subprocess.run(['openssl', 'pkcs12', '-export', '-inkey', key, '-in', cert, '-out', p12, '-passout', 'pass:Made-up-1'], check=True)
    return p12


def set_password(b, text):
    """The password box of the settings page opens with a click."""
    click_js(b, '#admin-signingcertpassword [data-passwordunmask="edit"]')
    time.sleep(0.4)
    b.fill('#id_s_local_academictranscript_signingcertpassword', text)


def settings(b):
    """A site administrator reads the settings page and puts the transcript on this plugin's own data."""
    b.login('admin', ADMINPASS)
    b.go(SETTINGS)
    b.js("document.querySelectorAll('#adminsettings h3').forEach((h, i) => { h.id = 'section' + i; });")
    crop(b, 'settings-school', '#section0', '#admin-registrartitle')
    b.select('#id_s_local_academictranscript_source', "This plugin's own data (posted grades)")
    crop(b, 'settings-grades', '#section1', '#admin-clocklabel')
    crop(b, 'settings-fees', '#section2', '#admin-fee_pickup')
    crop(b, 'settings-look', '#section3', '#admin-footer')
    crop(b, 'settings-signature', '#section4', '#admin-signingcertpassword')
    crop(b, 'settings-key', '#section5', '#admin-key_contact', pad=2)
    press(b, '#adminsettings button[type=submit]')
    print(b.text('#user-notifications')[:20])


def main():
    folder = tempfile.mkdtemp(prefix='transcript-own-')
    print('scratch', folder)
    b = WD()
    try:
        settings(b)
        ruth = student(b, folder)
        course = teacher(b)
        registrar(b)
        approvals(b, course)
        official, code = records(b, ruth)
        upkeep(b, course)
        repeats(b, course)
        people_own_record(b)
        verifier(b, code)
        logs(b)
        pdfs(b, folder, official)
    finally:
        b.close()


if __name__ == '__main__':
    main()
