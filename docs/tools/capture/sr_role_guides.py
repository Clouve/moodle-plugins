#!/usr/bin/env python3
"""Student Records: the first screens of the Registrar, a teacher and a student, for the guides by role, on a computer and a phone."""
# Snapshot: seed
import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srrecords import (crop, img, main_shot, open_course, person, phone, SR, switch)  # noqa: E402

# The Registrar's home, with the Student Records menu.
b = person('rosa')
b.go(f'{SR}/index.php')
b.shot(img('registrar-staff-home'), full=False)

# A teacher's menu, and the course menu.
switch(b, 'tomas')
b.go(f'{SR}/index.php')
b.shot(img('teacher-home'), full=False)
open_course(b, 'MKT210 - Principles of Marketing')
crop(b, 'teacher-course-menu', '.secondary-navigation', pad=2)

# A student: the dashboard card, My home, My academic record and My details.
switch(b, 'carmen')
print('lands on', b.url())
b.go('/my/')
crop(b, 'student-dashboard-strip', 'section.sr-strip', pad=6)
b.go(f'{SR}/index.php')
print(b.url())
crop(b, 'student-home-main', '#page-header h1', '#region-main', pad=6)
b.click_text('My student record', 'a')
print(b.url())
main_shot(b, 'student-record')
b.js('document.querySelector("details.sr-details").open = true;')
time.sleep(0.4)
crop(b, 'student-details', 'details.sr-details summary', 'details.sr-details table', pad=6)

# The same on a phone.
switch(b, 'carmen')
phone(b)
b.go(f'{SR}/index.php')
crop(b, 'student-home-phone', '#page-header h1', '#region-main', pad=6)
href = b.js('''return document.querySelector('a[href*="account.php"]').href;''')
b.go(href)
crop(b, 'student-account-phone', '#page-header h1', '#region-main table tbody tr:nth-child(2)', pad=6, width=386)
b.close()
