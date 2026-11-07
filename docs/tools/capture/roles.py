#!/usr/bin/env python3
"""Roles and permissions: a site administrator defines the roles the plugins don't make, and each role's menu is checked."""
# Snapshot: seed
import base64
import os
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from shots import ADMINPASS, USERDOCS, click_js, clip, start, submit, top  # noqa: E402

IMAGES = os.path.join(USERDOCS, 'images')
SRC = 'local/studentrecords:'
ATC = 'local/academictranscript:'
MENU = '#theme_boost-drawers-courseindex .drawercontent'
SR = '/local/studentrecords'
AT = '/local/academictranscript'

# The roles of the page, with the short name they get here. The seed has its own Registrar, Admissions and approver roles.
ROLES = {
    'records': ('Records office', 'recordsoffice', 'Registrar staff: records, offerings, terms, grades and transcripts.', 'june', [
        SRC + cap for cap in ('managestudents', 'viewstudents', 'viewdemographics', 'configure', 'import', 'managefaculty',
                              'viewfaculty', 'approvegradechange', 'viewlog')
    ] + [ATC + cap for cap in ('issueofficial', 'viewissued', 'approvechanges', 'managerecords', 'configure')] + [
        'moodle/user:viewalldetails', 'moodle/site:configview']),
    'admissions': ('Admissions office', 'admissionsoffice', 'Admissions staff: applicants and decisions.', 'owen', [
        SRC + cap for cap in ('managestudents', 'viewstudents', 'viewdemographics')] + ['moodle/user:viewalldetails']),
    'readonly': ('Records reader', 'recordsreader', 'Reads every student file, and changes nothing.', 'paul', [SRC + 'viewstudents']),
    'auditor': ('Auditor', 'auditor', 'Reads the audit log.', 'ruth', [SRC + 'viewlog']),
}
PAGES = [SR + '/index.php?view=staff', SR + '/students.php', SR + '/admissions.php', SR + '/offerings.php', SR + '/terms.php',
         SR + '/programs.php', SR + '/courses.php', SR + '/grading.php', SR + '/checklist.php', SR + '/saprules.php',
         SR + '/standing.php', SR + '/gradechange.php', SR + '/graduation.php', SR + '/reports.php', SR + '/outsidereports.php',
         SR + '/faculty.php', SR + '/roster.php', SR + '/import.php', SR + '/log.php', SR + '/messages.php', SR + '/setup.php',
         SR + '/billing.php', SR + '/billingsetup.php', SR + '/refunds.php', SR + '/aid.php', AT + '/requests.php',
         AT + '/students.php', AT + '/issued.php', AT + '/log.php', AT + '/approvals.php', AT + '/grading.php', AT + '/terms.php',
         AT + '/setup.php', '/admin/search.php', '/admin/settings.php?section=local_academictranscript']


def img(name):
    return os.path.join(IMAGES, name + '.png')


def tick(b, css):
    b.js('const box = document.querySelector(arguments[0]); if (!box.checked) { box.click(); }', css)


def define(b, name, short, description, capabilities, picture=False):
    """Define a role with no archetype, for the system level, as the page says."""
    b.go('/admin/roles/manage.php')
    b.click_text('Add a new role', 'a, button, input[type=submit]')
    submit(b, '#id_submitbutton')
    b.fill('#shortname', short)
    b.fill('#name', name)
    b.fill('#description', description)
    tick(b, '#cl10')
    for capability in capabilities:
        tick(b, f'input[type=checkbox][name="{capability}"]')
    if picture:
        # The Filter box shows only what you type. Tick boxes stay ticked when the list is filtered.
        b.fill('#defineroletablecapabilitysearch', 'studentrecords')
        time.sleep(1)
        clip(b, img('role-capabilities-filter'), '.capabilitysearchui', 'tr.rolecap:has(input[name="local/studentrecords:viewstudents"])',
             pad=4, width=840)
    submit(b, 'input[name=savechanges]')


def assign(b, role, person):
    """Give a role to a person at system level."""
    b.go('/admin/roles/assign.php?contextid=1')
    b.click_text(role, '#region-main a')
    b.js('''const select = document.getElementById('addselect');
            for (const option of select.options) { option.selected = option.text.includes(arguments[0]); }
            select.dispatchEvent(new Event('change', {bubbles: true}));''', person)
    time.sleep(0.4)
    click_js(b, '#add')
    time.sleep(1)
    b.settle()


def report(b, who):
    """Print what a person's menu holds and which pages open, to check the page against the site."""
    print('===', who)
    print(b.js('''return [...document.querySelectorAll('.primary-navigation li')].map(e => (e.innerText || '').trim())
                     .filter(t => t && t !== 'More');'''))
    for page in PAGES:
        b.go(page)
        text = b.js("return (document.querySelector('#region-main') || document.body).innerText.slice(0, 200);")
        denied = any(words in text for words in ('Access denied', "you do not currently have permissions", 'Sorry, but'))
        print('  ', 'NO ' if denied else 'yes', page)


def menu(b, person, name):
    """Save a person's Student Records menu, from their home page."""
    b.login(person)
    b.go(SR + '/index.php')
    print(person, b.url())
    b.metrics(1500)
    time.sleep(0.6)
    box = b.js('''const first = document.querySelector('#theme_boost-drawers-courseindex .drawerheader').getBoundingClientRect();
                  const last = document.querySelector('#theme_boost-drawers-courseindex .sr-nav').getBoundingClientRect();
                  return [first.top, last.bottom];''')
    data = b.cdp('Page.captureScreenshot', {'format': 'png', 'clip': {
        'x': 55, 'y': box[0], 'width': 285, 'height': box[1] - box[0] + 8, 'scale': 1}})['data']
    with open(img(name), 'wb') as out:
        out.write(base64.b64decode(data))
    b.metrics(b.height)
    b.logout()


def main():
    names = {'june': 'June Adler', 'owen': 'Owen Pratt', 'paul': 'Paul Greer', 'ruth': 'Ruth Keller'}
    b = start()
    try:
        b.login('admin', ADMINPASS)
        for key, (name, short, description, person, capabilities) in ROLES.items():
            define(b, name, short, description, capabilities, picture=key == 'records')
            assign(b, name, names[person])

        # Moodle's own check of what a person holds.
        b.go('/admin/roles/check.php?contextid=1')
        b.js("""const select = document.querySelector('#reportuser');
                for (const option of select.options) { option.selected = option.text.includes('Owen Pratt'); }""")
        b.click_text('Show this user\'s permissions', 'button, input[type=submit]')
        print(b.url())
        print(b.text('#region-main')[:500])
        top(b, img('role-check-permissions'), 700)
        b.logout()

        for person in names:
            b.login(person)
            b.go(SR + '/index.php')
            print(person, 'lands on', b.url(), b.js("return [...document.querySelectorAll('a.sr-nav-link')].map(a => a.innerText.trim());"))
            report(b, person)
            b.logout()

        # The way to Academic Transcript's pages.
        b.login('june')
        b.go('/admin/search.php#linkmodules')
        time.sleep(1)
        print(b.text('#region-main')[:800])
        clip(b, img('role-site-administration'), '#linkmodules', pad=4)
        b.logout()

        menu(b, 'june', 'menu-registrar')
        menu(b, 'owen', 'menu-admissions')
        menu(b, 'omar', 'menu-approver')
        menu(b, 'felix', 'menu-finance')
        menu(b, 'gwen', 'menu-financial-aid')
        menu(b, 'irene', 'menu-teacher')
        menu(b, 'ben', 'menu-student')
    finally:
        b.close()


if __name__ == '__main__':
    main()
