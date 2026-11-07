"""Helpers for the capture scripts of the theme and the shared user pages: a browser, crops, clicks and site commands."""
import base64
import json
import os
import subprocess
import sys
import time
import urllib.parse
import urllib.request

sys.dont_write_bytecode = True
TOOLS = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, TOOLS)
from wd import ELEMENT, HUB, SITE, WD  # noqa: E402,F401

USERDOCS = os.path.join(TOOLS, '..', 'user')
ADMINPASS = os.environ.get('ADMINPASS', 'Admin@123')


def start(**options):
    """Open a browser once the hub has a free slot. WD() alone gives up after 3 minutes, and a busy hub takes longer."""
    end = time.time() + 3600
    while time.time() < end:
        with urllib.request.urlopen(HUB + '/status', timeout=20) as reply:
            status = json.load(reply)['value']
        slots = [slot for node in status.get('nodes', []) for slot in node['slots']]
        if not slots or any(not slot.get('session') for slot in slots):
            return WD(**options)
        time.sleep(5)
    raise RuntimeError('no free browser slot')


def tool(*command):
    """Run docs/tools/site.sh on this site, for what isn't part of a person's job."""
    subprocess.run([os.path.join(TOOLS, 'site.sh'), *command], check=True,
                   env={**os.environ, 'SITE': str(SITE)}, stdout=subprocess.DEVNULL)


def size(b, width, height, mobile=False):
    """Change the size of the screen."""
    b.width, b.height, b.mobile = width, height, mobile
    b.metrics(height)


def press(b, css):
    """Click an element for real, as a finger or a mouse does, and wait for the page."""
    element = b.find(css)
    b.s('POST', f'/element/{element}/click', {})
    time.sleep(0.5)
    b.settle()


def click_js(b, css):
    """Click an element that may be under a sticky bar."""
    b.js('document.querySelector(arguments[0]).click();', css)


def submit(b, css='#adminsettings button[type=submit]'):
    """Press a form's save button and wait for the next page."""
    click_js(b, css)
    time.sleep(1)
    b.settle()


def away(b):
    """Move the pointer and the focus off what was pressed, so that its tooltip and focus ring go."""
    b.s('POST', '/actions', {'actions': [{'type': 'pointer', 'id': 'mouse', 'parameters': {'pointerType': 'mouse'}, 'actions': [
        {'type': 'pointerMove', 'duration': 0, 'x': b.width - 8, 'y': b.height - 8, 'origin': 'viewport'}]}]})
    b.js('if (document.activeElement) { document.activeElement.blur(); }')
    time.sleep(0.5)


def clip(b, path, first, last=None, pad=8, width=None):
    """Save the part of the page from the top of one element to the bottom of another."""
    os.makedirs(os.path.dirname(path), exist_ok=True)
    b.metrics(min(b.page_height() + 8, 8000))
    time.sleep(0.5)
    box = b.js('''
        const a = document.querySelector(arguments[0]).getBoundingClientRect();
        const z = document.querySelector(arguments[1] || arguments[0]).getBoundingClientRect();
        return [Math.min(a.left, z.left), a.top, Math.max(a.right, z.right), z.bottom];''', first, last)
    clipped = {'x': max(box[0] - pad, 0), 'y': max(box[1] - pad, 0), 'width': width or box[2] - box[0] + 2 * pad,
               'height': box[3] - box[1] + 2 * pad, 'scale': 1}
    data = b.cdp('Page.captureScreenshot', {'format': 'png', 'clip': clipped})['data']
    with open(path, 'wb') as out:
        out.write(base64.b64decode(data))
    b.metrics(b.height)


def top(b, path, height):
    """Save the top of what is on the screen, this many pixels high."""
    os.makedirs(os.path.dirname(path), exist_ok=True)
    data = b.cdp('Page.captureScreenshot', {'format': 'png', 'clip': {
        'x': 0, 'y': b.js('return window.scrollY;'), 'width': b.width, 'height': height, 'scale': 1}})['data']
    with open(path, 'wb') as out:
        out.write(base64.b64decode(data))


def open_course(b, name):
    """Open a course by its full name, as a person does from the course search."""
    b.go('/course/search.php?search=' + urllib.parse.quote(name.split(' - ')[0]))
    href = b.js('''for (const a of document.querySelectorAll('#region-main a[href*="course/view.php"]')) {
                       if (a.innerText.trim() === arguments[0]) { return a.href; }
                   }
                   return null;''', name)
    if not href:
        raise RuntimeError(f'no course {name!r} at {b.url()}')
    b.go(href)
    time.sleep(0.8)
