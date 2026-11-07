#!/usr/bin/env python3
"""The Student Records theme: its settings, what it changes on each kind of page, phones, dark mode, and the plugin menu on Boost."""
# Snapshot: seed
import os
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from shots import ADMINPASS, USERDOCS, away, click_js, clip, open_course, press, size, start, submit, tool, top  # noqa: E402

IMAGES = os.path.join(USERDOCS, 'theme', 'images')
SETTINGS = '/admin/settings.php?section=themesettingstudentrecords'
ACCENT = '#id_s_theme_studentrecords_accentcolor'
SR = '/local/studentrecords'
DRAWER = '[data-toggler=drawers][data-action=toggle][data-target=theme_boost-drawers-courseindex]'
PHONEMENU = '[data-toggler=drawers][data-action=toggle][data-target=theme_boost-drawers-primary]'


def img(name):
    return os.path.join(IMAGES, name + '.png')


def accent_is(b, colour):
    """Wait until the rebuilt styles reach the page: Moodle builds them on the first page after a save."""
    want = tuple(int(colour[i:i + 2], 16) for i in (1, 3, 5))
    for _ in range(40):
        b.go('/my/')
        got = b.js('''const a = document.createElement('button');
                      a.className = 'btn btn-primary';
                      document.body.appendChild(a);
                      const c = getComputedStyle(a).backgroundColor;
                      a.remove();
                      return c.match(/[0-9]+/g).slice(0, 3).map(Number);''')
        if tuple(got) == want:
            return
        time.sleep(1.5)
    raise RuntimeError(f'the accent {colour} never reached the page')


def login_page(b):
    """The login page and the pages that share its layout, as a visitor sees them."""
    b.go('/login/index.php')
    b.shot(img('login-page'), full=False)
    b.go('/login/forgot_password.php')
    top(b, img('login-forgot-password'), 560)


def settings(b):
    """The settings, as a site administrator walks them."""
    b.login('admin', ADMINPASS)
    b.go(SETTINGS)
    clip(b, img('settings-page'), '#adminsettings')

    # A colour that is too light, then one that isn't a colour.
    b.fill(ACCENT, '#94bce3')
    submit(b)
    print(b.text('.alert'))
    b.js('document.activeElement.blur();')
    clip(b, img('accent-too-light'), '#admin-accentcolor', pad=4)
    b.go(SETTINGS)
    b.fill(ACCENT, 'blue')
    submit(b)
    print(b.text('#admin-accentcolor .error, #admin-accentcolor .alert, #admin-accentcolor'))

    # A good one: three digits are saved as six.
    b.go(SETTINGS)
    b.fill(ACCENT, '#a13')
    submit(b)
    print(b.text('.alert'), b.js('return document.querySelector(arguments[0]).value;', ACCENT))
    accent_is(b, '#aa1133')
    b.go(SR + '/index.php?view=staff')
    top(b, img('accent-changed'), 420)
    b.go(SETTINGS)
    b.fill(ACCENT, '#1f5f7a')
    submit(b)
    accent_is(b, '#1f5f7a')

    # HTML is refused in the text settings.
    b.go(SETTINGS)
    b.fill('#id_s_theme_studentrecords_loginwelcome', '<b>Hello</b>')
    submit(b)
    print(b.text('#admin-loginwelcome')[:120])

    # Empty the welcome text.
    b.go(SETTINGS)
    b.fill('#id_s_theme_studentrecords_loginwelcome', '')
    submit(b)
    b.logout()
    b.go('/login/index.php')
    print(b.text('.sr-login-head'))
    clip(b, img('login-empty-welcome'), '.sr-login-head', pad=12)
    b.login('admin', ADMINPASS)

    # Empty the school name, then take the logo away.
    b.go(SETTINGS)
    b.fill('#id_s_theme_studentrecords_schoolname', '')
    submit(b)
    b.go('/my/')
    print(b.text('.sr-brand'))
    b.go(SETTINGS)
    click_js(b, '#admin-logo .fp-file a, #admin-logo .fp-thumbnail')
    time.sleep(1)
    click_js(b, '.moodle-dialogue-base[aria-hidden=false] .fp-file-delete')
    time.sleep(1.2)
    click_js(b, '.modal.show .btn-primary')
    time.sleep(1.2)
    submit(b)
    b.go('/my/')
    print(b.text('.sr-brand'), b.js("return !!document.querySelector('.sr-brand-tile');"))
    top(b, img('top-bar-initials'), 61)
    b.logout()
    tool('restore', 'seed')


def pages(b):
    """What the theme adds on each kind of page, as each person sees it."""
    b.login('irene')
    b.go('/my/')
    top(b, img('top-bar'), 61)
    clip(b, img('dashboard-staff-strip'), '.sr-cardtop', pad=4)
    open_course(b, 'ENG101 - English Composition I')
    course = b.url().split('id=')[1]
    top(b, img('course-teacher-card'), 560)
    open_course(b, 'PSY101 - Introduction to Psychology')
    print(b.text('.sr-aside'))
    clip(b, img('card-no-meetings'), '.sr-aside .sr-sidecard', pad=4)
    b.go(f'{SR}/students.php?courseid={course}')
    top(b, img('course-student-list'), 460)
    b.logout()

    b.login('ben')
    b.go('/my/')
    clip(b, img('dashboard-student-strip'), '.sr-cardtop', pad=4)
    open_course(b, 'ECO201 - Microeconomics')
    print(b.text('.sr-aside'))
    clip(b, img('course-student-card'), '.sr-aside .sr-sidecard', pad=4)
    b.go(SR + '/index.php?view=student')
    top(b, img('menu-drawer'), 520)
    b.logout()

    b.login('doris')
    top(b, img('top-bar-no-lms'), 61)
    b.logout()


def phones(b):
    """The same pages at 390 pixels wide."""
    size(b, 390, 844, True)
    b.go('/login/index.php')
    b.shot(img('phone-login'), full=False)
    b.login('ben')
    b.go('/my/')
    top(b, img('phone-dashboard'), 350)
    press(b, PHONEMENU)
    time.sleep(1.5)
    away(b)
    top(b, img('phone-main-menu'), 300)
    b.go(SR + '/index.php?view=student')
    b.shot(img('phone-student-records'), full=False)
    press(b, DRAWER)
    time.sleep(1.5)
    away(b)
    top(b, img('phone-menu-open'), 480)
    b.logout()

    b.login('irene')
    open_course(b, 'ENG101 - English Composition I')
    top(b, img('phone-course-page'), 520)
    b.js("document.querySelector('.secondary-navigation .nav-tabs').scrollLeft = 9999;")
    time.sleep(0.5)
    press(b, '.secondary-navigation .dropdownmoremenu > a')
    top(b, img('phone-more-opened'), 300)
    print(b.js("""const row = document.querySelector('.secondary-navigation .nav-tabs');
                  return [row.clientHeight, row.scrollHeight];"""))
    size(b, 844, 390, True)
    open_course(b, 'ENG101 - English Composition I')
    press(b, '.secondary-navigation .dropdownmoremenu > a')
    away(b)
    b.shot(img('phone-sideways-more'), full=False)
    size(b, 390, 844, True)
    b.logout()

    b.login('rosa')
    b.go(SR + '/students.php')
    time.sleep(0.5)
    print(b.js("""const box = document.querySelector('.table-responsive');
                  return [box.getAttribute('tabindex'), box.getAttribute('role'), box.getAttribute('aria-label')];"""))
    b.js("document.querySelector('.table-responsive').scrollIntoView({block: 'start', behavior: 'instant'}); window.scrollBy(0, -70);")
    time.sleep(0.5)
    top(b, img('phone-wide-table'), 640)
    b.logout()
    size(b, 1280, 900)


def dark(b):
    """Dark mode: a site administrator turns it on, and each person chooses it."""
    b.login('admin', ADMINPASS)
    b.go('/admin/settings.php?section=themesettingboost')
    click_js(b, 'a[href="#theme_boost_experimental"]')
    time.sleep(0.5)
    click_js(b, '#id_s_theme_boost_enablecolourmodes')
    time.sleep(0.5)
    clip(b, img('dark-setting'), '#adminsettings .nav-tabs', '#admin-defaultcolourmode', pad=2)
    submit(b)
    print(b.text('.alert'))
    b.logout()

    b.login('ben')
    b.go('/my/')
    press(b, '#colourmode-menu-toggle')
    time.sleep(0.4)
    top(b, img('dark-colour-menu'), 240)
    click_js(b, '[data-action=set-colourmode][data-colourmode=dark]')
    time.sleep(1.2)
    away(b)
    top(b, img('dark-dashboard'), 420)
    b.go(SR + '/index.php?view=student')
    top(b, img('dark-student-records'), 520)
    size(b, 390, 844, True)
    b.go(SR + '/index.php?view=student')
    top(b, img('dark-phone'), 520)
    size(b, 1280, 900)
    b.logout()

    # Nobody is signed in now, and the browser remembers the mode.
    b.go('/login/index.php')
    print(b.js("return document.documentElement.getAttribute('data-bs-theme');"))
    b.shot(img('dark-login'), full=False)

    b.login('doris')
    press(b, '#colourmode-menu-toggle')
    click_js(b, '[data-action=set-colourmode][data-colourmode=dark]')
    time.sleep(1.2)
    away(b)
    top(b, img('dark-top-bar-no-lms'), 61)
    b.logout()
    tool('restore', 'seed')


def boost(b):
    """The plugin's menu on another theme: a site administrator selects Boost, and people see the box at the top of the page."""
    b.login('admin', ADMINPASS)
    b.go('/admin/themeselector.php')
    press(b, '#theme-card-boost button[type=submit]')
    clip(b, img('themes-page'), '#region-main h2, #region-main h3', '#region-main .theme-card')
    b.logout()

    b.login('ben')
    b.go('/my/')
    top(b, img('boost-dashboard'), 300)
    b.go(SR + '/index.php?view=student')
    top(b, img('boost-menu-wide'), 460)
    size(b, 390, 844, True)
    b.go(SR + '/index.php?view=student')
    top(b, img('boost-menu-phone'), 360)
    press(b, 'details.sr-inline-menu-small summary')
    away(b)
    top(b, img('boost-menu-phone-open'), 620)
    size(b, 1280, 900)
    b.logout()

    # Back to the theme, as the install page says.
    b.login('admin', ADMINPASS)
    b.go('/admin/themeselector.php')
    press(b, '#theme-card-studentrecords button[type=submit]')
    print(b.text('#theme-card-studentrecords'))
    b.logout()
    tool('restore', 'seed')


def main():
    b = start()
    try:
        login_page(b)
        settings(b)
        pages(b)
        phones(b)
        dark(b)
        boost(b)
    finally:
        b.close()


if __name__ == '__main__':
    main()
