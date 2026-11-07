"""A small WebDriver client for the test site in docs/tools/site.sh.

It needs only Python's standard library. Each capture script makes one WD object,
signs in as the role the guide names, walks the workflow and saves screenshots:

    from wd import WD
    b = WD()
    b.login('rosa')
    b.go('/local/studentrecords/staff.php')
    b.shot('docs/user/student-records/images/staff-area.png')
    b.close()

SITE picks the test site (1 to 9), and HUB the WebDriver address.
"""
import base64
import json
import os
import subprocess
import time
import urllib.error
import urllib.request

HOST = 'riverside.example.com'
BASE = 'http://' + HOST
HUB = os.environ.get('HUB', 'http://127.0.0.1:14444')
SITE = int(os.environ.get('SITE', '1'))
PASSWORD = os.environ.get('USERPASS', 'Pass@1234')
ELEMENT = 'element-6066-11e4-a52e-4f735466cecf'


class WD:
    """One Chrome session on the test site."""

    def __init__(self, width=1280, height=900, mobile=False, site=None):
        self.width = width
        self.height = height
        self.mobile = mobile
        port = 18000 + (site or SITE)
        args = [
            '--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars',
            '--lang=en-US', f'--window-size={width},{height}', '--force-device-scale-factor=1',
            f'--host-resolver-rules=MAP {HOST} 127.0.0.1:{port}',
            # The site has no HTTPS, and Chrome would try it first for a name like this one.
            '--disable-features=HttpsUpgrades,HttpsFirstBalancedModeAutoEnable,HttpsFirstModeV2ForTypicallySecureUsers',
        ]
        caps = {'capabilities': {'alwaysMatch': {'browserName': 'chrome', 'goog:chromeOptions': {'args': args}}}}
        self.sid = self._req('POST', '/session', caps)['sessionId']
        self.metrics(height)

    # WebDriver plumbing.

    def _req(self, method, path, body=None):
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(self.hub() + path, data=data, method=method,
                                     headers={'Content-Type': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=180) as reply:
                return json.loads(reply.read())['value']
        except urllib.error.HTTPError as error:
            raise RuntimeError(error.read().decode()[:600]) from error

    @staticmethod
    def hub():
        return HUB

    def s(self, method, path, body=None):
        return self._req(method, f'/session/{self.sid}{path}', body)

    def cdp(self, cmd, params=None):
        return self.s('POST', '/goog/cdp/execute', {'cmd': cmd, 'params': params or {}})

    def metrics(self, height):
        self.cdp('Emulation.setDeviceMetricsOverride', {
            'width': self.width, 'height': int(height), 'deviceScaleFactor': 1, 'mobile': self.mobile})

    # Pages.

    def go(self, path):
        """Open a path on the site, or a full URL."""
        url = path if path.startswith('http') else BASE + path
        self.s('POST', '/url', {'url': url})
        self.settle()

    def url(self):
        return self.s('GET', '/url')

    def settle(self, seconds=0.4):
        """Wait until the page has loaded, its fonts are in and Moodle's JavaScript is idle."""
        end = time.time() + 20
        while time.time() < end:
            state = self.js('''return document.readyState === "complete"
                && (!window.M || !M.util || !M.util.pending_js || M.util.pending_js.length === 0)
                && (!document.fonts || document.fonts.status === "loaded")''')
            if state:
                break
            time.sleep(0.2)
        time.sleep(seconds)

    def js(self, script, *args):
        return self.s('POST', '/execute/sync', {'script': script, 'args': list(args)})

    # Elements.

    def find(self, css, wait=15):
        end = time.time() + wait
        while True:
            try:
                found = self.s('POST', '/element', {'using': 'css selector', 'value': css})
                return list(found.values())[0]
            except RuntimeError:
                if time.time() > end:
                    raise RuntimeError(f'not found: {css} at {self.url()}') from None
                time.sleep(0.3)

    def has(self, css):
        return bool(self.s('POST', '/elements', {'using': 'css selector', 'value': css}))

    def fill(self, css, text):
        """Clear a field and type into it. Moodle can then mark a required field invalid
        and refuse the form, so set such a value with js() instead."""
        for attempt in range(5):
            try:
                element = self.find(css)
                self.s('POST', f'/element/{element}/clear', {})
                self.s('POST', f'/element/{element}/value', {'text': text})
                return
            except RuntimeError as error:
                if 'stale element' not in str(error) or attempt == 4:
                    raise
                time.sleep(0.7)

    def send(self, css, text):
        self.s('POST', f'/element/{self.find(css)}/value', {'text': text})

    def click(self, css):
        element = self.find(css)
        self.js('arguments[0].scrollIntoView({block: "center"});', {ELEMENT: element})
        self.s('POST', f'/element/{element}/click', {})
        self.settle()

    def click_text(self, text, css='a, button, input[type=submit]'):
        """Click the first visible element of these kinds that shows exactly this text."""
        found = self.js('''
            const want = arguments[0].trim();
            for (const e of document.querySelectorAll(arguments[1])) {
                const label = (e.innerText || e.value || '').trim();
                if (label === want && e.offsetParent !== null) {
                    e.scrollIntoView({block: 'center'});
                    e.click();
                    return true;
                }
            }
            return false;''', text, css)
        if not found:
            raise RuntimeError(f'no {css} that says {text!r} at {self.url()}')
        self.settle()

    def select(self, css, label):
        """Pick an option of a select by what it shows."""
        found = self.js('''
            const select = document.querySelector(arguments[0]);
            for (const option of select.options) {
                if (option.text.trim() === arguments[1]) {
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', {bubbles: true}));
                    return true;
                }
            }
            return false;''', css, label)
        if not found:
            raise RuntimeError(f'no option {label!r} in {css} at {self.url()}')

    def text(self, css='body'):
        return self.s('GET', f'/element/{self.find(css)}/text')

    # Screenshots.

    def page_height(self):
        return self.js('''
            const page = document.getElementById('page');
            return Math.max(document.body.scrollHeight, document.documentElement.scrollHeight,
                page ? page.scrollHeight : 0);''')

    def shot(self, path, full=True, limit=3200):
        """Save the page as a PNG. Full means all of it, up to the limit in pixels."""
        os.makedirs(os.path.dirname(path) or '.', exist_ok=True)
        if full:
            self.metrics(min(self.page_height() + 8, limit))
            time.sleep(0.5)
        data = self.s('GET', '/screenshot')
        with open(path, 'wb') as out:
            out.write(base64.b64decode(data))
        if full:
            self.metrics(self.height)

    def shot_of(self, css, path):
        """Save one element of the page as a PNG."""
        os.makedirs(os.path.dirname(path) or '.', exist_ok=True)
        data = self.s('GET', f'/element/{self.find(css)}/screenshot')
        with open(path, 'wb') as out:
            out.write(base64.b64decode(data))

    # Files.

    def fetch(self, url, path, form=None):
        """Save a file from the site with this session's cookies. With form, post that form instead."""
        script = '''
            const [url, formcss, done] = arguments;
            const options = {credentials: 'include'};
            if (formcss) {
                options.method = 'POST';
                options.body = new FormData(document.querySelector(formcss));
            }
            fetch(url, options).then(reply => reply.arrayBuffer()).then(buffer => {
                const bytes = new Uint8Array(buffer);
                let text = '';
                for (let i = 0; i < bytes.length; i += 8192) {
                    text += String.fromCharCode.apply(null, bytes.subarray(i, i + 8192));
                }
                done(btoa(text));
            }).catch(error => done('ERROR ' + error));'''
        full = url if url.startswith('http') else BASE + url
        data = self.s('POST', '/execute/async', {'script': script, 'args': [full, form]})
        if data.startswith('ERROR '):
            raise RuntimeError(data)
        os.makedirs(os.path.dirname(path) or '.', exist_ok=True)
        with open(path, 'wb') as out:
            out.write(base64.b64decode(data))

    # People.

    def login(self, user, password=None):
        """Sign in. Every made-up user of the seed has the same password, except the admin."""
        self.go('/login/index.php')
        if self.has('#username'):
            self.fill('#username', user)
            self.fill('#password', password or PASSWORD)
            self.click('#loginbtn')
            time.sleep(0.6)
            self.settle()

    def logout(self):
        self.go('/login/logout.php')
        if self.has('form[action*="logout.php"] button[type=submit]'):
            self.click('form[action*="logout.php"] button[type=submit]')

    def close(self):
        self._req('DELETE', f'/session/{self.sid}')


def pdf_page(pdf, page, png, dpi=80):
    """Turn one page of a real PDF from the site into a PNG. It needs pdftoppm."""
    stem = png[:-4] if png.endswith('.png') else png
    subprocess.run(['pdftoppm', '-r', str(dpi), '-png', '-singlefile', '-f', str(page), '-l', str(page), pdf, stem], check=True)
