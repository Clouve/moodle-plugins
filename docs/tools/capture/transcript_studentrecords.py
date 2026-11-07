#!/usr/bin/env python3
"""Academic Transcript with Student Records as the source of the transcript."""
# Snapshot: seed
import os
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
TOOLS = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, TOOLS)
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from transcript_own import ENG102, SETTINGS, course_id, mark_row, pdf_crop, request, row_link, send, switch, tick  # noqa: E402
from transcript_setup import ADMINPASS, AT, click_js, confirm_click, crop, img, main_shot, press, press_text  # noqa: E402
from wd import WD, pdf_page  # noqa: E402


def student(b):
    """A student with a file: the timing choice, the fee on the account, and a hold."""
    switch(b, 'nadia')
    b.go('/local/studentrecords/index.php')
    print(b.url())
    press_text(b, 'My Transcript')
    print(b.url())
    request(b, 'pickup', 'Nadia Farah')
    click_js(b, '#attime_grades')
    time.sleep(0.2)
    crop(b, 'sr-request-form', 'form.at-request')
    send(b)
    print(b.text('#user-notifications')[:110])
    crop(b, 'sr-request-waiting', '#region-main .sr-col-side h2.sr-h2', '#region-main .sr-col-side table')
    press_text(b, 'My account')
    print(b.url())
    print(b.js('''
        for (const row of document.querySelectorAll('#region-main tr')) {
            if (row.innerText.includes('Official transcript')) {
                row.id = 'feerow';
                return row.innerText;
            }
        }
        return null;'''))
    crop(b, 'sr-fee-on-account', '#feerow', pad=2)

    switch(b, 'ivy')
    b.go(AT + '/index.php')
    crop(b, 'sr-request-hold', 'form.at-request h2', 'form.at-request .sr-note')


def registrar(b):
    """The queue with holds and waiting requests, and the staff page of a student on hold."""
    switch(b, 'rosa')
    b.go(AT + '/requests.php')
    main_shot(b, 'sr-queue')
    mark_row(b, 'Gina Marsh', 'ginarow')
    b.js("document.querySelector('#ginarow details').open = true;")
    time.sleep(0.2)
    crop(b, 'sr-queue-cancel-fee', '#ginarow')
    row_link(b, 'Ivy Stone', 'Ivy Stone')
    print(b.url())
    print(b.js("return Array.from(document.querySelectorAll('#region-main h4')).map(e=>e.innerText+' => '+(e.nextElementSibling?.innerText||'').slice(0,120))"))
    b.go(AT + '/grading.php')
    crop(b, 'sr-grading-notice', '#region-main .alert-info')


def teacher(b):
    """Post final grades and Request change, with the grade going to Student Records."""
    switch(b, 'irene', width=1600)
    course = course_id(b, ENG102)
    b.go(f'{AT}/codes.php?courseid={course}')
    mark_row(b, 'Carmen Vega', 'coderow')
    print(b.js("return Array.from(document.querySelectorAll('#coderow select option')).map(o=>o.text)"))
    b.go(f'{AT}/post.php?courseid={course}')
    print(b.text('#region-main')[:1600])
    tick(b, 'Carmen Vega')
    crop(b, 'sr-post-final-grades', '#region-main h2', '#region-main form input[type=submit]', pad=4)
    print(confirm_click(b, '#region-main form input[type=submit]'))
    print(b.text('#user-notifications')[:60])
    row_link(b, 'Carmen Vega', 'Request change')
    print(b.text('#region-main')[:500])
    b.select('#id_newgrade', b.js("return document.querySelector('#id_newgrade').options[1].text;"))
    b.fill('#id_reason', 'The last essay was graded after posting.')
    crop(b, 'sr-request-change', '#region-main h2', '#region-main form.mform')
    press(b, '#id_submitbutton')
    print(b.text('#user-notifications')[:90])
    return course


def unofficial(b, name, path):
    """Download the Unofficial PDF of a student on the list, as staff."""
    b.go(AT + '/students.php')
    row_link(b, name, 'View transcript')
    b.fetch(b.url().split('&')[0] + '&action=unofficial', path)


def text_of(pdf):
    return subprocess.run(['pdftotext', '-layout', pdf, '-'], capture_output=True, text=True).stdout


def transcripts(b, folder):
    """The PDF of a student with a file, one on clock hours, and what the repeat rule does."""
    switch(b, 'rosa')
    b.go(AT + '/issued.php')
    hank = b.js("""return Array.from(document.querySelectorAll('#region-main tbody tr'))
        .filter(r => r.innerText.includes('Henry Osei'))[0].querySelector('a[href*="issued.php"]').href;""")
    pdf = os.path.join(folder, 'hank.pdf')
    b.fetch(hank, pdf)
    pdf_page(pdf, 1, img('sr-pdf-official'), dpi=100)
    clock = os.path.join(folder, 'dante.pdf')
    unofficial(b, 'Dante Hill', clock)
    print(text_of(clock)[:1800])
    pdf_page(clock, 1, img('sr-pdf-clock'), dpi=100)
    ben = os.path.join(folder, 'ben.pdf')
    unofficial(b, 'Benedict Okoye', ben)
    print(text_of(ben)[:2600])
    pdf_crop(ben, 1, 'sr-pdf-repeat', 54, 282, 912, 330)


def rules(b, folder):
    """A site administrator changes the repeat rule, and the transcript follows."""
    for label, name in (('Only the highest grade counts', 'highest'), ('All attempts count', 'all')):
        switch(b, 'admin', ADMINPASS)
        b.go(SETTINGS)
        b.select('#id_s_local_academictranscript_repeatrule', label)
        press(b, '#adminsettings button[type=submit]')
        switch(b, 'rosa')
        pdf = os.path.join(folder, f'ben-{name}.pdf')
        unofficial(b, 'Benedict Okoye', pdf)
        print(name, [line.strip() for line in text_of(pdf).splitlines() if 'MAT110' in line or 'Overall' in line or 'Repeated' in line])
    switch(b, 'admin', ADMINPASS)
    b.go(SETTINGS)
    b.select('#id_s_local_academictranscript_repeatrule', 'Only the latest attempt counts')
    press(b, '#adminsettings button[type=submit]')


def not_in_records(b, course):
    """A grade posted while the source was this plugin stops the Official transcript once the source is Student Records."""
    switch(b, 'admin', ADMINPASS)
    b.go(SETTINGS)
    b.select('#id_s_local_academictranscript_source', "This plugin's own data (posted grades)")
    press(b, '#adminsettings button[type=submit]')
    switch(b, 'irene', width=1600)
    b.go(f'{AT}/post.php?courseid={course}')
    tick(b, 'Dara Whitman')
    confirm_click(b, '#region-main form input[type=submit]')
    print(b.text('#user-notifications')[:40])
    switch(b, 'admin', ADMINPASS)
    b.go(SETTINGS)
    b.select('#id_s_local_academictranscript_source', 'Student Records')
    press(b, '#adminsettings button[type=submit]')
    switch(b, 'rosa')
    b.go(AT + '/students.php')
    row_link(b, 'Dara Whitman', 'View transcript')
    print(b.js("return Array.from(document.querySelectorAll('#region-main .alert')).map(e=>e.innerText)"))
    crop(b, 'sr-problem-not-in-records', '#region-main .alert-warning')


def issue(b):
    """Issue one request, last of all: the queue is whole only until the first Issue."""
    switch(b, 'rosa')
    b.go(AT + '/requests.php')
    mark_row(b, 'Gina Marsh', 'ginarow')
    press(b, '#ginarow form button.btn-primary')
    print(b.text('#user-notifications')[:150])


def main():
    folder = tempfile.mkdtemp(prefix='transcript-sr-')
    print('scratch', folder)
    b = WD()
    try:
        b.login('admin', ADMINPASS)
        b.go(SETTINGS)
        print(b.js("return document.querySelector('#id_s_local_academictranscript_source').value;"))
        student(b)
        registrar(b)
        course = teacher(b)
        transcripts(b, folder)
        rules(b, folder)
        not_in_records(b, course)
        issue(b)
    finally:
        b.close()


if __name__ == '__main__':
    main()
