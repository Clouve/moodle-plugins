# Maintaining the docs

A change to a plugin updates its docs in the same pull request. This page says which pages to update, how to retake screenshots, and how the checks keep the docs and the code together.

## Contents

- [The rule](#the-rule)
- [What the checks catch](#what-the-checks-catch)
- [What to update for each kind of change](#what-to-update-for-each-kind-of-change)
- [Which pages describe which code](#which-pages-describe-which-code)
- [Retake screenshots](#retake-screenshots)
- [Lines that mark a limit or a bug](#lines-that-mark-a-limit-or-a-bug)
- [When a plugin is released](#when-a-plugin-is-released)
- [Writing rules](#writing-rules)
- [Related pages](#related-pages)

## The rule

The docs describe the code as released. So:

1. Update the docs in the same pull request as the code.
2. Base each statement on the code, or on something you saw run.
3. Walk a changed workflow on the test site, as the role the guide names, before you write it down.
4. Run `bin/check-docs` and `tests/run.sh`.

If a page and the code disagree and you can't tell which is right, the code wins. Open an issue if the code looks wrong.

## What the checks catch

`bin/check-docs` needs only Python 3, and `tests/docs.test.sh` runs it, so CI fails when it fails. `bin/check-docs --help` lists its checks:

| Check | Fails when |
|---|---|
| `links` | A local link or anchor doesn't resolve, or a page can't be reached from `docs/README.md` |
| `images` | An image is missing, has no alt text or is over 320 KB, an image outside `docs/tools/` is unused, or the docs folder is over 40 MB |
| `paths` | A path in backticks that starts with a folder of the repo or of a plugin, such as `local/` or `classes/`, doesn't exist |
| `symbols` | A `class::method()` in backticks names a plugin class that has no such method. A class it doesn't know, such as one of Moodle's, isn't checked |
| `coverage` | A page, capability, setting, task, table, column, message type, event or class in the code isn't named in the docs |
| `writing` | A page has an em or en dash, a curly quote, an exclamation mark or a line number, a heading isn't in sentence case, or a long page has no contents |

Run one check on one folder while you work:

```bash
bin/check-docs --only links,images,writing docs/user/academic-transcript
```

The checks can't tell you that a sentence is wrong. A renamed button, a changed rule or a new option on an old form passes them. That is your part.

## What to update for each kind of change

`bin/check-docs` catches what the Coverage column says. The rest is up to you.

| You add or change | User docs | Developer docs | Coverage |
|---|---|---|---|
| A page | The area page and role guide, [roles and permissions](user/roles-and-permissions.md) if it has its own access | The code map, the flow page, the access page | Yes |
| A form field or a button | The field table on the area page, and the steps | The flow page, if it changes a rule | No |
| A setting | The settings page or wizard page: label, default, effect | The settings page | Yes |
| A capability | [Roles and permissions](user/roles-and-permissions.md), the guide of each role it touches | The access page, and `db/access.php` needs a higher `$plugin->version` | Yes |
| A table or a column | Nothing, unless a person sees it | The data model page, with a heading per table and each column in backticks | Yes |
| A scheduled or ad-hoc task | The page of the work it does, and [troubleshooting](user/troubleshooting.md) if it can fail | The tasks page | Yes |
| A message | The messages page in Student Records | The tasks and messages page | The provider |
| An event | Nothing | The integration page | Yes |
| A class | Nothing | The code map | Yes |
| An error message | [Troubleshooting](user/troubleshooting.md) | The flow page, if it is a rule | No |
| A rule about who may act | [Roles and permissions](user/roles-and-permissions.md), the area page | The access page, and [pitfalls](developer/pitfalls.md) if it can break silently | No |
| How a figure is worked out | The area page, and `reading-a-transcript.md` for a transcript | The calculations page, [decisions](developer/decisions.md) | No |
| A call between plugins | `with-student-records.md` | [Architecture](developer/architecture.md), both integration pages | No |
| A Moodle or PHP version | The requirements in each install page, [docs/README.md](README.md) | [Compatibility](developer/compatibility.md), [Testing](developer/testing.md#the-ci-matrix) | No |
| A decision that looks odd | Nothing | [Decisions](developer/decisions.md) | No |

## Which pages describe which code

| Code | User docs | Developer docs |
|---|---|---|
| `local/academictranscript/setup.php`, its forms and drafts | [Install and setup](user/academic-transcript/install-and-setup.md), [Grading and marks](user/academic-transcript/grading-and-marks.md), [Programs and catalog](user/academic-transcript/programs-and-catalog.md) | [Execution flows](developer/academic-transcript/execution-flows.md), [Integration](developer/academic-transcript/integration.md) |
| `local/academictranscript/settings.php` | [Settings](user/academic-transcript/settings.md) | [Settings](developer/academic-transcript/settings.md) |
| `local/academictranscript/index.php`, `record.php`, `transfer.php`, `students.php`, `studentterms.php` | [Student guide](user/academic-transcript/student-guide.md), [Admin guide](user/academic-transcript/admin-guide.md) | [Execution flows](developer/academic-transcript/execution-flows.md) |
| `local/academictranscript/post.php`, `codes.php`, `change.php`, `approvals.php` | [Teacher guide](user/academic-transcript/teacher-guide.md), [Admin guide](user/academic-transcript/admin-guide.md) | [Execution flows](developer/academic-transcript/execution-flows.md), [Calculations](developer/academic-transcript/calculations.md) |
| `local/academictranscript/requests.php`, `issued.php` | [Registrar guide](user/academic-transcript/registrar-guide.md) | [Execution flows](developer/academic-transcript/execution-flows.md) |
| `local/academictranscript/verify.php` | [Verify](user/academic-transcript/verify.md) | [Execution flows](developer/academic-transcript/execution-flows.md) |
| `local/academictranscript/classes/local/pdf/` | [Reading a transcript](user/academic-transcript/reading-a-transcript.md) | [PDF and signing](developer/academic-transcript/pdf-and-signing.md) |
| `local/academictranscript/classes/local/calculator.php`, `scale.php` | [Grading and marks](user/academic-transcript/grading-and-marks.md) | [Calculations](developer/academic-transcript/calculations.md) |
| `local/academictranscript/classes/local/srsource.php`, `srpost.php` | [With Student Records](user/academic-transcript/with-student-records.md) | [Integration](developer/academic-transcript/integration.md), [Architecture](developer/architecture.md) |
| `local/studentrecords/setup.php`, `import.php`, `classes/local/importer.php` | [Setup wizard](user/student-records/setup-wizard.md), [Import](user/student-records/import.md) | [Admissions and records flows](developer/student-records/flows-admissions-and-records.md) |
| `local/studentrecords/terms.php`, `grading.php`, `programs.php`, `courses.php`, `offerings.php` | [Terms and calendar](user/student-records/terms-and-calendar.md), [Grading and levels](user/student-records/grading-and-levels.md), [Programs and courses](user/student-records/programs-and-courses.md), [Course offerings](user/student-records/course-offerings.md) | [Registration and grades flows](developer/student-records/flows-registration-and-grades.md) |
| `local/studentrecords/admissions.php`, `checklist.php`, `classes/local/admissions.php` | [Admissions process](user/student-records/admissions-process.md), [Admissions guide](user/student-records/admissions.md) | [Admissions and records flows](developer/student-records/flows-admissions-and-records.md) |
| `local/studentrecords/student.php`, `studentpart.php`, `students.php`, `viewas.php` | [The student file](user/student-records/student-file.md) | [Admissions and records flows](developer/student-records/flows-admissions-and-records.md) |
| `local/studentrecords/register.php`, `myregistration.php`, `classes/local/registration.php`, `selfreg.php`, `waitlist.php` | [Registration](user/student-records/registration.md) | [Registration and grades flows](developer/student-records/flows-registration-and-grades.md) |
| `local/studentrecords/gradechange.php`, `classes/local/grading.php`, `academic.php` | [Final grades](user/student-records/final-grades.md) | [Registration and grades flows](developer/student-records/flows-registration-and-grades.md), [Calculations](developer/student-records/calculations.md) |
| `local/studentrecords/attendance.php`, `classes/local/attendance.php` | [Attendance](user/student-records/attendance.md) | [Registration and grades flows](developer/student-records/flows-registration-and-grades.md) |
| `local/studentrecords/standing.php`, `saprules.php`, `classes/local/standing.php` | [Holds and standing](user/student-records/holds-and-standing.md) | [Calculations](developer/student-records/calculations.md) |
| `local/studentrecords/degreeaudit.php`, `graduation.php`, `classes/local/degreeaudit.php`, `graduation.php` | [Degree audit and graduation](user/student-records/degree-audit-and-graduation.md) | [Calculations](developer/student-records/calculations.md) |
| `local/studentrecords/billingsetup.php` | [Billing setup](user/student-records/billing-setup.md) | [Money flows](developer/student-records/flows-money.md), [Settings](developer/student-records/settings.md) |
| `local/studentrecords/account.php`, `billing.php`, `receipt.php`, `statement.php`, `classes/local/billing.php` | [Student accounts](user/student-records/student-accounts.md) | [Money flows](developer/student-records/flows-money.md) |
| `local/studentrecords/classes/local/plans.php` | [Payment plans](user/student-records/payment-plans.md) | [Money flows](developer/student-records/flows-money.md) |
| `local/studentrecords/refunds.php`, `classes/local/refunds.php` | [Refunds](user/student-records/refunds.md) | [Money flows](developer/student-records/flows-money.md) |
| `local/studentrecords/pay.php`, `classes/payment/`, `classes/local/payments.php` | [Online payments](user/student-records/online-payments.md) | [Money flows](developer/student-records/flows-money.md) |
| `local/studentrecords/billingreports.php`, `classes/local/moneyreports.php` | [Money reports](user/student-records/money-reports.md) | [Money flows](developer/student-records/flows-money.md) |
| `local/studentrecords/aid.php`, `aidletter.php`, `classes/local/aid.php`, `aidhelp.php` | [Federal aid](user/student-records/federal-aid.md), [Aid helpers](user/student-records/aid-helpers.md) | [Aid flows](developer/student-records/flows-aid.md) |
| `local/studentrecords/messages.php`, `classes/local/messages.php`, `db/messages.php` | [Messages](user/student-records/messages.md) | [Tasks and messages](developer/student-records/tasks-and-messages.md) |
| `local/studentrecords/reports.php`, `outsidereports.php`, `classes/local/outside.php` | [Reports and exports](user/student-records/reports-and-exports.md) | [Extending](developer/student-records/extending.md) |
| `local/studentrecords/ferpa.php`, `letter.php`, `classes/local/ferpa.php`, `letters.php` | [FERPA and letters](user/student-records/ferpa-and-letters.md) | [Admissions and records flows](developer/student-records/flows-admissions-and-records.md) |
| `local/studentrecords/log.php`, `classes/local/audit.php` | [Audit log](user/student-records/audit-log.md) | [Admissions and records flows](developer/student-records/flows-admissions-and-records.md) |
| `local/studentrecords/classes/privacy/provider.php` | [Privacy and uninstall](user/student-records/privacy-and-uninstall.md) | [Privacy](developer/student-records/privacy.md) |
| `local/studentrecords/db/access.php`, `classes/local/access.php`, `shell.php` | [Roles and permissions](user/roles-and-permissions.md) | [Access](developer/student-records/access.md) |
| `local/studentrecords/db/install.xml` | Nothing | [Data model](developer/student-records/data-model.md) |
| `local/academictranscript/db/install.xml` | Nothing | [Data model](developer/academic-transcript/data-model.md) |
| `theme/studentrecords/settings.php`, `lib.php`, `scss/` | [Theme settings](user/theme/settings.md) | [Settings and SCSS](developer/theme/settings-and-scss.md) |
| `theme/studentrecords/layout/`, `templates/`, `classes/` | [The theme](user/theme/README.md), [Phones and dark mode](user/theme/on-phones-and-dark-mode.md) | [The theme's code map](developer/theme/README.md), [Integration](developer/theme/integration.md) |
| `bin/`, `tests/`, `.github/workflows/` | Nothing | [Testing](developer/testing.md), [Release](developer/release.md) |

## Retake screenshots

You need Docker, Python 3 and `pdftoppm`, and `pngquant` to make the files small. `bin/check` builds the image `docs/tools/site.sh` needs, the first time it runs.

Start the test site once:

```bash
docs/tools/site.sh up
docs/tools/site.sh seed
```

Retake the pictures of one area, or all of them:

```bash
docs/tools/capture-all.sh docs/tools/capture/transcript_own.py
docs/tools/capture-all.sh
docs/tools/optimize-images.sh
```

Each capture script signs in as the role the guide names, walks the workflows for real and saves its pictures under `docs/user/<plugin>/images/`. So a script that stops has found a workflow that changed. Fix the script and the guide together.

Then:

1. Look at each new picture. Throw it away if it shows debug output, an error, a half-loaded page or a site address.
2. Check it against the text beside it.
3. Run `bin/check-docs --only images`.
4. Stop the site with `docs/tools/site.sh down`.

Rules for a picture:

- PNG, taken in a browser 1280 pixels wide, or 390 wide where a phone matters. Aim for under 300 KB. The check stops at 320 KB.
- Named for the task and step, in lowercase with hyphens.
- A real capture. Never draw, edit or mock one. Cropping is fine.
- The school is Riverside College, the people are made up, and addresses use `example.com`. Never use real student data.
- Alt text says what the picture shows.

[The tools page](tools/README.md) lists the people and the data, and says how to add a capture script.

## Lines that mark a limit or a bug

Two kinds of line stand out on the user pages, and a script can find both:

```bash
grep -rn '^> Not walked through:' docs/user
grep -rn '^> Known problem in' docs
```

`> Not walked through:` marks a step that no test site can do, such as a real payment. Its steps come from the code. If you walk one for real, take the line out.

`> Known problem in <release>:` marks a bug a reader will meet. [Troubleshooting](user/troubleshooting.md) has an entry for each one: a line of the same kind, or a row that says "A known problem in" and the release. `grep -in 'known problem in' docs/user/troubleshooting.md` finds both. When you fix the bug, delete the line and its troubleshooting entry, retake the pictures of that page, and take the bug out of [Bugs in this release](developer/pitfalls.md#bugs-in-this-release).

## When a plugin is released

1. Update the release in the table in [docs/README.md](README.md), and the versions at the top of [the developer docs](developer/README.md).
2. Search for the old release number, and update what still names it.
3. Check the `> Known problem in` lines.
4. If Moodle's version on the test site changed, say so in [docs/README.md](README.md), and retake the pictures.

[Release](developer/release.md) has the rest.

## Writing rules

The rules are in `CONTRIBUTING.md`: plain words, short sentences, sentence case, no em or en dashes, no curly quotes, no exclamation marks.

- Say "you" in the user docs. Name a screen or a button exactly as the language strings do.
- Write each task the same way: who can do it, what must be set up first, the steps, what you see when it worked, and what can go wrong.
- A task's steps live in one place. A role guide links to the area page.
- Give no legal, tax, FERPA or federal aid advice. The Return of aid worksheet and the loan checks are helpers.
- Name files by their repo path and code by its symbol. Don't use line numbers.
- Copy code samples from the repo, or run them.
- Keep the user set and the developer set apart. Link across only where a reader needs it.
- Never name a school, a person or a test account. The repo is public.

## Related pages

- [Documentation](README.md)
- [Test site tools](tools/README.md)
- [Testing](developer/testing.md)
- [Release](developer/release.md)
- [AI agents](developer/ai-agents.md)
