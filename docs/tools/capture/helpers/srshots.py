"""Helpers for the Student Records capture scripts: paths, crops, Moodle's file picker, and site commands."""
import atexit
import base64
import io
import os
import subprocess
import sys
import time
import zipfile
from xml.sax.saxutils import escape

sys.dont_write_bytecode = True
TOOLS = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, TOOLS)
from wd import ELEMENT, SITE, WD  # noqa: E402,F401

IMAGES = os.path.join(TOOLS, '..', 'user', 'student-records', 'images')
ASSETS = os.path.join(TOOLS, 'assets')
ADMINPASS = os.environ.get('ADMINPASS', 'Admin@123')
SR = '/local/studentrecords'
MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
OFFICE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
PACKAGE = 'http://schemas.openxmlformats.org/package/2006/relationships'


class Browser(WD):
    """A browser that closes itself when a script stops, so that a crash doesn't keep one of the few Chrome slots."""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.open = True
        atexit.register(self.close)

    def close(self):
        if self.open:
            self.open = False
            super().close()


def img(name):
    """The path of a screenshot of the Student Records guide."""
    return os.path.join(IMAGES, name + '.png')


def site(*command, capture=False):
    """Run a command in the Moodle folder of the site, for what isn't part of a person's job."""
    done = subprocess.run([os.path.join(TOOLS, 'site.sh'), 'sh', *command], check=True,
                          env={**os.environ, 'SITE': str(SITE)}, stdout=subprocess.PIPE, text=True)
    return done.stdout if capture else None


def tool(*command):
    """Run docs/tools/site.sh with this site, for cron and the like."""
    subprocess.run([os.path.join(TOOLS, 'site.sh'), *command], check=True,
                   env={**os.environ, 'SITE': str(SITE)}, stdout=subprocess.DEVNULL)


def theme():
    """Use the Student Records theme. The snapshot fresh still has Moodle's default theme."""
    site('php admin/cli/cfg.php --name=theme --set=studentrecords')


def crop(b, name, first, last=None, pad=8, width=None):
    """Save the part of the page from the top of one element to the bottom of another."""
    b.js('if (document.activeElement) { document.activeElement.blur(); }')
    b.metrics(min(b.page_height() + 8, 8000))
    time.sleep(0.5)
    box = b.js('''
        const a = document.querySelector(arguments[0]).getBoundingClientRect();
        const z = document.querySelector(arguments[1] || arguments[0]).getBoundingClientRect();
        return [Math.min(a.left, z.left), a.top, Math.max(a.right, z.right), z.bottom];''', first, last)
    clip = {'x': max(box[0] - pad, 0), 'y': max(box[1] - pad, 0), 'width': (width or box[2] - box[0] + 2 * pad),
            'height': box[3] - box[1] + 2 * pad, 'scale': 1}
    data = b.cdp('Page.captureScreenshot', {'format': 'png', 'clip': clip})['data']
    os.makedirs(IMAGES, exist_ok=True)
    with open(img(name), 'wb') as out:
        out.write(base64.b64decode(data))
    b.metrics(b.height)


def table(b, index):
    """A selector for the nth table of the main region, counted from 0. Moodle wraps each table in its own box."""
    b.js("document.querySelectorAll('#region-main table')[arguments[0]].id = 'shot-table-' + arguments[0];", index)
    return f'#shot-table-{index}'


def row(b, text, index=0):
    """A selector for the row of the nth table whose first cells show this text."""
    b.js("""const rows = document.querySelectorAll('#region-main table')[arguments[0]].querySelectorAll('tr');
            for (const row of rows) {
                if ([...row.cells].some(cell => cell.innerText.trim() === arguments[1])) {
                    row.id = 'shot-row';
                    break;
                }
            }""", index, text)
    return '#shot-row'


def main_shot(b, name):
    """Save the page's heading and its main region, without the menu."""
    crop(b, name, '#page-header', '#region-main', pad=0)


def click_js(b, css):
    """Click an element that may be under a sticky bar."""
    b.js('document.querySelector(arguments[0]).click();', css)


def submit(b, css='#id_submitbutton'):
    """Press a form's save button and wait for the next page."""
    click_js(b, css)
    time.sleep(0.6)
    b.settle()


def tick(b, css, on=True):
    """Set a tick box, whatever it says now."""
    b.js('''const box = document.querySelector(arguments[0]);
            if (box.checked !== arguments[1]) { box.click(); }''', css, on)


def errors(b):
    """The error messages of a form on the page."""
    return b.js("""return [...document.querySelectorAll('.form-control-feedback.invalid-feedback')]
        .map(e => e.innerText.trim()).filter(t => t);""")


def pick(b, field, label):
    """Choose in one of Moodle's autocomplete boxes, as a person does: type, then select the match."""
    b.fill(f'#fitem_id_{field} input[role=combobox]', label)
    for _ in range(12):
        time.sleep(0.4)
        done = b.js("""const box = document.querySelector('#fitem_id_' + arguments[0]);
                       const chosen = box.querySelector('.form-autocomplete-selection');
                       if (chosen && chosen.innerText.includes(arguments[1])) {
                           return true;
                       }
                       for (const item of box.querySelectorAll('ul[role=listbox] li')) {
                           if (item.innerText.trim().startsWith(arguments[1])) {
                               item.click();
                               break;
                           }
                       }
                       return false;""", field, label)
        if done:
            return
    raise RuntimeError(f'could not pick {label!r} in {field}')


def set_date(b, name, year, month, day):
    """Set the day, month and year menus of a Moodle date field, and tick it if it is optional."""
    if b.has(f'#id_{name}_enabled'):
        tick(b, f'#id_{name}_enabled')
    b.js('''const set = (id, value) => {
                const select = document.getElementById(id);
                select.disabled = false;
                select.value = String(value);
                select.dispatchEvent(new Event('change', {bubbles: true}));
            };
            set('id_' + arguments[0] + '_day', arguments[1]);
            set('id_' + arguments[0] + '_month', arguments[2]);
            set('id_' + arguments[0] + '_year', arguments[3]);''', name, day, month, year)


def upload(b, opener, path, name=None):
    """Add a file with Moodle's file picker, as a person does: Add, Upload a file, Upload this file."""
    buffer = io.BytesIO()
    name = name or os.path.basename(path)
    with zipfile.ZipFile(buffer, 'w') as archive:
        archive.write(path, name)
    remote = b.s('POST', '/se/file', {'file': base64.b64encode(buffer.getvalue()).decode()})
    click_js(b, opener)
    time.sleep(1.2)
    b.js('''for (const repo of document.querySelectorAll('.moodle-dialogue-base[aria-hidden=false] .fp-repo')) {
                if (repo.innerText.trim() === 'Upload a file') {
                    repo.querySelector('a').click();
                }
            }''')
    time.sleep(1.2)
    field = b.find('.moodle-dialogue-base[aria-hidden=false] input[type=file]')
    b.s('POST', f'/element/{field}/value', {'text': remote})
    click_js(b, '.moodle-dialogue-base[aria-hidden=false] .fp-upload-btn')
    for _ in range(30):
        time.sleep(0.4)
        if not b.has('.moodle-dialogue-base[aria-hidden=false] .fp-upload-btn'):
            break
    time.sleep(0.6)


def xlsx(path, sheets):
    """Write a small .xlsx workbook: sheets is a list of (name, rows). A number stays a number, as a spreadsheet saves it."""
    def cell(ref, value):
        if value is None or value == '':
            return ''
        if isinstance(value, (int, float)):
            return f'<c r="{ref}"><v>{value}</v></c>'
        return f'<c r="{ref}" t="inlineStr"><is><t xml:space="preserve">{escape(value)}</t></is></c>'

    def sheet(rows):
        lines = []
        for r, row in enumerate(rows, 1):
            cells = ''.join(cell(f'{column(c)}{r}', value) for c, value in enumerate(row))
            lines.append(f'<row r="{r}">{cells}</row>')
        return ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' + MAIN + '"><sheetData>' +
                ''.join(lines) + '</sheetData></worksheet>')

    def column(n):
        out = ''
        n += 1
        while n:
            n, left = divmod(n - 1, 26)
            out = chr(65 + left) + out
        return out

    types = ''.join(f'<Override PartName="/xl/worksheets/sheet{i}.xml" ContentType="application/vnd.openxmlformats-'
                    'officedocument.spreadsheetml.worksheet+xml"/>' for i in range(1, len(sheets) + 1))
    names = ''.join(f'<sheet name="{escape(name)}" sheetId="{i}" r:id="rId{i}"/>' for i, (name, _) in enumerate(sheets, 1))
    links = ''.join(f'<Relationship Id="rId{i}" Type="{OFFICE}/worksheet" Target="worksheets/sheet{i}.xml"/>'
                    for i in range(1, len(sheets) + 1))
    head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as book:
        book.writestr('[Content_Types].xml', head + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/'
                      'content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.'
                      'relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName='
                      '"/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.'
                      'main+xml"/>' + types + '</Types>')
        book.writestr('_rels/.rels', head + f'<Relationships xmlns="{PACKAGE}"><Relationship Id="rId1" Type="{OFFICE}/'
                      'officeDocument" Target="xl/workbook.xml"/></Relationships>')
        book.writestr('xl/workbook.xml', head + f'<workbook xmlns="{MAIN}" xmlns:r="{OFFICE}"><sheets>{names}</sheets>'
                      '</workbook>')
        book.writestr('xl/_rels/workbook.xml.rels', head + f'<Relationships xmlns="{PACKAGE}">{links}</Relationships>')
        for i, (_, rows) in enumerate(sheets, 1):
            book.writestr(f'xl/worksheets/sheet{i}.xml', sheet(rows))
