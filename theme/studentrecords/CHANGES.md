# Changes

Release notes for the Student Records theme, newest first.

## 1.0.3

The theme now works on Moodle 4.5, 5.1, 5.2 and 5.3. It was written for Moodle 5.2.

- The login page works on Moodle 4.5 and 5.1. The theme keeps no copy of Moodle's login form, so each version shows its own, with the fields and strings it has. On Moodle 5.3 that includes the OAuth 2 sign-in details.
- Course pages work on Moodle 4.5 and 5.1. They needed a template that came in Moodle 5.2.
- The user menu opens on Moodle 4.5. The theme keeps no copy of it, and the user's name goes in as user menu metadata.
- The buttons that open the drawers have the right icons on Moodle 4.5 and 5.1, and on Moodle 4.5 their labels stay hidden and the phone menu has its icon.
- Moodle 5.3: the course index has its collapse button back, the top bar stays white, and the colour mode menu shows. The theme's colours follow the dark colour mode.
- Sign-in instructions show on wide screens on Moodle 5.2 and later.
- A phone is the same width on every version. On Moodle 4.5 the phone styles reached up to 767 pixels.
- On phones a wide logo or a long school name no longer pushes the user menu off the screen.
- The Student Records menu heading keeps its padding on Moodle 4.5 and 5.1.
- A site without Student Records no longer gets a debugging message on every page, and the theme leaves that plugin alone until it is installed and while an upgrade runs.
- The editor's styles come from Boost. The theme named a file it does not have.
- The CSS used when a build fails holds Boost's styles one time. It held them twice.
- The message for a colour that is too light rounds down, so 4.46 to 1 no longer reads as 4.5 to 1.

## 1.0.0 to 1.0.2

- First releases: the school top bar and brand settings, the accent colour with a WCAG contrast check, the login page, the Student Records card regions on the Dashboard and course pages, and the phone tab row scroll fade.
- Login page background, avatar initials, a phone top bar that fits, outline buttons with dark text and drawer heading padding.
- Links in text are underlined, and tables that scroll sideways can be reached by keyboard (WCAG 2.1 AA).
