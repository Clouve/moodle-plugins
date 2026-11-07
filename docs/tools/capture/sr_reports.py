#!/usr/bin/env python3
"""Student Records: reports, admissions reports, saved exports, counts and the Clearinghouse file, as the Registrar."""
# Snapshot: seed
import datetime
import os
import sys
import tempfile
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import SR, Browser as WD, crop, main_shot, set_date, submit, table  # noqa: E402

TODAY = datetime.date.today()
FIELDS = ['Student ID', 'Legal last name', 'Legal first name', 'Program code', 'Status', 'Hours in the term']


def tab(b, label):
    """Open a tab of the page."""
    b.click_text(label, '#region-main .nav a, #region-main ul.nav a, .tabtree a, a.nav-link')


def pick_term(b, term):
    """Pick a term in Moodle's one-menu form, which goes to the page as soon as a person picks."""
    b.select('select[name=termid]', term)
    b.js("document.querySelector('select[name=termid]').form.submit();")
    time.sleep(0.8)
    b.settle()


def first_lines(path, n=3):
    """The first lines of a file that was downloaded, for the output."""
    with open(path, encoding='utf-8', errors='replace') as data:
        return data.read().split('\n')[:n]


def pick_fields(b, labels):
    """Pick several options of the Fields list."""
    b.js('''const list = document.querySelector('#id_fields');
            for (const option of list.options) { option.selected = arguments[0].includes(option.text.trim()); }
            list.dispatchEvent(new Event('change', {bubbles: true}));''', labels)


def main():
    folder = tempfile.mkdtemp(prefix='sr-reports-')
    b = WD()
    b.login('rosa')
    b.go(SR + '/staff.php')
    b.click_text('Reports', 'a')
    print(b.url())
    term = b.js("return document.querySelector('select[name=termid]').options[4].text")
    print(term)
    pick_term(b, term)
    print(b.url())
    main_shot(b, 'reports-enrollment')
    for name, label in (('hours', 'Credit hours'), ('completion', 'Completion rate'), ('graduation', 'Graduation rate'),
                        ('attendance', 'Attendance'), ('attendunder', 'Under minimum attendance')):
        tab(b, label)
        if name != 'graduation':
            pick_term(b, term)
        print(b.url(), b.text('#region-main table')[:300] if b.has('#region-main table') else 'no table')
        crop(b, 'reports-' + name, '#region-main .nav', table(b, 0) if b.has('#region-main table') else '#region-main p')
    b.fetch(SR + '/reports.php?report=enrollment&download=csv', os.path.join(folder, 'enrollment.csv'))
    print(first_lines(os.path.join(folder, 'enrollment.csv')))

    # Admissions reports. The tables of the pipeline and the decisions are wider than the page beside the menu, so the
    # menu is folded for these reports, as a person does, and opened again after. Moodle remembers it for the person.
    b.go(SR + '/admissions.php?report=pipeline')
    b.js('''const button = document.querySelector('button.drawertoggle[data-action=closedrawer][aria-label*="Student Records"]');
            if (button) { button.click(); }''')
    time.sleep(1)
    b.settle()
    print(b.text('#region-main')[:500])
    # From the tabs down: with the menu folded, its button sits over the breadcrumb.
    crop(b, 'reports-admissions-pipeline', '#region-main .nav', '#region-main')
    b.go(SR + '/admissions.php?report=missing')
    crop(b, 'reports-admissions-missing', '#region-main .nav', table(b, 0))
    b.go(SR + '/admissions.php?report=decisions')
    crop(b, 'reports-admissions-decisions', '#region-main .nav', table(b, 0) + ' tbody tr:nth-child(6)')
    b.js("document.querySelector('.drawer-left-toggle button').click();")
    time.sleep(1)
    b.settle()

    # Saved exports.
    b.go(SR + '/staff.php')
    b.click_text('Outside reports', 'a')
    main_shot(b, 'exports-list')

    # A starter needs its term or dates.
    b.js('''for (const card of document.querySelectorAll('.sr-card')) {
                if (card.innerText.includes('annual enrollment')) {
                    [...card.querySelectorAll('a')].find(a => a.innerText.trim() === 'Settings').click();
                }
            }''')
    b.settle()
    b.select('#id_termid', term)
    main_shot(b, 'exports-settings')
    submit(b)
    print(b.url(), b.text('#user-notifications'))
    b.js('''for (const card of document.querySelectorAll('.sr-card')) {
                if (card.innerText.includes('annual enrollment')) {
                    [...card.querySelectorAll('a')].find(a => a.innerText.trim() === 'Run report').click();
                }
            }''')
    b.settle()
    crop(b, 'exports-run', '#page-header', table(b, 0) + ' tbody tr:nth-child(8)', pad=0, width=885)
    run = b.url()
    b.fetch(run + '&download=csv', os.path.join(folder, 'export.csv'))
    print(first_lines(os.path.join(folder, 'export.csv')))

    # Our own export, with the agency's column names.
    b.go(SR + '/outsidereports.php')
    b.click_text('Add an export', 'a')
    b.fill('#id_name', 'State Board: fall roster')
    b.select('#id_termid', term)
    pick_fields(b, FIELDS)
    b.fill('#id_headers', 'STUDENT_NO\nFIRST\nLAST\nPROGRAM\n\nHOURS')
    b.fill('#id_note', 'Due to the State Board by October 15.')
    main_shot(b, 'exports-add')
    submit(b)
    print(b.url())
    b.js('''for (const card of document.querySelectorAll('.sr-card')) {
                if (card.innerText.includes('fall roster')) {
                    [...card.querySelectorAll('a')].find(a => a.innerText.trim() === 'Run report').click();
                }
            }''')
    b.settle()
    print(b.text('#region-main')[:500])
    b.fetch(b.url() + '&download=csv', os.path.join(folder, 'own.csv'))
    print(first_lines(os.path.join(folder, 'own.csv')))
    b.go(SR + '/outsidereports.php')
    crop(b, 'exports-recent', '#region-main h2.sr-h2', table(b, 0))

    # Graduates between two dates.
    b.js('''for (const card of document.querySelectorAll('.sr-card')) {
                if (card.innerText.includes('completers')) {
                    [...card.querySelectorAll('a')].find(a => a.innerText.trim() === 'Settings').click();
                }
            }''')
    b.settle()
    set_date(b, 'fromdate', TODAY.year, 1, 1)
    set_date(b, 'todate', TODAY.year, 12, 31)
    submit(b)

    # Counts.
    b.go(SR + '/outsidereports.php?tab=counts')
    print(b.text('#region-main')[:800])
    main_shot(b, 'reports-counts-headcount')
    b.select('select[name=kind]', 'Completions (awards conferred)')
    b.js("document.querySelector('#id_from').value = arguments[0]; document.querySelector('#id_to').value = arguments[1];",
         f'{TODAY.year}-01-01', f'{TODAY.year}-12-31')
    b.click('#region-main form input[type=submit]')
    time.sleep(0.5)
    b.settle()
    print(b.text('#region-main')[:800])
    main_shot(b, 'reports-counts-completions')

    # The Clearinghouse file.
    b.go(SR + '/outsidereports.php?tab=nsc')
    pick_term(b, term)
    print(b.url())
    print(b.text('#region-main')[:1500])
    crop(b, 'reports-clearinghouse', '#page-header', table(b, 0) + ' tbody tr:nth-child(6)', pad=0, width=885)
    b.close()


if __name__ == '__main__':
    main()
