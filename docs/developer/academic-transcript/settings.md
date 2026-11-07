# Settings

This page covers every setting and config key of Academic Transcript, where each one is written and read, the admin tree, and how to add a setting. The code is in `local/academictranscript/settings.php`, `local/academictranscript/setup.php` and `transcript::setting()`, and all names are in the config of `local_academictranscript`.

## Contents

- [How settings work here](#how-settings-work-here)
- [The admin tree](#the-admin-tree)
- [All settings at a glance](#all-settings-at-a-glance)
- [School and registrar](#school-and-registrar)
- [Grades and hours](#grades-and-hours)
- [Official transcript fees](#official-transcript-fees)
- [Look of the transcript](#look-of-the-transcript)
- [Signature, seal and digital signature](#signature-seal-and-digital-signature)
- [Transcript Key](#transcript-key)
- [Keys that aren't on the settings page](#keys-that-arent-on-the-settings-page)
- [How to add a setting](#how-to-add-a-setting)
- [Related pages](#related-pages)

## How settings work here

There are two ways to write a setting:

- The page Transcript settings, built in `local/academictranscript/settings.php`. It has no capability of its own, so Moodle uses `moodle/site:config`, and it is for site administrators only. A manager has every other page of the plugin and not this one.
- The setup wizard, `local/academictranscript/setup.php`, which needs `local/academictranscript:configure`. It writes the eight school details and the seven settings of the step Transcript look, through `form\setup_school` and `form\setup_look`. Those are the only settings a manager can change.

Code reads a setting in one of three ways:

| Read | Use |
|---|---|
| `transcript::setting($name, $default)` | Trims the value and returns the default when it is empty. Use this for text |
| `get_config('local_academictranscript', $name)` | The raw value, `false` when unset. Used where the exact value matters, such as `source` and `roundpercent` |
| A file area, through `builder::setting_file()` or `signer::credentials()` | For `logo`, `signatureimage`, `sealimage` and `signingcert`. The config key of a file setting is never read |

The install writes one setting, `setupcomplete`, and no other. The defaults in `local/academictranscript/settings.php` reach the config when an administrator saves the New settings page, or when an install applies the defaults. So code has to work with a setting that isn't set: `transcript::setting()` with a default, or a check like the ones in `scale::calculator_for()`. Test: `install_test::test_install_writes_only_the_setup_flag()`.

## The admin tree

`local/academictranscript/settings.php` adds the category `local_academictranscript_category`. Its parent is `localplugins` when that node exists for the current user, else `modules`. Moodle 4.5 adds `localplugins` only for users who can configure the site, and Moodle 5.3 adds it for everyone, so on 4.5 a manager sees the category directly under Plugins.

| Node name | File | Title string | Capability |
|---|---|---|---|
| `local_academictranscript_setup` | `local/academictranscript/setup.php` | `setupwizard` | `local/academictranscript:configure` |
| `local_academictranscript` | `local/academictranscript/settings.php` | `settings_header` | `moodle/site:config` |
| `local_academictranscript_grading` | `local/academictranscript/grading.php` | `gradingscale_title` | `local/academictranscript:configure` |
| `local_academictranscript_marks` | `local/academictranscript/marks.php` | `marks_title` | `local/academictranscript:configure` |
| `local_academictranscript_programs` | `local/academictranscript/programs.php` | `programs` | `local/academictranscript:configure` |
| `local_academictranscript_catalog` | `local/academictranscript/catalog.php` | `catalog` | `local/academictranscript:configure` |
| `local_academictranscript_drafts` | `local/academictranscript/drafts.php` | `draftspage` | `local/academictranscript:configure` |
| `local_academictranscript_terms` | `local/academictranscript/terms.php` | `terms` | `local/academictranscript:managerecords` |
| `local_academictranscript_students` | `local/academictranscript/students.php` | `studentstranscripts` | `local/academictranscript:managerecords` or `local/academictranscript:issueofficial` |
| `local_academictranscript_requests` | `local/academictranscript/requests.php` | `queue_title` | `local/academictranscript:issueofficial` |
| `local_academictranscript_approvals` | `local/academictranscript/approvals.php` | `approvals` | `local/academictranscript:approvechanges` |
| `local_academictranscript_issued` | `local/academictranscript/issued.php` | `issuedlog` | `local/academictranscript:viewissued` |
| `local_academictranscript_log` | `local/academictranscript/log.php` | `activitylog` | `local/academictranscript:viewissued` |
| `local_academictranscript_coursecheck` | `local/academictranscript/coursecheck.php` | `coursecheck` | `local/academictranscript:viewissued` |

An entry with an array of capabilities needs any one of them. Tests: `settings_test::test_students_page_accepts_issue_capability()`, `settings_test::test_students_page_accepts_manage_records_capability()`, `settings_test::test_students_page_refuses_other_capabilities()`.

## All settings at a glance

| Setting | Type | Default | Also in the wizard |
|---|---|---|---|
| [`schoolname`](#schoolname) | text | empty | School details |
| [`address`](#address) | textarea | empty | School details |
| [`phone`](#phone) | text | empty | School details |
| [`email`](#email) | text | empty | School details |
| [`website`](#website) | text | empty | School details |
| [`logo`](#logo) | stored file | none | School details, the file area only |
| [`registrarname`](#registrarname) | text | empty | School details |
| [`registrartitle`](#registrartitle) | text | empty | School details |
| [`source`](#source) | select | `own` | no |
| [`roundpercent`](#roundpercent) | checkbox | 1 | Transcript look |
| [`repeatrule`](#repeatrule) | select | `latest` | Transcript look |
| [`creditlabel`](#creditlabel) | text | `creditlabel_default` | Transcript look |
| [`clocklabel`](#clocklabel) | text | `clocklabel_default` | Transcript look |
| [`fee_email`](#fee_email) | text, float | 5 | no |
| [`fee_mail`](#fee_mail) | text, float | 10 | no |
| [`fee_pickup`](#fee_pickup) | text, float | 10 | no |
| [`pagesize`](#pagesize) | select | `LETTER` | Transcript look |
| [`accentcolor`](#accentcolor) | colour picker | `#1F3A5F` | Transcript look |
| [`footer`](#footer) | textarea | `footer_default` | no |
| [`signaturemode`](#signaturemode) | select | `lines` | Transcript look |
| [`signatureimage`](#signatureimage) | stored file | none | no |
| [`sealimage`](#sealimage) | stored file | none | no |
| [`signingcert`](#signingcert) | stored file | none | no |
| [`signingcertpassword`](#signingcertpassword) | password | empty | no |
| [`key_scalenote`](#key_scalenote) | text | empty | no |
| [`key_gpa`](#key_gpa) | textarea | `key_gpa_default` | no |
| [`key_units_title`](#key_units_title) | text | `key_units_title` | no |
| [`key_units`](#key_units) | textarea | `key_units_default` | no |
| [`key_coursenumbers`](#key_coursenumbers) | textarea | `key_coursenumbers_default` | no |
| [`key_accreditation`](#key_accreditation) | textarea | `key_accreditation_default` | no |
| [`key_real`](#key_real) | textarea | `key_real_default` | no |
| [`key_privacy`](#key_privacy) | textarea | `key_privacy_default` | no |
| [`key_contact`](#key_contact) | textarea | `key_contact_default` | no |

The six headings of the page are `schoolheading`, `gradesheading`, `feesheading`, `lookheading`, `sigheading` and `keyheading`.

## School and registrar

These print on every transcript, and they are read live on each draw, so a later change shows on an old Official copy too. See [what stays live](pdf-and-signing.md#what-is-frozen-and-what-stays-live). The wizard requires `schoolname`, and the settings page doesn't.

### `schoolname`

`PARAM_TEXT`. Printed in the PDF header and on the key, used as the PDF author, in the signature's `Name`, in the self-signed certificate, and on the verify page. `transcript::school_name()` reads it, and falls back to the full name of the site when it is empty. Tests: `transcript_test::test_school_name_is_the_setting_when_there_is_one()`, `transcript_test::test_school_name_falls_back_to_the_site_name_as_plain_text()`.

### `address`

`PARAM_TEXT`, one line per row. Printed in the header line, where `builder::record_header()` turns the line breaks into commas. Also the signature's `Location`.

### `phone`

`PARAM_TEXT`. Printed in the header line, and in the contact line of the verify page.

### `email`

`PARAM_TEXT`. Printed in the header line, in the contact line of the verify page, and used as the signature's `ContactInfo`.

### `website`

`PARAM_TEXT`. Printed in the header line.

### `logo`

File area `logo`, one file, `.png`, `.jpg` or `.jpeg`. Printed at 19 x 19 mm, top left. The wizard writes the file area with `file_save_draft_area_files()` and not the config key, so only the file area counts.

### `registrarname`

`PARAM_TEXT`. Printed in the signature block.

### `registrartitle`

`PARAM_TEXT`. Printed in the signature block.

## Grades and hours

### `source`

`own` or `studentrecords`, from `srsource::menu()`. Read only by `srsource::enabled()`, which also needs Student Records installed. The option is offered even when it isn't installed, and then it has no effect. This setting works per student: with it on, a student without a file keeps this plugin's data. See [with and without Student Records](integration.md#with-and-without-student-records).

Changing it moves no data. Grades posted earlier stay in this plugin and become problems that stop Official transcripts, until they are in Student Records.

### `roundpercent`

Checkbox, on by default. On rounds the course total to a whole percent before the scale applies. `scale::calculator_for()` treats it as on unless the value is exactly `'0'`, so unset means on. `catalog::round_percent()` in Student Records reads it too. See [letter from a percentage](calculations.md#letter-from-a-percentage). The string `roundpercent_desc` gives an example that fits a scale with A at 94, not the standard scale, where A starts at 93.

### `repeatrule`

`latest`, `highest` or `all`, from `settings_menus::repeatrules()`. `scale::calculator_for()` reads it, and `catalog::repeat_rule()` in Student Records reads it too. See [repeated courses](calculations.md#repeated-courses). The default text of `key_gpa` doesn't follow this setting: a school on `highest` or `all` has to edit that text.

### `creditlabel`

`PARAM_TEXT`. `programs::unit_label()` reads it, for a transcript of a credit-hour program. It prints as the Calendar value. Empty falls back to the string `creditlabel_default`.

### `clocklabel`

`PARAM_TEXT`. The same for a clock-hour program, with the string `clocklabel_default`.

## Official transcript fees

`local/academictranscript/settings.php` builds these three from `requests::DELIVERY`, as `PARAM_FLOAT` text boxes with no description. `requests::fee()` reads them: an empty value means the default, a number is rounded to 2 places and floored at 0, and anything else gives the default. So empty isn't free, and 0 is.

A request keeps the fee of the day it was made. The money sign is in the string `req_money`.

### `fee_email`

Fee of a transcript by email. Default 5.

### `fee_mail`

Fee of a transcript by mail. Default 10.

### `fee_pickup`

Fee of a transcript picked up. Default 10.

## Look of the transcript

### `pagesize`

`LETTER` or `A4`, from `settings_menus::pagesizes()`. `builder::page_format()` reads it.

### `accentcolor`

`admin_setting_configcolourpicker`. `builder::setup_page()` reads it, and a value that isn't six hex digits falls back to `1F3A5F`. The wizard's form checks it with `/^#?[0-9a-fA-F]{6}$/`.

### `footer`

`PARAM_TEXT`, 80 x 3. `builder::footer_html()` prints it at the bottom of each record page, and an empty value falls back to the string `footer_default`. That default names a US law and is a starting point for the school to edit.

## Signature, seal and digital signature

### `signaturemode`

`lines` or `images`, from `settings_menus::signaturemodes()`. `builder::signature_block()` reads it.

### `signatureimage`

File area `signatureimage`, one file, `.png`, `.jpg` or `.jpeg`. Printed only in `images` mode.

### `sealimage`

File area `sealimage`, one file, `.png`, `.jpg` or `.jpeg`. Printed only in `images` mode.

### `signingcert`

File area `signingcert`, one file, `.p12` or `.pfx`. `signer::credentials()` reads it. `local_academictranscript_pluginfile()` never serves this area.

### `signingcertpassword`

`admin_setting_configpasswordunmask`. `signer::credentials()` reads it. A wrong password shows no message: the plugin signs with the self-signed certificate, and only `debugging()` says so. See [certificates](pdf-and-signing.md#certificates).

## Transcript Key

The text of the last page. `builder::key_page()` reads each one with `transcript::setting()` and falls back to the `_default` string, so emptying a section doesn't remove it. `{school}` and `{verifyurl}` are replaced in the text sections and in the contact box. The defaults are written for a US school, and the text in brackets prints as it is until someone edits it.

### `key_scalenote`

`PARAM_TEXT`. Small print under the grade table. It is the only key setting that can be empty.

### `key_gpa`

Section "How GPA Is Figured". The default says that only the latest grade counts, whatever `repeatrule` says.

### `key_units_title`

Title of the hours section. It is the only section title that can be changed. `builder::key_page()` looks up a `_title` setting for every section, and this is the only one on the settings page.

### `key_units`

Section on credit and clock hours.

### `key_coursenumbers`

Section on course numbers.

### `key_accreditation`

Section on licensing and accreditation.

### `key_real`

Section "Is This Transcript Real?".

### `key_privacy`

Section on privacy.

### `key_contact`

The contact box at the foot of the key. Its title is always the string `key_contact_title`.

## Keys that aren't on the settings page

| Key | Written by | Read by |
|---|---|---|
| `setupcomplete` | `xmldb_local_academictranscript_install()` writes 0 when it is unset. `local/academictranscript/setup.php` writes 1 on Finish. No screen sets it back | `access::require_setup()`, `local/academictranscript/index.php` |
| `docbatch` | `local/academictranscript/setup.php`, up by 1 on each document upload | `local/academictranscript/setup.php`, as the item id of the file area `sourcedocs` |
| `key_gpa_title`, `key_coursenumbers_title`, `key_accreditation_title`, `key_real_title`, `key_privacy_title` | Nothing | `builder::key_page()` looks them up, finds nothing, and uses the language string |

`setupcomplete` set to 0 sends holders of `local/academictranscript:configure` to the wizard from five pages. See [the guard of each page](access.md#the-guard-of-each-page).

## How to add a setting

1. Add it in `local/academictranscript/settings.php`, inside `if ($ADMIN->fulltree)`, under the right heading. This is the shape, from that file:

   ```php
   $settings->add(new admin_setting_configtext("$p/phone", $str('phone'), $str('phone_desc'), '', PARAM_TEXT, 30));
   ```

2. Add the strings to `local/academictranscript/lang/en/local_academictranscript.php`, sorted by key. moodle-cs checks the order.
3. Read it with `transcript::setting()`, with a default. Remember that an existing site has no value until an administrator saves the new setting.
4. If the wizard should offer it, add a field to `form\setup_school` or `form\setup_look`, and add the name to the list in `local/academictranscript/setup.php`. Without that, only a site administrator can change it.
5. If Student Records reads it, say so on [the integration page](integration.md#what-student-records-uses-from-this-plugin).
6. A new setting needs no upgrade step. `any_new_admin_settings()` finds it, and Moodle's admin index sends an administrator to the New settings page.
7. Add it to this page. `bin/check-docs` fails when the docs name no setting of `local/academictranscript/settings.php`.

Never rename a setting. Sites keep their values under the old name.

## Related pages

- [Code map](README.md)
- [Access](access.md)
- [Execution flows](execution-flows.md#the-setup-wizard-and-its-drafts)
- [PDF and signing](pdf-and-signing.md)
- [Calculations](calculations.md)
- [Extending](extending.md)
