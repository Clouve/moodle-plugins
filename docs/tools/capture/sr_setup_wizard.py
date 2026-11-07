#!/usr/bin/env python3
"""Student Records: all 11 steps of the setup wizard on a site with no data, as a site administrator."""
# Snapshot: fresh
import os
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import ADMINPASS, ASSETS, SR, Browser as WD, crop, errors, main_shot, submit, theme, upload  # noqa: E402

ADDRESS = '100 College Way\nElm Hollow, IL 62999'
FERPA = ('Riverside College Annual Notice of Student Rights. You have the right to see your education records, to ask us to '
         'correct them, and to say who else may see them. This notice is sample text for a made-up school.')


def fill(b, values):
    """Fill text boxes by their field name."""
    for name, value in values.items():
        b.fill('#id_' + name, value)


def main():
    theme()
    b = WD()
    b.login('admin', ADMINPASS)

    # Step 1: School. The wizard opens here from the menu.
    b.go(SR + '/staff.php')
    b.click_text('Setup wizard', 'a')
    fill(b, {'schoolname': 'Riverside College', 'othernames': 'RVC', 'legalname': 'Riverside College, Inc.',
             'address': ADDRESS, 'campusaddress': 'Main Campus\n' + ADDRESS, 'state': 'Illinois',
             'website': 'https://www.example.com', 'phone': '(618) 555-0100', 'email': 'registrar@example.com',
             'registrarname': 'Rosa Delgado', 'registrartitle': 'College Registrar',
             'officehours': 'Monday to Friday, 8:30 to 4:30'})
    b.select('#id_timezone', 'America/Chicago')
    upload(b, '.filemanager .fp-btn-add a', os.path.join(ASSETS, 'logo.png'))
    main_shot(b, 'setup-wizard-school')
    submit(b)
    assert 'step=approvals' in b.url(), b.url()

    # Step 2: Approvals.
    fill(b, {'stateagency': 'State Board of Private Colleges (made up)', 'statelicense': 'PC-2041',
             'accreditor': 'Great Rivers Commission on Colleges (made up)', 'ferpanotice': FERPA})
    b.select('#id_fedaid', 'Yes')
    main_shot(b, 'setup-wizard-approvals')
    submit(b)
    assert 'step=calendar' in b.url(), b.url()

    # Step 3: Calendar. The numbers are checked when you save. The step is shot before the wrong numbers go in, so that
    # their error messages don't show beside the right ones.
    b.select('#id_calendartype', 'Semester')
    fill(b, {'yearstart': 'August 1', 'creditunit': 'Semester credit hour', 'withdrawpct': '75', 'minattendance': '80',
             'atriskgrade': '70', 'fulltimehours': '12'})
    main_shot(b, 'setup-wizard-calendar')
    fill(b, {'withdrawpct': '0', 'minattendance': '101', 'atriskgrade': '101', 'fulltimehours': 'twelve'})
    submit(b)
    print('calendar errors:', errors(b))
    crop(b, 'setup-wizard-calendar-errors', '#fitem_id_withdrawpct', '#fitem_id_atriskgrade')
    fill(b, {'withdrawpct': '75', 'minattendance': '80', 'atriskgrade': '70', 'fulltimehours': '12'})
    submit(b)
    assert 'step=grading' in b.url(), b.url()

    # Steps 4 to 6 lead to the pages where these things are kept.
    main_shot(b, 'setup-wizard-grading')
    b.click_text('Continue: Programs')
    main_shot(b, 'setup-wizard-programs')
    b.click_text('Continue: Courses')
    main_shot(b, 'setup-wizard-courses')
    b.click_text('Continue: Admissions')

    # Step 7: Admissions.
    b.select('#id_idauto', 'Yes')
    fill(b, {'idprefix': 'RVC', 'idpadding': '11', 'honors': '3.9 Summa cum laude; 3.7 Magna cum laude; 3.5 Cum laude'})
    submit(b)
    print('admissions errors:', errors(b))
    fill(b, {'idpadding': '4'})
    submit(b)
    assert 'step=billing' in b.url(), b.url()
    b.go(SR + '/setup.php?step=admissions')
    main_shot(b, 'setup-wizard-admissions')

    # Step 8 to 10.
    b.go(SR + '/setup.php?step=billing')
    main_shot(b, 'setup-wizard-billing')
    b.click_text('Continue: Roles and staff')
    main_shot(b, 'setup-wizard-staff')
    b.click_text('Continue: Import')
    main_shot(b, 'setup-wizard-import')
    b.click_text('Continue: Summary')
    main_shot(b, 'setup-wizard-summary')

    # OPEID shows on the School step once federal aid is saved as on.
    b.go(SR + '/setup.php?step=school')
    crop(b, 'setup-wizard-opeid', '#fitem_id_timezone', '#fitem_id_opeid')

    # A level with no pass rule: the Summary warns.
    b.go(SR + '/grading.php?what=level&id=0')
    fill(b, {'name': 'Undergraduate', 'description': 'Associate and bachelor programs'})
    submit(b)
    b.go(SR + '/setup.php?step=summary')
    crop(b, 'setup-wizard-summary-warning', '#region-main .alert', '#region-main .alert + p')
    b.close()


if __name__ == '__main__':
    main()
