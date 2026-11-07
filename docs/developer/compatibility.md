# Compatibility

This page says what differs across Moodle 4.5, 5.1, 5.2 and 5.3 and PHP 8.1 to 8.4, how the code handles each difference, and how to add support for a new version. Facts about Moodle come from its own source at 4.5.15+, 5.1.8+, 5.2.4+ and 5.3+. Moodle 5.0 wasn't read.

## Contents

- [Moodle and PHP versions](#moodle-and-php-versions)
- [How the code handles a difference](#how-the-code-handles-a-difference)
- [Folders](#folders)
- [Bootstrap](#bootstrap)
- [The theme by version](#the-theme-by-version)
- [Other differences in Moodle](#other-differences-in-moodle)
- [PHP versions](#php-versions)
- [Databases](#databases)
- [Add support for a new Moodle version](#add-support-for-a-new-moodle-version)
- [Add support for a new PHP version](#add-support-for-a-new-php-version)
- [Drop an old version](#drop-an-old-version)
- [Related pages](#related-pages)

## Moodle and PHP versions

All three plugins have `$plugin->requires = 2024100700` and `$plugin->supported = [405, 503]`.

| Moodle | Branch | Lowest PHP | Bootstrap | PHPUnit | Behat | Plugin folder |
|---|---|---|---|---|---|---|
| 4.5 | `MOODLE_405_STABLE` | 8.1 | 4 | 9.6 | 3.14 | `local/academictranscript` |
| 5.1 | `MOODLE_501_STABLE` | 8.2 | 5 | 11.5 | 3.29 | `public/local/academictranscript` |
| 5.2 | `MOODLE_502_STABLE` | 8.3 | 5 | 11.5 | 3.29 | `public/local/academictranscript` |
| 5.3 | `MOODLE_503_STABLE` | 8.3 | 5 | 11.5 | 3.32 | `public/local/academictranscript` |

The lowest PHP comes from `admin/environment.xml` of each Moodle. The CI rows are in [Testing](testing.md#the-ci-matrix): every version above runs in CI, and no row covers Moodle 5.0, although `$plugin->supported` spans it. `layout/drawers.php` treats 5.0 as older than 5.2, and `test_top_bar` treats it as Bootstrap 5.

The lowest database versions, from the same file:

| Moodle | PostgreSQL | MySQL | MariaDB |
|---|---|---|---|
| 4.5 | 13 | 8.0 | 10.6.7 |
| 5.1 | 15 | 8.4 | 10.11.0 |
| 5.2 | 16 | 8.4 | 10.11.0 |
| 5.3 | 17 | 8.4 | 11.4.0 |

## How the code handles a difference

In this order of preference:

1. Use what all versions have. The two local plugins have no `$CFG->branch` check at all. Hooks such as `\core\hook\navigation\primary_extend`, `\core\url` and the classes in `local/studentrecords/tests/hook_callbacks_test.php` exist on 4.5 and later.
2. Write both forms side by side, when both can be there: `data-toggle` and `data-bs-toggle`, `sr-only visually-hidden`, `.custom-select` and `.form-select`.
3. Leave it to Moodle. The login form and the user menu are Moodle's own, so each version shows its own, and the theme adds only around them.
4. Gate on `$CFG->branch` when none of the above works. The theme has two gates, and the plugins none. See [the theme by version](#the-theme-by-version).
5. Ask the code, not the version. `class_exists()` and `method_exists()` guard the sibling plugins and the AI subsystem.

## Folders

Moodle 5.1 and later keep the web root in `public/`, so a plugin is at `<moodle>/public/local/academictranscript`. Moodle 5.0 and earlier keep it at `<moodle>/local/academictranscript`. `$CFG->dirroot` points at the right place on each, and the code uses it, or `__DIR__`, and never a fixed path.

`admin/cli/` sits at the Moodle root on 5.1 and later too, not in `public/`. The tools in `admin/tool/` moved with the rest, so `docs/tools/site.sh` runs `php admin/cli/upgrade.php` and `php public/admin/tool/phpunit/cli/util.php`.

## Bootstrap

Moodle 4.5 runs Bootstrap 4, and 5.1 and later Bootstrap 5.

| What | Bootstrap 4 | Bootstrap 5 | In the code |
|---|---|---|---|
| A tooltip or dropdown | `data-toggle` | `data-bs-toggle` | `theme/studentrecords/templates/drawers.mustache` writes both |
| Hidden text | `sr-only` | `visually-hidden` | Written as `sr-only visually-hidden` |
| A select | `custom-select` | `form-select` | `theme/studentrecords/scss/studentrecords.scss` styles both |
| The phone menu icon | Needs `navbar-light` | Not needed | `theme/studentrecords/templates/navbar.mustache` keeps it |
| `media-breakpoint-down(sm)` | Up to 767.98px | Up to 575.98px | The theme writes `575.98px` out |

Moodle 4.5 has the spacing utilities of Bootstrap 5, such as `ms-auto` and `me-4`, and Boost's own templates use them, so the plugins use them on every version.

## The theme by version

The two gates in `theme/studentrecords/layout/drawers.php`:

| Key | True on | What it does |
|---|---|---|
| `srnewdrawers` | 5.2, 5.3 | The course name heading, the sidebar icons and the toggle classes |
| `srsearchdivider` | 4.5, 5.1, 5.2 | The divider after the search box |

The rest works by writing both forms, or by leaving it to Moodle:

| Area | 4.5 | 5.1 | 5.2 | 5.3 |
|---|---|---|---|---|
| Drawer toggle classes | `btn icon-no-margin` | Same | `btn btn-icon icon-size-3` | Same |
| Drawer toggle icons | `t/index_drawer`, `t/blocks_drawer`, `t/blocks_drawer_rtl` | Same | `e/sidebar_left`, `e/sidebar_right` | Same |
| `theme_boost/courseindexdrawerheading` | No such template | No such template | Yes | Yes |
| Course index controls go in | `drawerheadercontent` | Same | Same | `drawercontrols` |
| `closebuttonicon` block | Not in Boost's partial, so ignored | Same | Used | Used |
| Divider after the search box | Yes | Yes | Yes | No |
| `output.colour_mode_menu` | No such method, so empty | Same | Same | Shows when turned on |
| `[data-bs-theme="dark"]` | Never set | Never set | Never set | Set when turned on |
| `userfirstname` in the user menu | Not sent | Not sent | Not sent | Sent, and `brand::user_menu()` removes it |
| The login form template | `lib/templates/loginform.mustache`, the old form | Same | `<moodle>/theme/boost/templates/core/loginform.mustache`, the new form | `lib/templates/loginform.mustache`, the new form |
| The forgotten password link | `forgotaccount`, below the button | Same | `loginforgotpassword`, above the button | Same |
| `.d-lg-none` sign-in instructions | No such element | No such element | Applies | Applies |
| OAuth 2 details in the form | No | No | No | Yes, `hasoauth2client` |
| Boost's top bar class | `bg-white` | `bg-body` | `bg-body` | `bg-body` |
| Edit switch in Boost | After the user menu | After the user menu | Before the plugin output | After the colour menu |
| Main tabs in Boost | `core/moremenu` | Same | Same | `core/primarymoremenu`, and the theme stays on `core/moremenu` |
| Secondary tabs in Boost | `core/moremenu` | Same | Same | `core/secondarymoremenu`, and the theme stays on `core/moremenu` |
| Boost's login page | One centred box | Same | Split screen, `core/login_layout` | Same, and the theme keeps one card everywhere |

`admin_setting_configcolourpicker` is a class in `lib/adminlib.php` on 4.5, 5.1 and 5.2. On 5.3 it's an alias through `lib/db/legacyclasses.php`. `setting_accentcolour` extends the old name, as Boost's own `settings.php` still does.

The tests branch by version in `test_course_page_keeps_the_course_index`, `test_top_bar`, `test_top_bar_has_the_colour_mode_menu` and `test_top_bar_divider_after_the_search_box_follows_boost`.

## Other differences in Moodle

| Area | The difference | How the code handles it |
|---|---|---|
| Admin tree | Moodle 4.5 adds the Local plugins category only for users who can configure the site, and 5.3 for everyone | `local/academictranscript/settings.php` puts its category under `localplugins` when it exists, else under `modules`. `local/academictranscript/tests/settings_test.php` builds the tree as an ordinary user |
| AI subsystem | `is_action_available()` is static on 4.5, and an instance method from 5.1 | `ai_extractor::available()` calls it through `\core\di::get()`, which works for both |
| Dark colour mode | Only 5.3 sets `data-bs-theme="dark"` | The plugins' `styles.css` and the theme have a block for it, which does nothing earlier. The `--mds-` properties it reads are Moodle's |
| A debugging message in a page test | 5.3 reports it as a notice only | `run_page()` in `local/academictranscript/tests/pages_test.php` checks for it |
| Test data and `$PAGE` | On 4.5 a message sent while test data is made sets up the theme, and the page can't then set its course | See [page tests](testing.md#page-tests) |
| An AI test response | `response_generate_text` takes an `error` argument on 5.3 and not on 4.5 | `local/academictranscript/tests/local/import/ai_extractor_test.php` asks the constructor |
| PHPUnit | 9.6 ignores attributes, and 11.5 reads them | Each test has both the annotation and the attribute |

`local/studentrecords/tests/schema_test.php` holds table names to 28 characters, and calls that the limit of older Moodle versions. Moodle 4.5's own limit is 53 (`xmldb_table::NAME_MAX_LENGTH`), and Academic Transcript has names of 32.

## PHP versions

Write PHP 8.1 syntax only. Moodle 4.5 runs on it, and it's the lowest of the four. `phplint` runs on each CI row, so a newer form fails on the PHP 8.1 rows.

PHP 8.4 dropped the pre-rounding in `round()`. A float a little under a half no longer rounds up, so `round()` on a float product gives different answers on PHP 8.3 and 8.4:

| Case | PHP 8.3 | PHP 8.4 | Held by |
|---|---|---|---|
| 41.7 quality points summed in floats, over 12 hours | 3.48 | 3.47 | `test_half_cent_gpa_rounds_up` |
| 2.05 x 1.5 | 3.08 | 3.07 | `test_times` |
| 23 of 80 as a percent, one decimal | 28.8 | 28.7 | `local/studentrecords/tests/local/attendance_test.php` |
| 17.9 out of 20 as a letter | A- | B+ | `test_letter_rounds_course_totals_that_end_in_a_half` |

So the plugins do their arithmetic in whole numbers. [Pitfalls](pitfalls.md#exact-rounding-on-every-php-version) has the helpers. CI has rows on PHP 8.3 and 8.4 for this reason.

PHPUnit runs with `--fail-on-warning`, and `phpcs` with no warnings allowed. A nullable parameter has an explicit `?type`, because PHP 8.4 deprecates the implicit form.

## Databases

CI runs PostgreSQL 17, MySQL 8.4 and MariaDB 11. Write for all three.

- MySQL's unique index on course codes ignores case, so a test can't make `bio 101` beside `BIO 101`. `grading::normalise_code()` strips spaces and upper-cases a code, and `calculator::compute()` compares codes in upper case.
- Reserved words differ. `system` installs fine on MySQL 8 as a column and then breaks the first insert. `tests/schema_test.php` in both local plugins lists the words.
- A test on PostgreSQL runs inside a transaction that Moodle rolls back, but on MySQL and MariaDB it doesn't, because they can't roll back a table change. So `$DB->is_transaction_started()` can differ between rows of CI. Tests that care call `preventResetByRollback()`.
- MySQL and MariaDB give the same session the same lock again, so a test can't make a lock busy by taking it itself. Use `refusing_lock_factory`.
- The code uses `$DB->sql_like()`, `$DB->sql_isnotempty()` and `$DB->get_in_or_equal()` and not SQL of one database.

## Add support for a new Moodle version

1. Read `UPGRADING.md` at the root of the new Moodle.
2. Add the version to `bin/check`: the `case $moodle` list, the usage text, and the message for a wrong value. `tests/check.test.sh` looks for that message, so change it too. Then run `tests/run.sh`.
3. Add a row to the matrix in `.github/workflows/ci.yml`, and check its PHP against the new Moodle's `admin/environment.xml`.
4. Run `bin/check --moodle <new>` for every plugin, then with `--together`. Fix what fails, on all versions at once, and keep the older rows green.
5. For the theme, compare the seven files with Boost's. [Follow a new Boost](theme/extending.md#follow-a-new-boost) says how. `test_layouts_match_boost` fails when Boost's layouts change, and `test_template_renders_from_its_example` catches a partial that's gone. Look at the page too, because a test can't see a style that no longer applies.
6. Search the new Moodle for what the plugins use by name: the classes in `local/studentrecords/tests/hook_callbacks_test.php`, the `core_payment` interface in `service_provider`, `core_ai\manager` in `ai_extractor`, and `{payments}` in `payments::recover()`.
7. Change `$plugin->supported` in each `version.php`, the plugin table in `README.md`, the combinations in `CONTRIBUTING.md`, and this page.
8. Release each plugin that changed. See [Release](release.md).

The test site follows one version: `branch` in `docs/tools/site.sh`. Moving it means taking the screenshots in `docs/user` again.

## Add support for a new PHP version

1. Add the version to `bin/check`, in the `case $php` list and the usage text, and to `tests/check.test.sh`.
2. Add a row to `.github/workflows/ci.yml`.
3. Run `bin/check --php <new>` on the Moodle versions that allow it.
4. Read what the new PHP changes about floats and rounding, and run `local/academictranscript/tests/local/calculator_test.php` and `local/studentrecords/tests/local/billing_test.php` first.

## Drop an old version

Change `$plugin->requires` and `$plugin->supported`, the matrix, `bin/check` and the tables here. Then you can take out what only that version needed. For Moodle 4.5 that's the PHP 8.1 rule, the docblock annotations in the tests, `data-toggle`, `sr-only`, `custom-select`, `navbar-light`, and the check of `localplugins` in `local/academictranscript/settings.php`. A site on the dropped version keeps the release it has.

## Related pages

- [Testing](testing.md)
- [Theme extending](theme/extending.md)
- [Pitfalls](pitfalls.md)
- [Release](release.md)
- [Decisions](decisions.md)
