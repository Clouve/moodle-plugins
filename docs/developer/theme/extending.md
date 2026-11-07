# Student Records theme: extending

This page says how to change a layout, a template or a style safely across Moodle 4.5 to 5.3, what is safe to change and what isn't, and why the theme stays under the GPL. Read [the code map](README.md) first.

## Contents

- [What is safe to change](#what-is-safe-to-change)
- [Change a template](#change-a-template)
- [Change a layout](#change-a-layout)
- [Change a style](#change-a-style)
- [Add a setting](#add-a-setting)
- [Add a call into Student Records](#add-a-call-into-student-records)
- [Follow a new Boost](#follow-a-new-boost)
- [Write a child theme](#write-a-child-theme)
- [The seven files adapted from Boost](#the-seven-files-adapted-from-boost)
- [Why the theme stays GPL](#why-the-theme-stays-gpl)
- [Related pages](#related-pages)

## What is safe to change

| Change | Safe | Why |
|---|---|---|
| Add a rule to `theme/studentrecords/scss/studentrecords.scss` | Yes, with the check below | The file compiles on Boost's SCSS of that Moodle, so a rule must work on Bootstrap 4 and 5 |
| Add a language string | Yes | Keep `theme/studentrecords/lang/en/theme_studentrecords.php` sorted by key |
| Add a setting | Yes | See [Add a setting](#add-a-setting) |
| Change the wording of a string | Yes | `test_write_setting_refuses_a_light_colour` looks for the words "too light". Most other tests read strings by key |
| Add a key to the template context | Yes | Add it to the docblock of the template too |
| Change `theme/studentrecords/templates/brand.mustache` or `theme/studentrecords/templates/loginform.mustache` | With care | They aren't copies of Boost, but they serve every version. Keep the `Example context (json):` docblock |
| Change `theme/studentrecords/templates/navbar.mustache`, `theme/studentrecords/templates/drawers.mustache`, `theme/studentrecords/templates/login.mustache` | With care | One template serves four versions of Boost. See [Change a template](#change-a-template) |
| Change `theme/studentrecords/layout/drawers.php` | With care | It's a copy of Boost's file. Keep it close, so a new Boost is easy to follow |
| Rename a class, a template, or a setting | No | Sites hold the settings under those names, and the plugin tests the theme's name |
| Add a table, a capability or user data | No, not here | The theme stores nothing. `provider` says so. Student Records owns that kind of data |
| Copy another Boost template into the theme | Avoid | Each copy has to follow Boost on every version. The theme keeps few copies on purpose. The login form and the user menu are Moodle's own for that reason |
| Add Student Records logic to the theme | No | The theme must work without the plugin. Put the logic in the plugin, behind a callback |
| Call `component_callback()` directly | No | It throws for a plugin that isn't on disk. Use `records::call()` |

## Change a template

One template serves Moodle 4.5, 5.1, 5.2 and 5.3. Before you edit, read the same template of Boost on each version, in `<moodle>/theme/boost/templates/`. Then:

1. Write both forms where the versions differ, when the markup allows it. Moodle 4.5 runs Bootstrap 4, and 5.1 and later run Bootstrap 5. The theme writes `data-toggle` and `data-bs-toggle`, and `sr-only visually-hidden`, side by side. Don't drop the old form until Moodle 4.5 leaves `$plugin->supported`.
2. Use a gate only when a shared form can't work. The theme has two, `srnewdrawers` and `srsearchdivider`, both set in `theme/studentrecords/layout/drawers.php`. A new gate goes there, with a comment that says which Moodle changed what, and is listed in the docblock of the template.
3. Fill both blocks of `theme_boost/drawer` when they differ by version. `theme/studentrecords/templates/drawers.mustache` fills `drawerheadercontent` and `drawercontrols`, and each version prints the one it has.
4. Don't reach for a template that some versions lack. `theme_boost/courseindexdrawerheading` exists on 5.2 and 5.3 only, so it sits inside `{{#srnewdrawers}}`. A partial that doesn't exist throws `filenotfound` when it renders, and that stops the page. `core/primarymoremenu` and `core/secondarymoremenu` exist on 5.3 only, so the tabs stay on `core/moremenu`.
5. Keep the example in the docblock valid JSON, and keep its shape. `test_template_renders_from_its_example` finds it with a regular expression that needs `Example context (json):`, then a closing brace at four spaces, then `}}`. A reformatted docblock fails that test.
6. Escape with `{{ }}`. Use `{{{ }}}` only for HTML that Moodle or the plugin made. `test_templates_escape_the_top_bar_text` holds the top bar.

Then run the theme's tests on Moodle 4.5 and on 5.3. If the change touches the drawers, also run 5.2, where `srnewdrawers` turns on. `test_layout_renders_for_an_admin` renders all 19 layouts, and `test_course_page_keeps_the_course_index` holds the course index on each version.

## Change a layout

`theme/studentrecords/layout/drawers.php` and `theme/studentrecords/layout/login.php` are the only two. The other seven layouts are Boost's.

- Keep the additions in `theme/studentrecords/layout/drawers.php` in blocks that are easy to tell apart from Boost's lines. A diff against Boost's file should show only what the theme adds. [Follow a new Boost](#follow-a-new-boost) depends on that.
- The list of layouts in `theme/studentrecords/config.php` must have the same keys and values as Boost's. `test_layouts_match_boost` compares them on the Moodle under test, so a change in Boost's `config.php` fails the test until this file follows. That is on purpose.
- To give a layout its own file, put a file with Boost's name in `theme/studentrecords/layout/`. `theme_config::layout_file()` looks in this theme first, which is how `drawers.php` and `login.php` work. A new file name in the config fails `test_layouts_match_boost`.
- Anything a layout asks of Student Records goes through `records::call()`.
- `theme/studentrecords/layout/drawers.php` runs on every page of 11 layouts, so a slow call slows nearly every page. `shell::groups()` keeps its result for the request.

## Change a style

1. Edit `theme/studentrecords/scss/studentrecords.scss`.
2. Purge the theme caches, because the CSS is compiled. On the test site, `docs/tools/site.sh sync theme/studentrecords` copies the code in and purges.
3. Run `theme/studentrecords/tests/scss_test.php`. It builds the real CSS with the SCSS of that Moodle. A failed build falls back to Boost's CSS without a word, so don't rely on looking at the page alone.

Rules that keep it safe on every version:

- Colours that dark mode must change go through a custom property, with a line in the `[data-bs-theme="dark"]` block. Moodle 5.2 and earlier never set that attribute.
- Write a phone width out as `max-width: 575.98px`. `media-breakpoint-down(sm)` means another width on Bootstrap 4. `media-breakpoint-up(lg)` is the same on both, and the theme uses it.
- A rule against Boost's login page may need the id: `.pagelayout-login #region-main.sr-login-card`.
- Move the user's name only with the order rule in [Rules by area](settings-and-scss.md#rules-by-area). It depends on the name being the last metadata item.
- `!important` carries a `stylelint-disable-line` comment, as the existing lines do.

## Add a setting

1. Add it in `theme/studentrecords/settings.php`, with a title string and a `_desc` string in `theme/studentrecords/lang/en/theme_studentrecords.php`, sorted by key.
2. If it changes the CSS or a stored file, add `$setting->set_updatedcallback('theme_reset_all_caches')`.
3. Read it in `brand`, or in `core_renderer::render_login()`. If it reaches SCSS, validate it twice, on save and on read, as `setting_accentcolour::write_setting()` and `brand::accent()` do. A value set in the database or in `config.php` skips the form.
4. Add it to [Every setting](settings-and-scss.md#every-setting) and to the user pages. `bin/check-docs` fails until the docs name it.

The theme has no `db/upgrade.php`. Moodle's Notifications page finds a setting that has no value yet and sends the administrator to its "New settings" page (`any_new_admin_settings()` in `<moodle>/admin/index.php`).

## Add a call into Student Records

1. Add a function named `local_studentrecords_theme_<name>` in `local/studentrecords/lib.php`, and put the logic in a class of the plugin.
2. Call it from `theme/studentrecords/layout/drawers.php` with `records::call('theme_<name>', [...], <default>)`. Cast the answer, as the existing calls do.
3. Give a default that leaves the page as it is without the plugin.
4. Test the default. The tests in `theme/studentrecords/tests/local/records_test.php` show how: theme Boost stands in for the plugin.
5. The plugin and the theme have separate releases, so a new theme can meet an old plugin. `component_callback()` gives the default for a function that doesn't exist, so that case needs no code.

## Follow a new Boost

When a new Moodle version comes, compare each of the seven files with Boost's file on that version.

```bash
diff <moodle>/theme/boost/layout/drawers.php theme/studentrecords/layout/drawers.php
```

Then read `<moodle>/theme/boost/templates/drawers.mustache`, `<moodle>/theme/boost/templates/navbar.mustache`, `<moodle>/theme/boost/templates/login.mustache` and `<moodle>/theme/boost/templates/drawer.mustache`, the partial that `theme_studentrecords/drawers` fills. Take what the new version needs, and keep it working on the older versions. [Compatibility](../compatibility.md#add-support-for-a-new-moodle-version) has the whole list.

## Write a child theme

A child theme with `$THEME->parents = ['studentrecords', 'boost']` isn't in this repo, and nothing here tests one. From reading the code:

- `shell::theme_has_drawer()` is true for it, so the plugin's pages rely on the drawer.
- `brand::logo_url()` asks `$PAGE->theme`, and `setting_file_url()` reads the child's own `logo` setting and the child's component. The logo saved in this theme doesn't show, and the compact logo or the initials do.
- `theme_studentrecords_pluginfile()` loads `theme_config::load('studentrecords')` by name, so it serves this theme's file area only.

## The seven files adapted from Boost

| Theme file | Came from | Credit it keeps |
|---|---|---|
| `theme/studentrecords/config.php` | `<moodle>/theme/boost/config.php` | `@copyright 2016 Frédéric Massart - FMCorz.net`, then `@copyright 2026 Clouve Inc.` |
| `theme/studentrecords/lib.php` | `<moodle>/theme/boost/lib.php` | `@copyright 2016 Frédéric Massart - FMCorz.net`, then `@copyright 2026 Clouve Inc.` |
| `theme/studentrecords/layout/drawers.php` | `<moodle>/theme/boost/layout/drawers.php` | `@copyright 2021 Bas Brands`, then `@copyright 2026 Clouve Inc.` |
| `theme/studentrecords/layout/login.php` | `<moodle>/theme/boost/layout/login.php` | `@copyright 2016 Damyon Wiese`, then `@copyright 2026 Clouve Inc.` |
| `theme/studentrecords/templates/drawers.mustache` | `<moodle>/theme/boost/templates/drawers.mustache` | "Adapted in 2026 from theme_boost/drawers, under the GNU GPL." |
| `theme/studentrecords/templates/login.mustache` | `<moodle>/theme/boost/templates/login.mustache` | "Adapted in 2026 from theme_boost/login, under the GNU GPL." |
| `theme/studentrecords/templates/navbar.mustache` | `<moodle>/theme/boost/templates/navbar.mustache` | "Adapted in 2026 from theme_boost/navbar, under the GNU GPL." |

Boost's templates name no author, so the three templates keep the GPL header and say what they came from.

What each one changes:

- `theme/studentrecords/config.php`: the same options and layouts, shortened with `$sidepre`, plus `parents`, the theme's own `scss` and `prescsscallback`.
- `theme/studentrecords/lib.php`: three functions follow `theme_boost_get_main_scss_content()`, `theme_boost_get_pre_scss()` and `theme_boost_pluginfile()`. The main SCSS always uses the default preset, the pre-SCSS sets the accent, and the file callback serves only `logo`.
- The layouts and templates: see [the code map](README.md#layout-files).

## Why the theme stays GPL

The whole theme is under the GNU GPL, version 3 or later, and `theme/studentrecords/LICENSE` has the full text. The repo's root `LICENSE` and the two local plugins are under the MIT License.

`CONTRIBUTING.md` says why: a plugin that copies or adapts a substantial part of Moodle's code is under the GNU GPL as a whole. The seven files above adapt Boost and keep Boost's authors. The theme can move to MIT only if those seven files are rewritten.

What that means for a change:

- Keep the GPL header and the author lines of every file you copy or adapt, and say what you changed. Don't remove `@copyright` lines of Boost's authors.
- Every PHP file of the theme keeps the Moodle GPL header, including new ones. `theme/studentrecords/scss/studentrecords.scss` has no licence header.
- Copy no theme code into `local/academictranscript` or `local/studentrecords`, which are under MIT. Those two have no GPL header, and `phpcbf` would write one in. See [Conventions](../conventions.md#file-headers).
- `phpcs` runs with `--exclude moodle.Files.BoilerplateComment` on the whole repo, so it doesn't check the theme's header either. Checking it is on you.

## Related pages

- [Code map](README.md)
- [Settings and SCSS](settings-and-scss.md)
- [Student Records and the theme](integration.md)
- [Compatibility](../compatibility.md)
- [Decisions](../decisions.md)
- [Testing](../testing.md)
