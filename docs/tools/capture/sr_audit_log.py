#!/usr/bin/env python3
"""Student Records: the Audit log as the Registrar: open it, read its rows, filter them, and see what a data request leaves."""
# Snapshot: seed
import os
import sys
import tempfile
import time
import zipfile

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (SR, click_js, close_menu, crop, flash, img, leave, open_file, person, site, submit,  # noqa: E402
                       switch, tool)
from srshots import pick  # noqa: E402

LOG = SR + '/log.php'
REQUESTS = '/admin/tool/dataprivacy/datarequests.php'


def rows(b):
    """The rows of the log on this page, for the output."""
    return b.js('''return [...document.querySelectorAll('#region-main table tbody tr')]
        .map(r => [...r.cells].map(c => c.innerText.trim()).join(' | '))''')


def nth_row(n):
    """A selector for the nth row of the log."""
    return f'#region-main table tbody tr:nth-child({n})'


def filter_by(b, label):
    """Pick an action in the filter, with a click on the option, as a person does. The page goes at once."""
    b.click('select[name=logaction]')
    option = b.js('''return [...document.querySelectorAll('select[name=logaction] option')]
                         .find(o => o.text.trim() === arguments[0]).value;''', label)
    b.s('POST', f"/element/{b.find(f'select[name=logaction] option[value={option}]')}/click", {})
    time.sleep(1)
    b.settle()
    print(label, b.url())


def place_hold(b, name, reason):
    """Place a hold on a student's file."""
    open_file(b, name, 'holds')
    b.click_text('Place a hold', 'a')
    b.fill('#id_reason', reason)
    b.select('#id_blocksreg', 'No')
    submit(b)
    print('hold', flash(b)[:60])


def log_in_as(b, name):
    """Use Moodle's Log in as from a person's profile, as a site administrator."""
    b.go('/admin/user.php')
    time.sleep(1)
    b.go(b.js('''return [...document.querySelectorAll('#region-main a[href*="user/profile.php"]')]
                     .find(a => a.innerText.includes(arguments[0])).href;''', name))
    b.go(b.js('''return document.querySelector('a[href*="course/loginas.php"]').href;'''))
    b.click_text('Continue', 'a, button')


def request(b, name, kind, note):
    """Make a data request for someone, and let cron prepare it. A privacy officer does this, not the Registrar."""
    b.go(REQUESTS)
    b.click_text('New request', 'a')
    pick(b, 'userid', name)
    b.select('#id_type', kind)
    b.fill('#id_comments', note)
    submit(b, '#id_submitbutton')
    tool('adhoc')


def open_request(b, name):
    """Find the row of a person's request, and open its Actions menu."""
    b.go(REQUESTS)
    b.js('''for (const row of document.querySelectorAll('#region-main table tbody tr')) {
                if (row.innerText.includes(arguments[0])) { row.setAttribute('data-shot', 'request'); }
            }''', name)
    b.click('tr[data-shot=request] .action-menu .dropdown-toggle')


def approve(b, name):
    """Approve a request, and let cron carry it out."""
    open_request(b, name)
    click_js(b, 'tr[data-shot=request] a[data-action=approve]')
    time.sleep(1.2)
    click_js(b, '.modal.show button[data-action=save]')
    time.sleep(1.5)
    b.settle()
    tool('adhoc')


def export_file(b, name):
    """Print the start of what the export of a request holds of the audit log."""
    open_request(b, name)
    href = b.js("""return [...document.querySelectorAll('tr[data-shot=request] .action-menu a')]
                       .find(a => a.textContent.includes('Download')).href;""")
    with tempfile.TemporaryDirectory() as folder:
        path = os.path.join(folder, 'export.zip')
        b.fetch(href, path)
        with zipfile.ZipFile(path) as archive:
            for member in archive.namelist():
                if member.endswith('Student Records/Audit log/data.json'):
                    print(archive.read(member).decode()[:220])


def read_the_log(b):
    """Open the log, write some rows, and read them."""
    # Open it from the menu, with the end of the menu in view.
    b.go(SR + '/index.php')
    b.click_text('Audit log', 'a.sr-nav-link')
    print(b.url(), rows(b)[:1])
    b.js('''const drawer = document.querySelector('.sr-nav').closest('.drawercontent');
            drawer.scrollTop = drawer.scrollHeight;''')
    time.sleep(0.4)
    b.shot(img('audit-log-open'), full=False)

    # The other way in, then close the menu so that the table has room.
    b.click_text('All staff pages', 'a.sr-nav-link')
    crop(b, 'audit-log-staff-area', '#page-header h1', '#region-main .list-group', pad=6)
    b.click_text('Audit log', '#region-main a, a.sr-nav-link')
    close_menu(b)

    # Work that writes rows: a file and one of its tabs, the roster, a download and a hold.
    open_file(b, 'Marsh')
    open_file(b, 'Marsh', 'holds')
    b.go(SR + '/roster.php')
    with tempfile.TemporaryDirectory() as folder:
        b.fetch(SR + '/reports.php?report=enrollment&download=csv', os.path.join(folder, 'enrollment.csv'))
    place_hold(b, 'Bell', 'Return the library books before any transcript goes out.')
    b.go(LOG)
    for line in rows(b)[:7]:
        print(line)
    crop(b, 'audit-log-rows', '#page-header h1', nth_row(7), pad=6)

    # What writes no row.
    before = rows(b)[0]
    for page in ('/students.php', '/reports.php', '/offerings.php', '/log.php'):
        b.go(SR + page)
    b.go(LOG)
    print('no new row:', rows(b)[0] == before)


def filters(b):
    """The filter, the links and the pages."""
    filter_by(b, 'Exported')
    print(rows(b)[:3])
    filter_by(b, 'Approved import items')
    crop(b, 'audit-log-refund-rows', '.singleselect', nth_row(3), pad=6)
    filter_by(b, 'Imported a file')
    print(b.text('#region-main')[-40:])
    crop(b, 'audit-log-nothing', '.singleselect', '#region-main .alert', pad=6)

    # Follow a row to its record.
    filter_by(b, 'Viewed')
    b.click('#region-main table tbody tr td.c3 a')
    print(b.url())

    # The pages of the log, which keep the filter.
    b.go(LOG)
    crop(b, 'audit-log-paging', '#region-main .pagination', pad=6)
    filter_by(b, 'Changed')
    click_js(b, '#region-main .pagination a[href*="page=1"]')
    time.sleep(0.6)
    b.settle()
    print(b.url(), len(rows(b)))


def other_people(b):
    """Someone without the capability, and the rows of a student, a staff member and Log in as."""
    switch(b, 'felix')
    b.go(SR + '/index.php')
    print(b.js("return [...document.querySelectorAll('a.sr-nav-link')].map(a => a.innerText).slice(-3)"))
    b.go(LOG)
    print(b.text('#region-main'))

    # A student opens their own record, and a staff member places a hold, so that their rows have an address.
    switch(b, 'wes')
    b.go(SR + '/index.php')
    b.click_text('My academic record', 'a.sr-nav-link')
    switch(b, 'doris')
    place_hold(b, 'Cruz', 'Bring the original of the diploma to the office.')

    # Log in as: the row names the student, not the administrator.
    switch(b, 'admin')
    log_in_as(b, 'Ada Whitlock')
    b.go(SR + '/index.php')
    b.click_text('My academic record', 'a.sr-nav-link')
    b.logout()
    switch(b, 'rosa')
    b.go(LOG)
    print(rows(b)[0])
    filter_by(b, 'Changed')
    for line in rows(b)[:2]:
        print(line)
    crop(b, 'audit-log-privacy-before', '.singleselect', nth_row(2), pad=6)


def data_requests(b):
    """What a privacy officer's requests leave in the log, as the Registrar sees it."""
    switch(b, 'admin')
    request(b, 'Benedict Okoye', 'Export all of my personal data', 'The student asked for a copy of his records.')
    approve(b, 'Benedict Okoye')
    export_file(b, 'Benedict Okoye')
    request(b, 'Wesley Bell', 'Delete all of my personal data', 'The former student asked for his data to be deleted.')
    approve(b, 'Wesley Bell')
    request(b, 'Doris Hale', 'Delete all of my personal data', 'A former staff member asked for her data to be deleted.')
    approve(b, 'Doris Hale')
    b.go(REQUESTS)
    print(b.text('#region-main table tbody'))

    switch(b, 'rosa')
    b.go(LOG)
    for line in rows(b)[:8]:
        print(line)
    filter_by(b, 'Changed')
    for line in rows(b)[:2]:
        print(line)
    crop(b, 'audit-log-privacy-after', '.singleselect', nth_row(2), pad=6)
    filter_by(b, 'Privacy export')
    print(rows(b))

    # Later, Moodle's task removes the user context, and the plugin's hourly task finishes the request.
    site("php admin/cli/scheduled_task.php --execute='\\core\\task\\context_cleanup_task' > /dev/null")
    site("php admin/cli/scheduled_task.php --execute='\\local_studentrecords\\task\\erase_removed_accounts' > /dev/null")
    filter_by(b, 'Privacy deletion')
    for line in rows(b):
        print(line)
    crop(b, 'audit-log-privacy-deletion', '.singleselect', '#region-main table', pad=6)


def main():
    b = person('rosa')
    read_the_log(b)
    filters(b)
    other_people(b)
    data_requests(b)
    leave(b)


if __name__ == '__main__':
    main()
