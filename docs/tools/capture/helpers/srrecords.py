"""Helpers for the capture scripts of the records and teaching pages of Student Records."""
import atexit
import os
import re
import signal
import sys
import time
from urllib.parse import quote

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from srshots import ADMINPASS, SR, WD, click_js, crop, img, set_date, site, submit, tick, tool, upload  # noqa: E402,F401


def main_shot(b, name):
    """Save the page's title and its main region. It leaves out the breadcrumbs, which the closed menu's button covers."""
    crop(b, name, '#page-header h1', '#region-main', pad=6)


def person(name, **options):
    """Open a browser and sign in as this person. Chrome sessions are shared, so a script that stops closes its own."""
    b = WD(**options)
    atexit.register(leave, b)
    if name == 'admin':
        b.login('admin', ADMINPASS)
    else:
        b.login(name)
    return b


def leave(b):
    """Close a session the script left open. Closing it twice does no harm."""
    try:
        b.close()
    except RuntimeError:
        pass


signal.signal(signal.SIGTERM, lambda *_: sys.exit(143))


def file_id(b, name):
    """Find a student with the search box of the list of student records, and return the id of the file."""
    b.go(f'{SR}/students.php?search={quote(name)}')
    href = b.js('''const link = document.querySelector('#region-main table a[href*="student.php?id="]');
                   return link ? link.href : null;''')
    if not href:
        raise RuntimeError(f'no student found for {name!r}')
    return re.search(r'[?&]id=(\d+)', href).group(1)


def open_file(b, name, tab=None):
    """Open a student's file, on one of its tabs. Return the id of the file."""
    sid = file_id(b, name)
    b.go(f'{SR}/student.php?id={sid}' + (f'&tab={tab}' if tab else ''))
    return sid


def expand_all(b):
    """Open every section of a Moodle form."""
    b.js('''for (const link of document.querySelectorAll('a.collapsed[data-bs-toggle="collapse"], a.fheader.collapsed')) {
                link.click();
            }''')
    time.sleep(0.7)


def choose_user(b, css, text, wait=8):
    """Type in an autocomplete, wait for it to narrow its list, and take the first match with the keyboard."""
    b.fill(css, text)
    end = time.time() + wait
    time.sleep(1)
    while time.time() < end:
        found = b.js('''const list = document.querySelector('.form-autocomplete-suggestions');
                        return !!list && Array.from(list.querySelectorAll('[role=option]'))
                            .some(item => item.offsetParent !== null && item.innerText.includes(arguments[0]));''', text)
        if found:
            b.send(css, '\ue015')
            time.sleep(0.3)
            b.send(css, '\ue007')
            time.sleep(0.5)
            return
        time.sleep(0.3)
    raise RuntimeError(f'no match for {text!r} in {css}')


def flash(b):
    """The text of the notices at the top of the page."""
    return b.js('''return Array.from(document.querySelectorAll('.alert')).map(a => a.innerText.trim()).join(' | ');''')


def clear_notices(b):
    """Take the Before releasing information notice off the page, as its close button does."""
    b.js('''for (const alert of document.querySelectorAll('#region-main .alert')) {
                const close = alert.querySelector('.btn-close, .close');
                if (close && alert.innerText.includes('Before releasing information')) { close.click(); }
            }''')
    time.sleep(0.4)


def close_menu(b):
    """Close the Student Records menu on the left, so that the page has room for its tables. It stays closed for this person."""
    b.js('''const button = document.querySelector('button.drawertoggle[data-action=closedrawer][aria-label*="Student Records"]');
              if (button) { button.click(); }''')
    time.sleep(0.8)


def open_course(b, fullname):
    """Open a course with Moodle's course search, by its full name."""
    b.go('/course/search.php?search=' + quote(fullname.split(' ')[0]))
    b.click_text(fullname, 'a')


def course_link(b, label):
    """Follow a link of the course's second menu, where More may hide it."""
    href = b.js('''for (const link of document.querySelectorAll('.secondary-navigation a')) {
                         if (link.textContent.trim() === arguments[0]) { return link.href; }
                     }
                     return null;''', label)
    if not href:
        raise RuntimeError(f'no {label!r} in the course menu at {b.url()}')
    b.go(href)


def terms(b):
    """The term names of the site, oldest first, from the Admit term filter of the list of student records."""
    b.go(f'{SR}/students.php')
    return b.js('''return Array.from(document.querySelectorAll('#srtermid option')).slice(1).map(o => o.text.trim());''')


def reg_link(b, term, code, label):
    """Follow a link of one course on the Registration and grades tab."""
    href = b.js('''for (const table of document.querySelectorAll('#region-main table')) {
                       const caption = table.querySelector('caption');
                       if (!caption || caption.innerText.trim() !== arguments[0]) { continue; }
                       for (const row of table.querySelectorAll('tbody tr')) {
                           if (row.cells[0].innerText.trim() !== arguments[1]) { continue; }
                           for (const link of row.querySelectorAll('a')) {
                               if (link.innerText.trim() === arguments[2]) { return link.href; }
                           }
                       }
                   }
                   return null;''', term, code, label)
    if not href:
        raise RuntimeError(f'no {label!r} for {code} in {term} at {b.url()}')
    b.go(href)


def press(b, label):
    """Press a small form button by its accessible name, such as "Add ENG101 01"."""
    b.js('''document.querySelector(`button[aria-label="${arguments[0]}"]`).click();''', label)
    time.sleep(0.6)
    b.settle()


def notifications_shot(b, name):
    """Open Moodle's notifications page and save the list of subjects."""
    b.go('/message/output/popup/notifications.php')
    time.sleep(1.5)
    crop(b, name, '[data-region="control-area"] [data-region="content"]', pad=2)


def open_offering(b, term, code, section='01'):
    """Open one section on Course offerings."""
    b.go(f'{SR}/offerings.php')
    termid = b.js('''for (const option of document.querySelectorAll('#offterm option')) {
                         if (option.text.trim() === arguments[0]) { return option.value; }
                     }
                     return null;''', term)
    b.go(f'{SR}/offerings.php?termid={termid}')
    href = b.js('''for (const row of document.querySelectorAll('#region-main table tbody tr')) {
                       const link = row.cells[0].querySelector('a');
                       if (link && link.innerText.trim().startsWith(arguments[0]) && row.cells[1].innerText.trim() === arguments[1]) {
                           return link.href;
                       }
                   }
                   return null;''', code, section)
    if not href:
        raise RuntimeError(f'no section {code} {section} in {term}')
    b.go(href)


def term_table(b, term):
    """Give the table of one term on the Registration and grades tab an id, so that a crop can find it. Return its CSS."""
    b.js('''for (const table of document.querySelectorAll('#region-main table')) {
                const caption = table.querySelector('caption');
                if (caption && caption.innerText.trim() === arguments[0]) { table.id = 'termtable'; }
            }''', term)
    return '#termtable'


def mark_row(b, css, text, marker='markedrow'):
    """Give the first table row under this CSS that holds this text an id, so that a crop can stop there. Return its CSS."""
    b.js('''for (const row of document.querySelectorAll(arguments[0] + ' tbody tr')) {
                if (row.innerText.includes(arguments[1])) { row.id = arguments[2]; return; }
            }''', css, text, marker)
    return '#' + marker


def switch(b, name):
    """Sign out and sign in as another person, in the same browser. Chrome sessions are shared, so a script keeps its one."""
    b.logout()
    if name == 'admin':
        b.login('admin', ADMINPASS)
    else:
        b.login(name)


def phone(b, on=True):
    """Make the browser the size of a phone, or a computer again."""
    b.width, b.height, b.mobile = (390, 844, True) if on else (1280, 900, False)
    b.metrics(b.height)
