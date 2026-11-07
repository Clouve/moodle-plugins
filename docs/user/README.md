# User manual

This manual teaches you to use three Moodle plugins: Student Records, Academic Transcript and the Student Records theme. It is for administrators, registrars, admissions staff, finance and financial aid staff, teachers, students, and anyone who checks a transcript.

## Contents

- [The three plugins](#the-three-plugins)
- [Where to start](#where-to-start)
- [Pages for every plugin](#pages-for-every-plugin)
- [Student Records](#student-records)
- [Academic Transcript](#academic-transcript)
- [Student Records theme](#student-records-theme)
- [How to read this manual](#how-to-read-this-manual)
- [Related pages](#related-pages)

## The three plugins

| Plugin | What it is | Version |
|---|---|---|
| [Student Records](student-records/README.md) | A student information system inside Moodle: admissions, registration, grades, attendance, billing, aid, graduation | `local_studentrecords` 1.4.6 |
| [Academic Transcript](academic-transcript/README.md) | Unofficial and Official transcripts, with a request queue and public verification | `local_academictranscript` 1.4.3 |
| [Student Records theme](theme/README.md) | The school's name, logo and colour on Moodle, with the Student Records menu and cards | `theme_studentrecords` 1.0.3 |

Each works without the others. Together, Student Records feeds the transcript, and the theme puts the menu in a left drawer. This manual was written for Moodle 5.3, and the plugins run on Moodle 4.5 to 5.3.

## Where to start

| You are | Start here | Then |
|---|---|---|
| An administrator setting up a site | [Install and set up Student Records](student-records/install-and-setup.md), [Install and set up Academic Transcript](academic-transcript/install-and-setup.md) and [the theme](theme/README.md) | [Setup wizard](student-records/setup-wizard.md), [Roles and permissions](roles-and-permissions.md) |
| A registrar | [Registrar's guide](student-records/registrar.md) | [Registrar guide for transcripts](academic-transcript/registrar-guide.md), [The student file](student-records/student-file.md) |
| Admissions staff | [Admissions guide](student-records/admissions.md) | [Admissions process](student-records/admissions-process.md) |
| Finance staff | [Finance guide](student-records/finance.md) | [Student accounts](student-records/student-accounts.md), [Refunds](student-records/refunds.md) |
| Financial aid staff | [Financial aid guide](student-records/financial-aid.md) | [Federal aid](student-records/federal-aid.md), [Aid helpers](student-records/aid-helpers.md) |
| A teacher | [Teacher's guide](student-records/teacher.md) | [Teacher guide for transcripts](academic-transcript/teacher-guide.md), [Attendance](student-records/attendance.md) |
| A student | [The student's guide](student-records/student.md) | [Student guide for transcripts](academic-transcript/student-guide.md), [On phones](theme/on-phones-and-dark-mode.md) |
| Anyone checking a transcript | [Verify a transcript](academic-transcript/verify.md) | [Reading a transcript](academic-transcript/reading-a-transcript.md) |

If your school uses Academic Transcript without Student Records, start with [Academic Transcript](academic-transcript/README.md) and its guides.

## Pages for every plugin

| Page | What it covers |
|---|---|
| [Roles and permissions](roles-and-permissions.md) | Every capability, the roles to make, each role's menu, the rules no role changes |
| [Troubleshooting](troubleshooting.md) | What can go wrong, why, and what to do |
| [Glossary](glossary.md) | The words these plugins use |

## Student Records

The [Student Records guide](student-records/README.md) says what the plugin does, and where each role starts.

| Setup | |
|---|---|
| [Install and set up](student-records/install-and-setup.md) | Requirements, the install, Moodle roles, cron, the start page |
| [Setup wizard](student-records/setup-wizard.md) | All 11 steps and every question |
| [Import from a spreadsheet](student-records/import.md) | Spreadsheets and CSV files, drafts, approving and rejecting |

| Guides by role | |
|---|---|
| [Registrar's guide](student-records/registrar.md) | The Registrar's year |
| [Admissions guide](student-records/admissions.md) | From inquiry to registered student |
| [Finance guide](student-records/finance.md) | Billing, accounts, plans, refunds and the year-end file |
| [Financial aid guide](student-records/financial-aid.md) | From the first award to a student who withdraws |
| [Teacher's guide](student-records/teacher.md) | Classes, attendance, students at risk, final grades |
| [The student's guide](student-records/student.md) | Your record, classes, account and documents |

| The school | |
|---|---|
| [Terms and the calendar](student-records/terms-and-calendar.md) | Every date on a term, and what it does |
| [Grading and levels](student-records/grading-and-levels.md) | The scale, marks, levels and pass rules |
| [Programs and courses](student-records/programs-and-courses.md) | Programs, course lists and the catalog |
| [Course offerings](student-records/course-offerings.md) | Sections, rosters and linked Moodle courses |
| [Faculty and staff](student-records/faculty-and-staff.md) | Staff records, credentials, qualified courses, the roster |

| Students and records | |
|---|---|
| [The student file](student-records/student-file.md) | Every tab and field, history, View as student |
| [Admissions process](student-records/admissions-process.md) | The checklist, decisions, conditions, waitlists |
| [Registration](student-records/registration.md) | Staff and online registration, waitlists, drops, withdrawals |
| [Final grades](student-records/final-grades.md) | Three ways in, the freeze, grade changes |
| [Attendance](student-records/attendance.md) | Meetings, marks, students at risk |
| [Holds and standing](student-records/holds-and-standing.md) | Holds, standing rules, running standing |
| [Degree audit and graduation](student-records/degree-audit-and-graduation.md) | Audits, substitutions, transfer credit, conferral |
| [FERPA and letters](student-records/ferpa-and-letters.md) | The annual notice, release authorizations, letters |
| [Documents](student-records/documents.md) | Files on a student's file |

| Money and aid | |
|---|---|
| [Billing setup](student-records/billing-setup.md) | The price list, plans, refund rules, billing settings |
| [Student accounts](student-records/student-accounts.md) | Charges, credits, payments, receipts, statements, reversals |
| [Payment plans](student-records/payment-plans.md) | Giving a plan, reminders, late fees and holds |
| [Refunds](student-records/refunds.md) | How a refund comes up, is decided and is paid back |
| [Online payments](student-records/online-payments.md) | Setting up and using Moodle's payment providers |
| [Money reports](student-records/money-reports.md) | Reports, and the 1098-T |
| [Federal aid](student-records/federal-aid.md) | Awards, disbursements, runs, letters, reports |
| [Aid helpers](student-records/aid-helpers.md) | The loan check and the Return of aid worksheet |

| Running it | |
|---|---|
| [Messages](student-records/messages.md) | Each message, test mode, the message log, cron |
| [Reports and exports](student-records/reports-and-exports.md) | Reports, saved exports, counts, the Clearinghouse file |
| [Audit log](student-records/audit-log.md) | Who did what, what goes in the log, and how to read it |
| [Privacy and uninstall](student-records/privacy-and-uninstall.md) | What's stored, data requests, removing the plugin |

## Academic Transcript

The [Academic Transcript guide](academic-transcript/README.md) says what the plugin does, and where each role starts.

| Page | What it covers |
|---|---|
| [Install and set up](academic-transcript/install-and-setup.md) | Requirements, the install, the six wizard steps, what to do after them |
| [Transcript settings](academic-transcript/settings.md) | Every setting and its default |
| [Grading scale and marks](academic-transcript/grading-and-marks.md) | The scale, marks, rounding, repeats, GPA |
| [Programs and the course catalog](academic-transcript/programs-and-catalog.md) | Programs, the catalog, CSV files, Drafts to review, AI documents |
| [Terms and course fields](academic-transcript/terms-and-course-fields.md) | Terms, course fields, the Course codes check, Course terms, the command line script |
| [Student guide](academic-transcript/student-guide.md) | My transcript, the Unofficial PDF, requests |
| [Teacher guide](academic-transcript/teacher-guide.md) | Transcript codes, posting, changes, issuing |
| [Registrar guide](academic-transcript/registrar-guide.md) | The request queue |
| [Admin guide](academic-transcript/admin-guide.md) | Student details, transfer credit, approvals, the issued list, the log |
| [Verify a transcript](academic-transcript/verify.md) | The public page and QR codes |
| [Reading a transcript](academic-transcript/reading-a-transcript.md) | Every block of the PDF |
| [With Student Records](academic-transcript/with-student-records.md) | What changes with Student Records as the source |
| [Privacy and uninstall](academic-transcript/privacy-and-uninstall.md) | What's stored, data requests, removing the plugin |

## Student Records theme

| Page | What it covers |
|---|---|
| [Student Records theme](theme/README.md) | Install, what changes on each kind of page, with and without Student Records |
| [Theme settings](theme/settings.md) | Every setting, and the contrast rule for the accent colour |
| [On phones and in dark mode](theme/on-phones-and-dark-mode.md) | What changes at each width, the tab row, the menu on a phone, dark mode |

## How to read this manual

Task sections have the same shape: who can do it, what must be set up first, the steps, how to check that it worked, and what can go wrong.

- A path such as Site administration > Plugins > Local plugins means: select each of these in turn.
- Words in double quotes are the exact text of a button, a link or a message.
- A name like `local/studentrecords:viewlog` is a capability. See [Roles and permissions](roles-and-permissions.md).
- The school in the screenshots and its people are made up.
- A line that starts with "Not walked through" marks something that needs the real thing, such as a real payment. The steps there come from the code.
- A line that starts with "Known problem in" tells you about a bug in that release, and what to do instead.

Aid helpers and tax files are only helpers. This manual gives no legal, tax, FERPA or federal aid advice.

## Related pages

- [Troubleshooting](troubleshooting.md)
- [Glossary](glossary.md)
- [Roles and permissions](roles-and-permissions.md)
