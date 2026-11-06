# Student Records theme

The Student Records theme (`theme_studentrecords`) is a small child theme of Boost for Moodle 4.5 to 5.3. It gives every page the school's name, logo and colour. With the [Student Records](../../local/studentrecords/README.md) plugin it also shows that plugin's menu and cards.

[Clouve Inc.](https://clouve.com) maintains the theme. It is licensed under the GNU General Public License, version 3 or later.

## What it does

- The same top bar on every page: the school's logo (or the first letters of its name) and name, the main tabs, notifications, messages, and the user menu with the user's name.
- A simple login page: one card with the school's logo, name, welcome text and help text, around Moodle's own login form.
- On phones the course tab row shows that it scrolls. There is a fade at the right edge, and tabs that do not fit go in "More".
- With Student Records, a short strip on the Dashboard (balance, next payment, holds), a side card on course and activity pages (attendance for students, today's class for teachers), and the Student Records menu in the left drawer on its pages.

Without Student Records the theme looks like Boost with the school's name and colour.

## Requirements

- Moodle 4.5 to 5.3, with the Boost theme. The CI of the repo runs on Moodle 4.5, 5.1, 5.2 and 5.3.
- Optional: [Student Records](../../local/studentrecords/README.md) (`local_studentrecords`). The theme works without it.

## Install

1. Download `theme_studentrecords-<release>.zip` from the [releases page](https://github.com/Clouve/moodle-plugins/releases).
2. Sign in as a site administrator and go to Site administration > Plugins > Install plugins.
3. Drop the ZIP file into "ZIP package" and select "Install plugin from the ZIP file".
4. Select "Continue" and then "Upgrade Moodle database now". Follow the screens.
5. Choose the theme in Site administration > Appearance > Themes.

To install by hand, unzip the file into the `public/theme/` folder of your Moodle code on Moodle 5.1 and later, or into `theme/` on Moodle 5.0 and earlier. Then run the upgrade.

## Settings

The settings are in Site administration > Appearance > Themes > Student Records theme.

- School name. If it is empty, the site's full name is used.
- Logo. If it is empty, Moodle's compact logo is used, or else the first letters of the school name.
- Accent colour. It must have a contrast of at least 4.5 to 1 with white (WCAG 2.1 AA), and a lighter colour is refused.
- Login page welcome text and login page help text.

## Moodle versions

The theme keeps few copies of Boost's files, because those change between versions. The login form and the user menu are Moodle's own, so each version shows its own. A site on Moodle 5.2 or later sees the login form of that version, with the forgotten password link above the button.

The top bar and the drawers are the theme's own templates, written to work on all four versions.

Moodle 5.3 can turn on a dark colour mode in the settings of the Boost theme. The top bar, the drawers and the login page follow it, and the colour menu shows in the top bar. The pages of the Student Records and Academic Transcript plugins follow it too.

## Privacy

The theme stores no personal data. Boost keeps the drawer preferences of each user.

## License

The Student Records theme is free software. You can redistribute it and change it under the terms of the GNU General Public License, version 3 or later. The full text is in the `LICENSE` file in the theme folder. Copyright 2026 Clouve Inc.

The theme stays under the GPL, unlike the plugins in this repo, because it adapts files from Moodle's Boost theme: `config.php`, `lib.php`, `layout/drawers.php`, `layout/login.php`, `templates/drawers.mustache`, `templates/login.mustache` and `templates/navbar.mustache`.
