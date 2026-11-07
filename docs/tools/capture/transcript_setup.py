#!/usr/bin/env python3
"""Academic Transcript: the setup wizard and what follows it, as a site administrator."""
# Snapshot: fresh
import base64
import io
import os
import subprocess
import sys
import tempfile
import time
import zipfile

sys.dont_write_bytecode = True
TOOLS = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, TOOLS)
from wd import SITE, WD  # noqa: E402

IMAGES = os.path.join(TOOLS, '..', 'user', 'academic-transcript', 'images')
ASSETS = os.path.join(TOOLS, 'assets')
ADMINPASS = os.environ.get('ADMINPASS', 'Admin@123')
AT = '/local/academictranscript'
YEAR = time.localtime().tm_year
SCHOOL = {'name': 'Riverside College', 'address': '100 College Way\nElm Hollow, IL 62999', 'phone': '(618) 555-0100',
          'email': 'registrar@example.com', 'website': 'https://www.riverside.example.com', 'registrar': 'Rosa Delgado',
          'title': 'College Registrar'}


def img(name):
    return os.path.join(IMAGES, name + '.png')


def site(*command):
    """Run a command in the Moodle folder of the site, for what isn't part of a person's job."""
    subprocess.run([os.path.join(TOOLS, 'site.sh'), 'sh', *command], check=True,
                   env={**os.environ, 'SITE': str(SITE)}, stdout=subprocess.DEVNULL)


def crop(b, name, first, last=None, pad=8, tall=True, width=None):
    """Save the part of the page from the top of one element to the bottom of another, at most this wide."""
    if tall:
        b.metrics(min(b.page_height() + 8, 8000))
        time.sleep(0.5)
    box = b.js('''
        const a = document.querySelector(arguments[0]).getBoundingClientRect();
        const z = document.querySelector(arguments[1] || arguments[0]).getBoundingClientRect();
        return [Math.min(a.left, z.left), a.top, Math.max(a.right, z.right), z.bottom];''', first, last)
    clip = {'x': max(box[0] - pad, 0), 'y': max(box[1] - pad, 0), 'width': min(box[2] - box[0] + 2 * pad, width or 9999),
            'height': box[3] - box[1] + 2 * pad, 'scale': 1}
    data = b.cdp('Page.captureScreenshot', {'format': 'png', 'clip': clip})['data']
    os.makedirs(IMAGES, exist_ok=True)
    with open(img(name), 'wb') as out:
        out.write(base64.b64decode(data))
    if tall:
        b.metrics(b.height)


def main_shot(b, name):
    """Save the main region of the page."""
    b.metrics(min(b.page_height() + 8, 8000))
    time.sleep(0.4)
    b.shot_of('#region-main', img(name))
    b.metrics(b.height)


def click_js(b, css):
    """Click an element that may be under a sticky bar."""
    b.js('document.querySelector(arguments[0]).click();', css)


def press(b, css, navigates=True):
    """Click a button or link, and wait for the page it opens."""
    b.js('window.wdstay = 1;')
    click_js(b, css)
    end = time.time() + (20 if navigates else 1)
    while time.time() < end:
        try:
            if not b.js('return window.wdstay === 1;'):
                break
        except RuntimeError:
            pass
        time.sleep(0.2)
    b.settle()


def press_text(b, text, css='a, button, input[type=submit]'):
    """Click what shows this text, and wait for the page it opens."""
    b.js('window.wdstay = 1;')
    b.click_text(text, css)
    end = time.time() + 20
    while time.time() < end:
        try:
            if not b.js('return window.wdstay === 1;'):
                break
        except RuntimeError:
            pass
        time.sleep(0.2)
    b.settle()


def confirm_click(b, css):
    """Click a button that opens the browser's confirm box, and accept the box. Returns what the box said."""
    b.js('const e = document.querySelector(arguments[0]); setTimeout(() => e.click(), 50);', css)
    time.sleep(0.6)
    text = b.s('GET', '/alert/text')
    b.s('POST', '/alert/accept', {})
    time.sleep(0.5)
    b.settle()
    return text


def upload(b, opener, path, name=None):
    """Add a file with Moodle's file picker, as a person does: Add, Upload a file, Upload this file."""
    buffer = io.BytesIO()
    name = name or os.path.basename(path)
    with zipfile.ZipFile(buffer, 'w') as archive:
        archive.write(path, name)
    remote = b.s('POST', '/se/file', {'file': base64.b64encode(buffer.getvalue()).decode()})
    click_js(b, opener)
    time.sleep(1.2)
    b.js('''for (const repo of document.querySelectorAll('.moodle-dialogue-base[aria-hidden=false] .fp-repo')) {
                if (repo.innerText.trim() === 'Upload a file') {
                    repo.querySelector('a').click();
                }
            }''')
    time.sleep(1.2)
    field = b.find('.moodle-dialogue-base[aria-hidden=false] input[type=file]')
    b.s('POST', f'/element/{field}/value', {'text': remote})
    click_js(b, '.moodle-dialogue-base[aria-hidden=false] .fp-upload-btn')
    for _ in range(30):
        time.sleep(0.4)
        if not b.has('.moodle-dialogue-base[aria-hidden=false] .fp-upload-btn'):
            break
    time.sleep(0.6)


def write(folder, name, text):
    """Make a small file for an upload."""
    path = os.path.join(folder, name)
    with open(path, 'w', encoding='utf-8') as out:
        out.write(text)
    return path


def add_term(b, name, start, end, shot=None):
    """Fill in the term form and save it."""
    b.fill('#id_name', name)
    for field, (day, month, year) in (('startdate', start), ('enddate', end)):
        b.select(f'#id_{field}_day', str(day))
        b.select(f'#id_{field}_month', month)
        b.select(f'#id_{field}_year', str(year))
    if shot:
        main_shot(b, shot)
    press(b, '#id_submitbutton')


def read_csv(b, label, path):
    """On a wizard step, choose what the file holds, upload it and read it."""
    b.select('#id_itemtype', label)
    upload(b, '.fp-btn-choose', path)
    press(b, '#id_submitbutton')


def install(b, folder):
    """The plugin installer, up to its check of the ZIP file. Its Continue would replace the folder that all test sites share."""
    repo = os.path.dirname(os.path.dirname(TOOLS))
    zipped = subprocess.run([os.path.join(repo, 'bin', 'build-zip'), os.path.join(repo, 'local', 'academictranscript'), folder],
                            check=True, capture_output=True, text=True).stdout.strip()
    b.go('/admin/tool/installaddon/index.php')
    upload(b, '.fp-btn-choose', zipped)
    crop(b, 'install-zip-form', '#region-main form.mform')
    press(b, '#id_submitbutton')
    print(b.text('#region-main')[:300])


def wizard(b, folder):
    """The six steps."""
    # Until the setup is finished, some pages send you to the wizard.
    b.go(AT + '/issued.php')
    print(b.url(), b.text('#user-notifications')[:60])

    # Step 1, School details.
    b.go(AT + '/setup.php')
    b.fill('#id_schoolname', SCHOOL['name'])
    b.fill('#id_address', SCHOOL['address'])
    b.fill('#id_phone', SCHOOL['phone'])
    b.fill('#id_email', SCHOOL['email'])
    b.fill('#id_website', SCHOOL['website'])
    b.fill('#id_registrarname', SCHOOL['registrar'])
    b.fill('#id_registrartitle', SCHOOL['title'])
    upload(b, '.filemanager .fp-btn-add a', os.path.join(ASSETS, 'logo.png'))
    main_shot(b, 'wizard-school-details')
    press(b, '#id_submitbutton')

    # Step 2, Grading. A CSV file replaces the scale, and the button brings the standard scale back.
    crop(b, 'wizard-grading', '#region-main h2', '#region-main .d-flex.gap-2.my-3')
    crop(b, 'wizard-csv-section', '#region-main h4', '#id_submitbutton')
    grades = write(folder, 'grading-policy.csv', """letter,min,max,points,ingpa,earns,description
A,90,100,4.0,1,1,Excellent
B,80,89,3.0,1,1,Good
C,70,79,2.0,1,1,Satisfactory
D,60,69,1.0,1,1,Poor
F,0,59,0.0,1,0,Failing
""")
    read_csv(b, 'Grading scale', grades)
    main_shot(b, 'drafts-grade-review')
    press_text(b, 'Approve selected')
    print(b.text('#user-notifications')[:40])
    press_text(b, 'Load the standard 4.0 scale')
    print(b.text('#user-notifications')[:80])
    press_text(b, 'Next step')

    # Step 3, Marks.
    crop(b, 'wizard-marks', '#region-main h2', '#region-main .d-flex.gap-2.my-3')
    marks = write(folder, 'marks.csv', """code,label,description,earns
AU,Audit,Audited the course; no hours earned,0
""")
    read_csv(b, 'Marks', marks)
    press_text(b, 'Approve selected')
    press_text(b, 'Next step')

    # Step 4, Programs and hours.
    programs = write(folder, 'programs.csv', """name,credential,unittype,totalhours
Business Administration,Associate of Science,credit,60
Medical Assistant,Certificate,clock,900
""")
    read_csv(b, 'Programs', programs)
    press_text(b, 'Approve selected')
    courses = write(folder, 'courses.csv', """code,title,hours,unittype,program
BUS 101,Introduction to Business,3,credit,Business Administration
ACC 110,Principles of Accounting,,credit,Business Administration
MA 110,Clinical Procedures I,120,clock,Medical Assistant
""")
    read_csv(b, 'Courses', courses)
    main_shot(b, 'drafts-course-review')
    press_text(b, 'Approve selected')
    crop(b, 'wizard-programs', '#region-main h2', '#region-main .d-flex.gap-2.my-3')
    press_text(b, 'Next step')

    # Step 5, Transcript look.
    main_shot(b, 'wizard-look')
    b.fill('#id_accentcolor', 'navy')
    press(b, '#id_submitbutton')
    print(b.text('#fitem_id_accentcolor'))
    b.fill('#id_accentcolor', '1F3A5F')
    press(b, '#id_submitbutton')

    # Step 6, Finish, with one check open.
    main_shot(b, 'wizard-finish-open')
    press_text(b, 'At least one term is set (Terms page)')
    press_text(b, 'Add a term')
    add_term(b, f'Fall {YEAR}', (24, 'August', YEAR), (18, 'December', YEAR), 'terms-add-form')
    print(b.text('#user-notifications')[:40])
    b.go(AT + '/setup.php?step=done')
    print(b.text('#region-main ul'))
    press_text(b, 'Finish setup')
    print(b.url(), b.text('#user-notifications')[:80])


def terms(b):
    """Terms, after the wizard, and what the form says about a bad entry."""
    b.go(AT + '/terms.php')
    press_text(b, 'Add a term')
    add_term(b, f'Spring {YEAR}', (12, 'January', YEAR), (8, 'May', YEAR))
    press_text(b, 'Add a term')
    add_term(b, f'Spring {YEAR + 1}', (11, 'January', YEAR + 1), (7, 'May', YEAR + 1))
    crop(b, 'terms-list', '#region-main h2', '#region-main table')
    press_text(b, 'Add a term')
    b.fill('#id_name', f'Fall {YEAR}')
    b.select('#id_enddate_year', str(YEAR - 1))
    press(b, '#id_submitbutton')
    crop(b, 'terms-errors', '#fitem_id_name', '#fitem_id_enddate')


def scale(b):
    """The grading scale and the marks by hand. A row that overlaps shows an error, and the typed rows are gone."""
    b.go(AT + '/grading.php')
    main_shot(b, 'grading-scale-edit')

    def extra_row():
        b.fill('input[name="g[12][letter]"]', 'A+')
        b.fill('input[name="g[12][minpct]"]', '98')
        b.fill('input[name="g[12][maxpct]"]', '100')
        b.fill('input[name="g[12][points]"]', '4.0')
        b.fill('input[name="g[12][description]"]', 'Excellent')

    extra_row()
    press_text(b, 'Save the grading scale')
    crop(b, 'grading-scale-overlap', '#region-main .alert-danger')
    print('rows after the error:', repr(b.js("return document.querySelector('input[name=\"g[12][letter]\"]').value")))
    b.fill('input[name="g[0][maxpct]"]', '97')
    extra_row()
    press_text(b, 'Save the grading scale')
    print(b.text('#user-notifications')[:40])
    print(confirm_click(b, 'input[name=preset]'))
    print(b.text('#user-notifications')[:80])

    b.go(AT + '/marks.php')
    last = b.js("return document.querySelectorAll('#region-main tbody tr').length") - 3
    b.fill(f'input[name="m[{last}][code]"]', 'EX')
    b.fill(f'input[name="m[{last}][label]"]', 'Excused')
    b.fill(f'input[name="m[{last}][description]"]', 'Excused from the course. No hours earned.')
    main_shot(b, 'marks-edit')
    press(b, '#region-main input[type=submit]')
    print(b.text('#user-notifications')[:30])


def programs(b, folder):
    """Programs and the catalog by hand, then a CSV file with a missing column and a rejected draft."""
    b.go(AT + '/programs.php')
    press_text(b, 'Add a program')
    b.fill('#id_name', 'Liberal Arts')
    b.fill('#id_credential', 'Associate of Arts')
    b.fill('#id_totalhours', '60')
    main_shot(b, 'programs-add-form')
    press(b, '#id_submitbutton')
    crop(b, 'programs-list', '#region-main table', pad=2)

    b.go(AT + '/catalog.php')
    press_text(b, 'Add a catalog course')
    b.fill('#id_code', 'ENG 101')
    b.fill('#id_title', 'English Composition')
    b.fill('#id_hours', '3')
    b.select('#id_programid', 'Liberal Arts')
    main_shot(b, 'catalog-add-form')
    press(b, '#id_submitbutton')
    crop(b, 'catalog-list', '#region-main table', pad=2)
    press_text(b, 'Add a catalog course')
    b.fill('#id_code', 'ENG 101')
    b.fill('#id_title', 'English Composition again')
    b.fill('#id_hours', '3')
    press(b, '#id_submitbutton')
    print(b.text('#fitem_id_code'))

    b.go(AT + '/setup.php?step=programs')
    bad = write(folder, 'courses-no-title.csv', """code,hours,unittype,program
BUS 201,3,credit,Business Administration
""")
    read_csv(b, 'Courses', bad)
    crop(b, 'drafts-csv-missing-column', '#user-notifications .alert')
    b.go(AT + '/setup.php?step=programs')
    old = write(folder, 'old-programs.csv', """name,credential,unittype,totalhours
Bookkeeping,Certificate,credit,30
""")
    read_csv(b, 'Programs', old)
    press_text(b, 'Reject selected')
    print(b.url(), b.text('#user-notifications')[:30])


def courses(b):
    """Two courses with their Transcript fields, one with a code only, then the Course codes check."""
    b.go('/course/edit.php?category=1')
    b.fill('#id_fullname', 'Introduction to Business')
    b.fill('#id_shortname', 'BUS101-F')
    press(b, '#id_saveanddisplay', navigates=False)
    click_js(b, 'a.collapseexpand')
    time.sleep(0.5)
    print(b.text('#fitem_id_customfield_transcript_coursecode'))
    b.fill('#id_customfield_transcript_coursecode', 'BUS 101')
    b.fill('#id_customfield_transcript_creditunits', '3')
    b.select('#id_customfield_transcript_hourtype', 'Credit hours')
    b.select('#id_customfield_transcript_term', f'Fall {YEAR}')
    click_js(b, '#id_fullname')
    crop(b, 'course-fields', '#id_category_1')
    press(b, '#id_saveanddisplay')

    b.go('/course/edit.php?category=1')
    b.fill('#id_fullname', 'Clinical Procedures I')
    b.fill('#id_shortname', 'MA110-F')
    click_js(b, 'a.collapseexpand')
    time.sleep(0.5)
    b.fill('#id_customfield_transcript_coursecode', 'BUS 101')
    press(b, '#id_saveanddisplay', navigates=False)
    print(b.text('#fitem_id_customfield_transcript_coursecode'))
    b.fill('#id_customfield_transcript_coursecode', 'MA 110')
    press(b, '#id_saveanddisplay')
    b.go(AT + '/coursecheck.php')
    main_shot(b, 'coursecheck')


def main():
    site('php admin/cli/cfg.php --name=theme --set=studentrecords')
    folder = tempfile.mkdtemp(prefix='transcript-setup-')
    b = WD()
    try:
        b.login('admin', ADMINPASS)
        install(b, folder)
        wizard(b, folder)
        terms(b)
        scale(b)
        programs(b, folder)
        courses(b)
    finally:
        b.close()


if __name__ == '__main__':
    main()
