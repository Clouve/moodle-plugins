#!/usr/bin/env python3
"""Student Records: programs, their course lists and the course catalog, as the Registrar."""
# Snapshot: seed
import os
import sys
import tempfile
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import SR, Browser as WD, crop, errors, main_shot, pick, submit, tick, upload  # noqa: E402


def main():
    b = WD()
    b.login('rosa')

    # The course catalog.
    b.go(SR + '/staff.php')
    b.click_text('Course catalog', 'a')
    main_shot(b, 'courses-list')
    b.click_text('Add course', 'a')
    b.fill('#id_code', 'mkt 310')
    b.fill('#id_title', 'Consumer Behavior')
    b.fill('#id_hours', '3')
    b.select('#id_levelid', 'Undergraduate')
    b.fill('#id_prereqtext', 'MKT210')
    main_shot(b, 'courses-add')
    submit(b)
    print(b.url())
    print(b.text('#region-main table').split('\n')[12:18])

    # What the course page says about a typed prerequisite: no link.
    b.go(SR + '/courses.php?search=MKT310')
    b.click_text('MKT310', '#region-main a')
    print(b.text('#region-main')[-200:])
    crop(b, 'courses-no-link', '#region-main h4', '#region-main h4 ~ p:last-of-type', pad=0)

    # A program.
    b.go(SR + '/staff.php')
    b.click_text('Programs', 'a')
    main_shot(b, 'programs-list')
    b.click_text('Add program', 'a')
    b.fill('#id_code', 'AS-MKT')
    b.fill('#id_name', 'Marketing')
    b.fill('#id_school', 'School of Business')
    b.fill('#id_credential', 'Associate of Science')
    b.select('#id_credentialtype', 'Associate')
    b.select('#id_isdegree', 'Yes')
    b.select('#id_levelid', 'Undergraduate')
    b.fill('#id_totalhours', '15')
    b.fill('#id_trackhours', '3')
    b.fill('#id_mingpa', '2.0')
    b.fill('#id_residenthours', '6')
    b.select('#id_deliverymode', 'Hybrid (online and in person)')
    b.fill('#id_location', 'Main Campus')
    b.fill('#id_delivery', 'Day and evening classes on campus')
    b.fill('#id_cipcode', '52.1401')
    b.fill('#id_duration', '2 years')
    b.fill('#id_maxtime', '3 years')
    b.fill('#id_graderules', 'C or better in the core courses')
    main_shot(b, 'programs-add')
    submit(b)
    print(b.url())

    # Its course list: required courses, two tracks, electives.
    for course, group, kind in (('MKT210', 'Core (9 credits)', 'Required'), ('BUS101', 'Core (9 credits)', 'Required'),
                                ('ENG101', 'Core (9 credits)', 'Required'), ('MKT220', 'Digital Track', 'Track option'),
                                ('MKT310', 'Consumer Track', 'Track option'), ('ACC201', 'Electives (3 credits)', 'Elective'),
                                ('PSY101', 'Electives (3 credits)', 'Elective')):
        pick(b, 'courseid', course)
        b.fill('#id_groupname', group)
        b.select('#id_requirement', kind)
        if course == 'MKT210':
            crop(b, 'programs-add-course', '#fitem_id_courseid', '#fgroup_id_buttonar, #fitem_id_submitbutton')
        submit(b)
    print(b.text('#region-main')[-900:])
    main_shot(b, 'programs-outline')

    # The seed's program with its list.
    b.go(SR + '/programs.php')
    b.click_text('AS-BUS', '#region-main a')
    print(b.text('#region-main')[:1800])

    # Only the import makes a prerequisite link.
    folder = tempfile.mkdtemp(prefix='sr-programs-')
    path = os.path.join(folder, 'mkt310-prerequisite.csv')
    with open(path, 'w', encoding='utf-8') as out:
        out.write('Course code,Title,Credits,Credit type,Level,Prerequisites\n'
                  'MKT 310,Consumer Behavior,3,Semester credit,Undergraduate,MKT 210\n')
    b.go(SR + '/staff.php')
    b.click_text('Import from spreadsheet', 'a')
    upload(b, 'input[name=importfilechoose]', path)
    submit(b)
    print(b.text('#region-main table'))
    b.click_text('Courses', '#region-main a')
    print(b.text('#region-main h3 + form'))
    # Only the course: the Levels item of the same file has no description, and approving it would clear the level's.
    tick(b, '#region-main input[type=checkbox][name="ids[]"]')
    b.click_text('Approve selected', 'button, input[type=submit]')
    time.sleep(1)
    b.settle()
    print(b.text('#user-notifications'))
    b.go(SR + '/courses.php?search=MKT310')
    b.click_text('MKT310', '#region-main a')
    print(b.text('#region-main')[-200:])
    crop(b, 'courses-linked', '#region-main h4', '#region-main h4 ~ p:last-of-type', pad=0)

    # A program turned off, and a student of that program saved.
    b.go(SR + '/programs.php')
    b.click_text('CERT-PHL', '#region-main a')
    b.click_text('Edit program', 'a')
    tick(b, '#id_active', False)
    submit(b)
    b.go(SR + '/students.php?search=Dante')
    b.click_text('Dante Hill', '#region-main a')
    print(b.url())
    print([line for line in b.text('#region-main').split('\n') if 'CERT-PHL' in line or 'Phlebotomy' in line][:3])
    b.go(b.url() + '&edit=1')
    print(b.js("return document.querySelector('#id_programid').selectedOptions[0].text"))
    submit(b)
    print(b.url())
    print(errors(b))
    print([line for line in b.text('#region-main').split('\n') if 'CERT-PHL' in line or 'Phlebotomy' in line][:3])
    b.close()


if __name__ == '__main__':
    main()
