#!/usr/bin/env python3
"""Academic Transcript: uninstall it, see what stays, and install it again, as a site administrator."""
# Snapshot: fresh
import os
import sys
import time

sys.dont_write_bytecode = True
TOOLS = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, TOOLS)
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from transcript_setup import ADMINPASS, AT, YEAR, add_term, click_js, main_shot, press, press_text, site  # noqa: E402
from wd import WD  # noqa: E402


def main():
    site('php admin/cli/cfg.php --name=theme --set=studentrecords')
    b = WD()
    try:
        b.login('admin', ADMINPASS)

        # A term and a course, so that there's something to keep.
        b.go(AT + '/terms.php')
        press_text(b, 'Add a term')
        add_term(b, f'Fall {YEAR}', (24, 'August', YEAR), (18, 'December', YEAR))
        b.go('/course/edit.php?category=1')
        b.fill('#id_fullname', 'Introduction to Business')
        b.fill('#id_shortname', 'BUS101-F')
        click_js(b, 'a.collapseexpand')
        time.sleep(0.5)
        b.fill('#id_customfield_transcript_coursecode', 'BUS 101')
        b.fill('#id_customfield_transcript_creditunits', '3')
        b.select('#id_customfield_transcript_hourtype', 'Credit hours')
        b.select('#id_customfield_transcript_term', f'Fall {YEAR}')
        press(b, '#id_saveanddisplay')
        course = b.url().split('id=')[1]

        # Uninstall.
        b.go('/admin/plugins.php')
        href = b.js("return document.querySelector('a[href*=\"uninstall=local_academictranscript\"]').href;")
        b.go(href)
        main_shot(b, 'uninstall-confirm')
        print(b.text('#region-main')[:600])
        press_text(b, 'Continue')
        print(b.text('#region-main')[:900])
        # Never select Continue here. It deletes the plugin folder, which every test site shares.

        # Install it again: the folder is in place, so Moodle finds a new plugin.
        b.go('/admin/index.php')
        press_text(b, 'Continue')
        print(b.url())
        print(b.js("return document.body.innerText.includes('To be installed');"))
        press_text(b, 'Upgrade Moodle database now')
        print(b.url())
        press_text(b, 'Continue')
        print(b.url())
        if 'upgradesettings' in b.url():
            print(b.text('#region-main')[:200])
            press_text(b, 'Save changes')
            print(b.url())
        b.go(f'/course/edit.php?id={course}')
        click_js(b, 'a.collapseexpand')
        time.sleep(0.5)
        print(b.text('#id_category_1')[:500])
    finally:
        b.close()


if __name__ == '__main__':
    main()
