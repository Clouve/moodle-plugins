#!/usr/bin/env python3
"""Student Records: the grading scale, marks, levels and pass rules, as the Registrar."""
# Snapshot: seed
import os
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import SR, Browser as WD, crop, errors, main_shot, submit, table, tick  # noqa: E402


def main():
    b = WD()
    b.login('rosa')
    b.go(SR + '/staff.php')
    b.click_text('Grading', 'a')
    main_shot(b, 'grading-page')

    # A mark: no grade points, not in the GPA. A row that counts in the GPA needs grade points.
    b.click_text('Add grade or mark', 'a')
    b.fill('#id_letter', 'AU')
    b.fill('#id_description', 'Audit. Attended, no hours and no grade points.')
    submit(b)
    print('errors:', errors(b))
    b.go(SR + '/grading.php?what=grade&id=0')
    b.fill('#id_letter', 'AU')
    b.fill('#id_description', 'Audit. Attended, no hours and no grade points.')
    tick(b, '#id_ingpa', False)
    main_shot(b, 'grading-add-mark')
    submit(b)
    print(b.url())

    # A row that overlaps another: the page warns.
    b.click_text('Add grade or mark', 'a')
    b.fill('#id_letter', 'A+')
    b.fill('#id_minpct', '98')
    b.fill('#id_maxpct', '100')
    b.fill('#id_points', '4')
    submit(b)
    print(b.text('#region-main')[:900])
    crop(b, 'grading-scale-warning', '#region-main .alert-warning')

    # Fix that row: the page has no way to delete it.
    b.js('''for (const row of document.querySelectorAll('#region-main table tbody tr')) {
                if (row.cells[0].innerText.trim() === 'A') { row.querySelector('a').click(); }
            }''')
    b.settle()
    b.fill('#id_maxpct', '97')
    submit(b)
    print([a for a in b.text('#region-main').split('\n') if 'overlap' in a or 'Gap' in a])

    # A level and its rule.
    b.click_text('Add level', 'a')
    b.fill('#id_name', 'Doctoral')
    b.fill('#id_description', 'Doctoral programs')
    main_shot(b, 'grading-add-level')
    submit(b)
    crop(b, 'grading-level-no-rule', table(b, 1))
    b.click_text('Add pass rule', 'a')
    b.select('#id_levelid', 'Doctoral')
    b.select('#id_programid', 'AS-BUS - Business Administration')
    submit(b)
    print('errors:', errors(b))
    b.go(SR + '/grading.php?what=minpass&id=0')
    b.select('#id_levelid', 'Doctoral')
    b.select('#id_minletter', 'B')
    b.fill('#id_note', 'Doctoral courses need a B')
    main_shot(b, 'grading-add-rule')
    submit(b)

    # A rule for one course of a program.
    b.click_text('Add pass rule', 'a')
    b.select('#id_programid', 'AS-BUS - Business Administration')
    b.fill('#id_coursecode', 'ACC 999')
    b.fill('#id_minpct', '75')
    submit(b)
    print('errors:', errors(b))
    b.fill('#id_coursecode', 'ACC 201')
    submit(b)
    print(b.text('#region-main')[-1200:])
    crop(b, 'grading-rules', table(b, 2), pad=0)
    b.close()


if __name__ == '__main__':
    main()
