#!/usr/bin/env python3
"""Student Records: faculty and staff records, credentials, qualified courses, the roster and No LMS access, as the Registrar."""
# Snapshot: seed
import os
import sys
import tempfile
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import ASSETS, SR, Browser as WD, crop, img, main_shot, pick, submit, table, tick, upload  # noqa: E402


def open_person(b, name, kind='faculty'):
    """Open a record from the list."""
    b.go(SR + f'/faculty.php?type={kind}')
    b.click_text(name, '#region-main a')


def pick_user(b, text):
    """Find a Moodle account in the search box of the form."""
    b.fill('#fitem_id_userid input[role=combobox]', text)
    time.sleep(1.5)
    found = b.js('''const list = document.querySelector('#fitem_id_userid ul[role=listbox]');
                    const item = list.querySelector('li[role=option]');
                    if (item) { item.click(); return item.innerText; }
                    return list.innerText;''')
    time.sleep(0.5)
    print('user:', found)


def press(b, label):
    """Press a form's submit button by what it says."""
    b.js('''for (const button of document.querySelectorAll('#region-main input[type=submit], #region-main button[type=submit]')) {
                if ((button.value || button.innerText).trim() === arguments[0]) { button.click(); break; }
            }''', label)
    time.sleep(1)
    b.settle()


def main():
    b = WD()
    b.login('rosa')

    b.go(SR + '/staff.php')
    b.click_text('Faculty and staff records', 'a')
    main_shot(b, 'faculty-list')

    # A new teacher with a Moodle account.
    b.click_text('Add faculty or staff record', 'a')
    pick_user(b, 'Paul Greer')
    b.fill('#id_honorific', 'Dr.')
    b.fill('#id_firstname', 'Paul')
    b.fill('#id_lastname', 'Greer')
    b.fill('#id_jobtitle', 'Assistant Professor of Business')
    b.fill('#id_department', 'Business')
    b.fill('#id_email', 'paul@example.com')
    b.fill('#id_phone', '(618) 555-0143')
    b.select('#id_employment', 'Full-time')
    b.fill('#id_officehours', 'Tuesday and Thursday, 2:00 to 4:00')
    b.fill('#id_notes', 'Started this year.')
    main_shot(b, 'faculty-add')
    submit(b)
    print(b.url())

    # The form for a degree. In this release neither Save changes nor Cancel works on it: leave it by the link back.
    record = b.url()
    b.click_text('Add degree, license or other credential', 'a')
    b.select('#id_credtype', 'Degree')
    b.fill('#id_title', 'M.B.A.')
    b.fill('#id_subject', 'Finance')
    b.fill('#id_institution', 'Prairie University (made up)')
    b.fill('#id_yearawarded', '2015')
    tick(b, '#id_verified')
    upload(b, '.filemanager .fp-btn-add a', os.path.join(ASSETS, 'signature.png'), name='diploma-copy.png')
    main_shot(b, 'faculty-credential')
    b.go(record)

    # Courses he may teach, and one taken away again.
    pick(b, 'courseids', 'ACC201')
    pick(b, 'courseids', 'ACC202')
    pick(b, 'courseids', 'BUS101')
    press(b, 'Add as qualified')
    print(b.text('#region-main')[:900])
    pick(b, 'removeids', 'BUS101')
    press(b, 'Remove qualification')
    b.js("document.querySelector('#region-main details').open = true;")
    crop(b, 'faculty-qualified', '#region-main h3:nth-of-type(2)', '#region-main details')

    # A record with its credentials.
    open_person(b, 'Pia Lindgren')
    crop(b, 'faculty-record', '#page-header', table(b, 1), pad=0)

    # A teacher's advisees.
    open_person(b, 'Dr. Carl Jensen')
    print(b.js("return [...document.querySelectorAll('#region-main h3')].map(h=>h.innerText)"))
    b.js('''for (const heading of document.querySelectorAll('#region-main h3')) {
                if (heading.innerText.trim() === 'Advisees') {
                    heading.id = 'shot-advisees';
                    heading.nextElementSibling.id = 'shot-advisee-list';
                }
            }''')
    crop(b, 'faculty-advisees', '#shot-advisees', '#shot-advisee-list')

    # The roster, with the form for several people.
    b.go(SR + '/faculty.php')
    b.click_text('Faculty roster', 'a')
    crop(b, 'faculty-roster', '#page-header', table(b, 0), pad=0)
    pick(b, 'facultyids', 'Dr. Paul Greer')
    pick(b, 'facultyids', 'Dr. Tomas Silva')
    pick(b, 'courseids', 'ECO201')
    crop(b, 'faculty-roster-several', '#id_h_bulk', '#id_h_bulk ~ #fgroup_id_buttonar, #id_submitbutton')
    press(b, 'Apply')
    print(b.text('#user-notifications'))

    # The only way to add a degree in this release: a Faculty sheet, with a row that differs from the saved record.
    folder = tempfile.mkdtemp(prefix='sr-faculty-')
    path = os.path.join(folder, 'degrees.csv')
    with open(path, 'w', encoding='utf-8') as out:
        out.write('Name,Role,Type,Highest degree,Department,Email\n'
                  'Dr. Paul Greer,Assistant Professor of Finance,Faculty,M.B.A. in Finance,Business,paul@example.com\n')
    b.go(SR + '/import.php')
    upload(b, 'input[name=importfilechoose]', path)
    submit(b)
    print(b.text('#region-main table'))
    b.click_text('Approve all pending items without errors', 'button, input[type=submit]')
    time.sleep(1)
    b.settle()
    b.go(SR + '/roster.php')
    print(b.text('#region-main table').split('\n')[:4])
    b.close()

    # Doris has No LMS access: she can't open a course, and her top bar says so.
    b = WD()
    b.login('doris')
    print(b.url())
    b.go('/my/')
    print(b.url())
    b.go('/my/courses.php')
    print(b.url())
    b.shot(img('faculty-nolms-home'), full=False)
    b.close()


if __name__ == '__main__':
    main()
