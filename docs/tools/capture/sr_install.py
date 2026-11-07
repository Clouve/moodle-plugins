#!/usr/bin/env python3
"""Student Records: after the install, as a site administrator: a role for the registrar, the start page, the tasks."""
# Snapshot: fresh
import os
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import ADMINPASS, SR, Browser as WD, crop, img, submit, theme, tick  # noqa: E402

CAPS = ['configure', 'import', 'managestudents', 'viewstudents', 'viewdemographics', 'managefaculty', 'viewfaculty',
        'approvegradechange', 'viewlog']


def add_user(b):
    """The account the role goes to."""
    b.go('/user/editadvanced.php?id=-1')
    b.fill('#id_username', 'rosa')
    b.js("document.querySelector('[data-passwordunmask=\"edit\"]').click();")
    time.sleep(0.5)
    b.fill('#id_newpassword', 'Pass@1234')
    b.fill('#id_firstname', 'Rosa')
    b.fill('#id_lastname', 'Delgado')
    b.fill('#id_email', 'rosa@example.com')
    submit(b)


def add_role(b):
    """Define a Registrar role at system level, with the capabilities of Student Records it needs."""
    b.go('/admin/roles/manage.php')
    b.click_text('Add a new role', 'a, button, input[type=submit]')
    submit(b)
    b.fill('#shortname', 'srregistrar')
    b.fill('#name', 'Registrar')
    b.fill('#description', 'Records staff: student records, offerings and terms.')
    tick(b, '#cl10')
    print(b.js('''return [...document.querySelectorAll('#rolesform label, #rolesform .fitem .col-form-label, form label')]
        .map(l => l.innerText.trim()).filter(t => t).slice(0, 14)'''))
    print(b.js("return document.querySelector('input[name=savechanges]').value"))
    for cap in CAPS:
        tick(b, f'input[type=checkbox][name="local/studentrecords:{cap}"]')
    tick(b, 'input[type=checkbox][name="moodle/user:viewalldetails"]')
    print(b.js('''return [...document.querySelectorAll('tr.rolecap input[type=checkbox]')]
        .filter(e => e.name.startsWith('local/studentrecords:')).map(e => e.name + (e.checked ? ' on' : ''))'''))
    first = 'tr.rolecap:has(input[type=checkbox][name="local/studentrecords:approvegradechange"])'
    last = 'tr.rolecap:has(input[type=checkbox][name="local/studentrecords:viewown"])'
    crop(b, 'install-role-capabilities', first, last, pad=0, width=900)
    submit(b, 'input[name=savechanges]')
    print('after role', b.url())


def assign_role(b):
    """Give the role to a person at system level."""
    b.go('/admin/roles/assign.php?contextid=1')
    b.click_text('Registrar', '#region-main a')
    print(b.url())
    b.js('''const select = document.getElementById('addselect');
            for (const option of select.options) { option.selected = option.text.includes('Rosa Delgado'); }
            select.dispatchEvent(new Event('change', {bubbles: true}));''')
    time.sleep(0.4)
    crop(b, 'install-role-assign', '#assignform')
    b.click('#add')
    time.sleep(0.6)
    b.settle()


def main():
    theme()
    b = WD()
    b.login('admin', ADMINPASS)
    add_user(b)
    add_role(b)
    assign_role(b)

    # The start page.
    b.go('/admin/settings.php?section=navigation')
    b.select('#id_s__defaulthomepage', 'Student Records')
    crop(b, 'install-start-page', '#admin-defaulthomepage', pad=0)
    submit(b, '#adminsettings button[type=submit]')


    # Moodle's list of scheduled tasks.
    b.go('/admin/tool/task/scheduledtasks.php')
    b.js('''const rows = [...document.querySelectorAll('#region-main table tbody tr')]
                .filter(r => r.innerText.includes('local_studentrecords'));
            rows[0].id = 'shot-first';
            rows[rows.length - 1].id = 'shot-last';''')
    crop(b, 'install-tasks', '#shot-first', '#shot-last', pad=0, width=1120)
    b.close()

    # What the person sees, on their next sign-in.
    b = WD()
    b.login('rosa')
    print(b.url())
    b.shot(img('install-first-sign-in'), full=False)
    print(b.text('#region-main')[:600])
    for page in ('terms', 'offerings', 'grading', 'faculty', 'import', 'reports', 'setup', 'messages'):
        b.go(f'{SR}/{page}.php')
        print(page, b.has('#region-main .alert-danger, .errorbox'), b.js('return document.title'))
    b.close()


if __name__ == '__main__':
    main()
