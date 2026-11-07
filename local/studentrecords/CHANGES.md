# Changes

Release notes for Student Records, newest first.

## 1.4.6

First public release.

Student Records is a student information system inside Moodle. Moodle keeps the teaching and the gradebook, and Student Records keeps the official record.

- A setup wizard, and import from a spreadsheet or CSV file, where each item is a draft until staff approve it.
- Programs in credit hours or clock hours, a course catalog, terms, and the school's own grading scale, marks and pass rules.
- One file per student with a change history, plus admissions, holds, academic standing, transfer credit and "View as student".
- Faculty and staff records, with "No LMS access" for staff who never use courses.
- Course offerings and registration by staff, with optional self-registration and waitlists. Registering enrols the student in the linked Moodle course, unless the billing rule says to wait for a payment, and a drop suspends the enrolment. Self-registration is for admitted, enrolled and active students.
- Final grades from teachers, the gradebook or staff. A term's grade freeze limits changes to those that a second person approves. With Academic Transcript installed, repeats follow its repeat rule. Without it, every attempt counts.
- Degree audits, graduation, attendance, enrollment verification letters, FERPA notices and release authorizations.
- Billing: a price list, student accounts, online payments through Moodle's payment providers, receipts, payment plans, late fees, refunds with approval, money reports and a 1098-T spreadsheet.
- Automatic messages that hold no grades or amounts, with a test mode.
- Reports, saved exports for state agencies and accreditors, and a National Student Clearinghouse file.
- An optional federal aid reporting module, with a yearly loan check and a Return of aid worksheet that follow the 2026-27 federal rules. An aid expert should check them before a school relies on them.
- Role home pages and a menu by role, with cards on the Dashboard and course pages when the Student Records theme is in use. The pages follow Moodle 5.3's dark colour mode.
- Support for Moodle's privacy tools. A deletion request removes what a school doesn't have to keep, and the README lists what stays.
- An uninstall that puts back the start page and removes the Finance and Financial Aid roles.

It works with Moodle 4.5 to 5.3, PHP 8.1 to 8.4, and PostgreSQL, MySQL and MariaDB. It stores no Social Security numbers. It is under the MIT License.

This release keeps money, seats and grades correct when several people act at once. Refunds, payouts, reversals, new plans, seats and seat offers run under a lock, an online payment is checked against the request and the payer, and amounts, GPAs and rates are the same on every PHP version. A payment that reached Moodle but not the account is recorded by a task within minutes, and a mail server that hangs can't hold up a payment, because messages written during other work go through cron.
