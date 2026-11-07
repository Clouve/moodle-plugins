#!/usr/bin/env python3
"""Student Records: Moodle's data requests for a student and a staff member, then the uninstall, as a site administrator."""
# Snapshot: seed
import os
import sys
import tempfile
import time
import zipfile

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from shots import ADMINPASS, USERDOCS, click_js, clip, open_course, press, start, submit, tool, top  # noqa: E402
from srshots import pick  # noqa: E402

IMAGES = os.path.join(USERDOCS, 'student-records', 'images')
THEME = os.path.join(USERDOCS, 'theme', 'images')
REQUESTS = '/admin/tool/dataprivacy/datarequests.php'
SR = '/local/studentrecords'


def img(name):
    return os.path.join(IMAGES, name + '.png')


def request(b, person, kind, note, picture=None):
    """Make a data request for someone, as the page says, and let cron prepare it."""
    b.go(REQUESTS)
    b.click_text('New request', 'a')
    pick(b, 'userid', person)
    b.select('#id_type', kind)
    b.fill('#id_comments', note)
    if picture:
        clip(b, img(picture), '#region-main h2', '#id_submitbutton')
    submit(b, '#id_submitbutton')
    tool('adhoc')


def row(b, person):
    """Find the row of a person's request, and open its Actions menu."""
    b.go(REQUESTS)
    b.js('''for (const row of document.querySelectorAll('#region-main table tbody tr')) {
                if (row.innerText.includes(arguments[0])) { row.setAttribute('data-shot', 'request'); }
            }''', person)
    press(b, 'tr[data-shot=request] .action-menu .dropdown-toggle')


def approve(b, person):
    """Approve a request, and let cron carry it out."""
    row(b, person)
    click_js(b, 'tr[data-shot=request] a[data-action=approve]')
    time.sleep(1.2)
    click_js(b, '.modal.show button[data-action=save]')
    time.sleep(1.5)
    b.settle()
    tool('adhoc')


def download(b, person):
    """Save the export of a request in a temporary file, and print the folders of Student Records in it."""
    row(b, person)
    href = b.js("""return [...document.querySelectorAll('tr[data-shot=request] .action-menu a')]
                       .find(a => a.textContent.includes('Download')).href;""")
    with tempfile.TemporaryDirectory() as folder:
        path = os.path.join(folder, 'export.zip')
        b.fetch(href, path)
        with zipfile.ZipFile(path) as archive:
            names = sorted({name.split('/Student Records/')[1].split('/')[0] for name in archive.namelist()
                            if '/Student Records/' in name})
    print(person, names)


def data_requests(b):
    """An export and a deletion."""
    b.login('admin', ADMINPASS)
    request(b, 'Benedict Okoye', 'Export all of my personal data', 'The student asked for a copy of his records.', 'privacy-new-request')
    approve(b, 'Benedict Okoye')
    row(b, 'Benedict Okoye')
    clip(b, img('privacy-download-ready'), '#region-main table', 'tr[data-shot=request] .dropdown-menu', pad=2)
    download(b, 'Benedict Okoye')

    request(b, 'Rosa Delgado', 'Export all of my personal data', 'A staff member asked for a copy of her data.')
    approve(b, 'Rosa Delgado')
    download(b, 'Rosa Delgado')

    # The file before a deletion, as the Registrar sees it.
    b.logout()
    b.login('rosa')
    b.go(SR + '/students.php')
    b.fill('#srsearch', 'Wesley')
    b.js("document.querySelector('#srsearch').form.submit();")
    time.sleep(1)
    b.settle()
    student = b.js("return [...document.querySelectorAll('#region-main table a')].find(a => a.innerText.includes('Bell')).href;")
    b.go(student)
    clip(b, img('privacy-file-before'), '.sr-file-header', '.sr-columns', pad=4)
    b.logout()

    b.login('admin', ADMINPASS)
    request(b, 'Wesley Bell', 'Delete all of my personal data', 'The former student asked for his data to be deleted.')
    approve(b, 'Wesley Bell')
    b.go(REQUESTS)
    print(b.text('#region-main table tbody'))
    b.logout()

    b.login('rosa')
    b.go(student)
    print(b.text('#region-main')[:600])
    clip(b, img('privacy-file-after'), '.sr-file-header', '.sr-columns', pad=4)
    b.logout()


def uninstall(b):
    """Uninstall the plugin up to the question about its folder, which every test site shares, and see what stays."""
    tool('restore', 'seed')
    b.login('admin', ADMINPASS)
    b.go('/admin/settings.php?section=navigation')
    b.select('#id_s__defaulthomepage', 'Student Records')
    submit(b)
    b.go('/admin/plugins.php')
    b.go(b.js("return document.querySelector('a[href*=\"uninstall=local_studentrecords\"]').href;"))
    clip(b, img('uninstall-confirm'), '#region-main h2', '#notice', pad=6)
    b.click_text('Continue', 'button')
    time.sleep(1)
    b.settle()
    print(b.text('#region-main'))
    # Never select Continue here. It deletes the plugin folder, which every test site shares.

    b.go('/admin/settings.php?section=navigation')
    print('Start page:', b.js("""const box = document.querySelector('#id_s__defaulthomepage');
                                 return box.options[box.selectedIndex].text;"""))
    b.go('/admin/roles/manage.php')
    print(b.js("return [...document.querySelectorAll('#region-main table tbody tr td:first-child')].map(e => e.innerText.trim());"))
    b.logout()

    # The theme with no Student Records.
    b.login('irene')
    print(b.url())
    b.go('/my/')
    top(b, os.path.join(THEME, 'without-student-records-dashboard.png'), 420)
    open_course(b, 'ENG101 - English Composition I')
    top(b, os.path.join(THEME, 'without-student-records-course.png'), 420)
    b.logout()

    # With the folder still in place, a page of Academic Transcript finds the plugin's code and no tables.
    b.login('ben')
    b.go('/local/academictranscript/index.php?view=mine')
    print(b.text('#region-main')[:200])
    b.logout()
    tool('restore', 'seed')


def main():
    b = start()
    try:
        data_requests(b)
        uninstall(b)
    finally:
        b.close()


if __name__ == '__main__':
    main()
