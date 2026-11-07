#!/usr/bin/env python3
"""Student Records: terms and the calendar settings, as the Registrar."""
# Snapshot: seed
import datetime
import os
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import SR, Browser as WD, crop, errors, main_shot, row, set_date, submit  # noqa: E402

TODAY = datetime.date.today()


def main():
    b = WD()
    b.login('rosa')
    b.go(SR + '/staff.php')
    b.click_text('Terms', 'a')
    main_shot(b, 'terms-list')

    # Add a term, with mistakes first. It runs next summer, the year after the running term.
    year = TODAY.year + 1
    name = f'Summer {year}'
    b.click_text('Add term', 'a')
    b.fill('#id_name', 'Fall ' + str(TODAY.year))
    set_date(b, 'startdate', year, 6, 7)
    set_date(b, 'enddate', year, 5, 30)
    set_date(b, 'adddropend', year, 7, 30)
    set_date(b, 'gradefreeze', year, 5, 1)
    submit(b)
    print('errors:', errors(b))
    crop(b, 'terms-add-errors', '#fitem_id_name', '#fitem_id_gradefreeze')

    b.go(SR + '/terms.php?edit=0')
    b.fill('#id_name', name)
    set_date(b, 'startdate', year, 6, 7)
    set_date(b, 'enddate', year, 7, 30)
    set_date(b, 'adddropstart', year, 4, 26)
    set_date(b, 'adddropend', year, 6, 14)
    set_date(b, 'examstart', year, 7, 26)
    set_date(b, 'examend', year, 7, 30)
    set_date(b, 'gradefreeze', year, 8, 13)
    b.fill('#id_notes', 'Independence Day: no classes on July 5.')
    main_shot(b, 'terms-add')
    submit(b)
    print(b.url())
    print(b.text('#region-main table'))
    crop(b, 'terms-calculated', row(b, name), pad=0)

    # A date you pick stays yours.
    b.js('''for (const row of document.querySelectorAll('#region-main table tbody tr')) {
                if (row.cells[0].innerText.trim() === arguments[0]) {
                    row.querySelector('a').click();
                }
            }''', name)
    b.settle()
    print(b.url())
    set_date(b, 'withdrawby', year, 7, 2)
    submit(b)
    print(b.text('#region-main table'))
    b.go(SR + '/setup.php?step=calendar')
    main_shot(b, 'calendar-settings')
    b.close()


if __name__ == '__main__':
    main()
