"""Helpers for the money and aid capture scripts of Student Records: finding things on a page and cropping to them."""
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.request
from datetime import date, timedelta  # noqa: F401

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from srshots import ADMINPASS, SR, WD, click_js, crop, img, main_shot, set_date, site, submit, tick, tool  # noqa: E402,F401
from wd import HUB  # noqa: E402


def start(**options):
    """Open a browser once the hub has a free slot, so that a busy hub doesn't end the script. WD() gives up after 3 minutes."""
    end = time.time() + 900
    while time.time() < end:
        with urllib.request.urlopen(HUB + '/status', timeout=20) as reply:
            nodes = json.load(reply)['value']['nodes']
        if any(not slot.get('session') for node in nodes for slot in node['slots']):
            break
        time.sleep(5)
    return WD(**options)


def mark(b, name, text, css='h1, h2, h3, h4, h5, legend, caption, summary, th, label, p, li, a, button', nth=0):
    """Give the element that shows this text the attribute data-shot=name, so that crop() can find it."""
    found = b.js('''
        const [name, want, css, nth] = arguments;
        const hits = [...document.querySelectorAll(css)].filter(e => e.offsetParent !== null &&
            (e.innerText || e.value || '').trim().startsWith(want));
        if (!hits[nth]) { return false; }
        hits[nth].setAttribute('data-shot', name);
        return true;''', name, text, css, nth)
    if not found:
        raise RuntimeError(f'no {css} that starts with {text!r} at {b.url()}')
    return f'[data-shot={name}]'


def mark_next(b, name, after, css):
    """Give the first element that matches css after another marked element the attribute data-shot=name."""
    found = b.js('''
        const [name, after, css] = arguments;
        const first = document.querySelector(after);
        for (const e of document.querySelectorAll(css)) {
            if (first.compareDocumentPosition(e) & Node.DOCUMENT_POSITION_FOLLOWING) {
                e.setAttribute('data-shot', name);
                return true;
            }
        }
        return false;''', name, after, css)
    if not found:
        raise RuntimeError(f'no {css} after {after} at {b.url()}')
    return f'[data-shot={name}]'


def mark_row(b, name, text, table='table', last=False):
    """Give the first or the last table row that holds this text the attribute data-shot=name."""
    found = b.js('''
        const [name, want, table, last] = arguments;
        const rows = [...document.querySelectorAll(table + ' tr')].filter(r => r.innerText.includes(want));
        if (!rows.length) { return false; }
        rows[last ? rows.length - 1 : 0].setAttribute('data-shot', name);
        return true;''', name, text, table, last)
    if not found:
        raise RuntimeError(f'no row with {text!r} at {b.url()}')
    return f'[data-shot={name}]'


def pick(b, css, part):
    """Pick the first option of a select whose label holds this text."""
    found = b.js('''
        const select = document.querySelector(arguments[0]);
        for (const option of select.options) {
            if (option.text.includes(arguments[1])) {
                select.value = option.value;
                select.dispatchEvent(new Event('change', {bubbles: true}));
                return option.text;
            }
        }
        return null;''', css, part)
    if found is None:
        raise RuntimeError(f'no option with {part!r} in {css} at {b.url()}')
    time.sleep(0.2)
    return found


def row_link(b, row_text, link_text, table='table'):
    """Click a link in the first table row that holds this text."""
    found = b.js('''
        const [rowtext, linktext, table] = arguments;
        for (const row of document.querySelectorAll(table + ' tr')) {
            if (!row.innerText.includes(rowtext)) { continue; }
            for (const link of row.querySelectorAll('a')) {
                if (link.innerText.trim() === linktext) {
                    link.click();
                    return true;
                }
            }
        }
        return false;''', row_text, link_text, table)
    if not found:
        raise RuntimeError(f'no row with {row_text!r} and a link {link_text!r} at {b.url()}')
    time.sleep(0.5)
    b.settle()


def close_menu(b):
    """Fold the left menu, as a person does for a wide table. Moodle remembers it for that person."""
    b.js('''for (const toggle of document.querySelectorAll('[data-action=closedrawer]')) {
            if ((toggle.getAttribute('aria-label') || '').includes('Student Records menu') && toggle.offsetParent !== null) {
                toggle.click();
            }
        }''')
    time.sleep(1)
    b.settle()


def open_menu(b):
    """Open the left menu again."""
    b.js("document.querySelector('.drawer-left-toggle button').click();")
    time.sleep(1)
    b.settle()


def press(b, label):
    """Press the submit button that says this, on a page with more than one form."""
    b.js("""[...document.querySelectorAll('input[type=submit], button[type=submit]')]
        .find(e => (e.value || e.innerText).trim() === arguments[0]).click();""", label)
    time.sleep(0.6)
    b.settle()


def term_dates():
    """The first and last day of each term, by name: a person knows these from the calendar."""
    out = site("""php -r 'define("CLI_SCRIPT", true); require("config.php");
        foreach ($DB->get_records("local_studentrecords_term") as $t) {
            echo $t->name, "|", \\local_studentrecords\\local\\catalog::time_to_date((int)$t->startdate), "|",
                \\local_studentrecords\\local\\catalog::time_to_date((int)$t->enddate), "\\n";
        }'""", capture=True)
    return {line.split('|')[0]: line.split('|')[1:] for line in out.strip().split('\n')}


def term_names():
    """The running term and the next one. Their names follow from the day the seed ran."""
    dates = term_dates()
    today = date.today().isoformat()
    ordered = sorted(dates, key=lambda name: dates[name][0])
    running = next(name for name in ordered if dates[name][0] <= today <= dates[name][1])
    return running, ordered[ordered.index(running) + 1]


def account(b, name):
    """Open a student's account the way Finance does: search the list, then select the name."""
    b.go(SR + '/billing.php?q=' + name.split()[0])
    b.click_text(name, 'table a')


def aid_student(b, name):
    """Open a student's aid page the way Financial Aid does: search the list, then select the name."""
    b.go(SR + '/aid.php?report=students&q=' + name.split()[0])
    b.click_text(name, 'table a')


def day(offset=0):
    """A date some days from today, as year-month-day."""
    return (date.today() + timedelta(days=offset)).isoformat()


def shows(b, text, css='#region-main'):
    """Stop the script when a page doesn't say what the guide says it does."""
    body = b.text(css)
    if text not in body:
        raise RuntimeError(f'{text!r} is not on {b.url()}: {body[:400]}')


def pdf_shot(b, url, name, height=None, dpi=90):
    """Save a real PDF from the site in a scratch folder and show its first page, cut off at a height in pixels."""
    with tempfile.TemporaryDirectory() as folder:
        pdf = os.path.join(folder, 'file.pdf')
        b.fetch(url, pdf)
        command = ['pdftoppm', '-r', str(dpi), '-png', '-singlefile', '-f', '1', '-l', '1']
        if height:
            command += ['-x', '0', '-y', '0', '-W', str(int(8.5 * dpi)), '-H', str(height)]
        subprocess.run(command + [pdf, img(name)[:-4]], check=True)
        return subprocess.run(['pdftotext', pdf, '-'], check=True, stdout=subprocess.PIPE, text=True).stdout


def pdf_text(b, url):
    """Read the text of a real PDF from the site."""
    with tempfile.TemporaryDirectory() as folder:
        pdf = os.path.join(folder, 'file.pdf')
        b.fetch(url, pdf)
        return subprocess.run(['pdftotext', pdf, '-'], check=True, stdout=subprocess.PIPE, text=True).stdout


def billing_task():
    """Run the daily task of billing, which Moodle's cron runs at 06:15."""
    site('php admin/cli/scheduled_task.php --execute="\\\\local_studentrecords\\\\task\\\\billing_daily" >/dev/null')
