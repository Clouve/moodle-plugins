#!/usr/bin/env python3
"""Student Records: course offerings: add a section, copy a term, read a roster, get final grades, as the Registrar."""
# Snapshot: seed
import os
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import SR, Browser as WD, crop, errors, main_shot, pick, submit, tick  # noqa: E402


def open_term(b, term):
    """Filter the list by a term with the page's own form."""
    b.go(SR + '/offerings.php')
    b.select('#offterm', term)
    b.click('.sr-filter button[type=submit]')
    time.sleep(0.4)
    b.settle()


def open_section(b, code, section='01'):
    """Open a section from the list on the screen."""
    href = b.js('''for (const row of document.querySelectorAll('#region-main table tbody tr')) {
                       if (row.cells[0].innerText.startsWith(arguments[0]) && row.cells[1].innerText.trim() === arguments[1]) {
                           return row.querySelector('a').href;
                       }
                   }''', code, section)
    b.go(href)


def main():
    b = WD()
    b.login('rosa')

    b.go(SR + '/staff.php')
    b.click_text('Course offerings', 'a')
    open_term(b, b.js("return document.querySelector('#offterm').options[4].text"))
    current = b.js("return document.querySelector('#offterm').selectedOptions[0].text")
    print(current)
    main_shot(b, 'offerings-list')

    # Next term: a section with mistakes first.
    following = b.js("return document.querySelector('#offterm').options[5].text")
    open_term(b, following)
    b.click_text('Add section', 'a')
    print(b.js("return document.querySelector('#id_termid').selectedOptions[0].text"))
    print(b.js("return [...document.querySelector('#id_moodlecourseid').options].map(o=>o.text).slice(0,12)"))
    pick(b, 'courseid', 'ACC202')
    b.select('#id_facultyid', 'Pia Lindgren')
    b.fill('#id_capacity', '0')
    b.fill('#id_starttime', '2pm')
    b.fill('#id_endtime', '13:00')
    submit(b)
    print('errors:', errors(b))
    crop(b, 'offerings-add-errors', '#fitem_id_facultyid', '#fitem_id_capacity')

    # The same section, right this time.
    open_term(b, following)
    b.click_text('Add section', 'a')
    pick(b, 'courseid', 'ACC202')
    b.select('#id_facultyid', 'Dr. Tomas Silva')
    b.fill('#id_capacity', '24')
    tick(b, '#id_day_mon')
    tick(b, '#id_day_wed')
    b.fill('#id_starttime', '14:30')
    b.fill('#id_endtime', '15:45')
    b.fill('#id_room', 'Birch Hall 110')
    b.fill('#id_notes', 'Second half of the accounting track')
    main_shot(b, 'offerings-add')
    submit(b)
    print(b.url())

    # A section with a waitlist, and its roster.
    open_term(b, following)
    open_section(b, 'COM150')
    main_shot(b, 'offerings-section')

    # Final grades from the gradebook, for a section of the running term.
    open_term(b, current)
    open_section(b, 'ACC201')
    print(b.text('#region-main')[:900])
    b.click_text('Get final grades from Moodle', 'button, input[type=submit]')
    time.sleep(1)
    b.settle()
    print(b.text('#region-main')[:1500])
    main_shot(b, 'offerings-moodle-grades')

    # Copy the sections of the term before.
    open_term(b, following)
    b.click_text('Copy from last term', 'a')
    main_shot(b, 'offerings-copy-confirm')
    b.click_text('Continue', 'button, input[type=submit], a')
    time.sleep(1)
    b.settle()
    print(b.text('#region-main')[:400])
    main_shot(b, 'offerings-copied')

    # A teacher who is turned off: the form has no such option, and saving takes the teacher off the section.
    b.go(SR + '/faculty.php')
    b.click_text('Pia Lindgren', '#region-main a')
    b.click_text('Edit record', 'a')
    tick(b, '#id_active', False)
    submit(b)
    open_term(b, following)
    open_section(b, 'PHL101')
    print(b.text('#region-main table')[:120])
    b.click_text('Edit offering', 'a')
    print(b.js("return document.querySelector('#id_facultyid').selectedOptions[0].text"))
    submit(b)
    print(b.url(), errors(b))
    print(b.text('#region-main table')[:120])
    b.close()


if __name__ == '__main__':
    main()
