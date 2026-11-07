# Student Records

Student Records (`local_studentrecords`) is a student information system that runs inside Moodle, for Moodle 4.5 to 5.3. Moodle keeps the teaching and the gradebook, and Student Records keeps the official record. It is built for colleges, universities and vocational schools in the United States.

Nothing about a particular school is built in. Names, the calendar, grading, rules, programs, courses, prices and refund rules come from the setup wizard or from the school's own spreadsheet.

[Clouve Inc.](https://clouve.com) maintains the plugin. It is licensed under the MIT License.

## What it does

- The setup wizard takes the school through its details, approvals, calendar, grading, programs, courses, admissions, billing, staff and import. Every answer can be changed later. Each item imported from a spreadsheet (.xlsx) or CSV file is a draft until staff approve it.
- Programs use credit hours or clock hours. The course catalog has prerequisites, terms have add and drop and withdraw dates, and the school sets its own grading scale and pass rules.
- Each student has one file with a change history: admissions, documents, holds, academic standing, transfer credit and graduation. Staff can open "View as student" to see what the student sees.
- Admissions covers inquiries, applicants, a document checklist, decisions and waitlists. A person has a student record from the first inquiry, and each decision moves it along.
- Faculty and staff records hold degrees and licenses with expiry dates and the courses each teacher may teach.
- Registration runs on course offerings with days, times, rooms and seats. Staff register students. A school can also let students register themselves, with a cart, a cost estimate, a time clash check and waitlists. That is off by default, and it is open only to admitted, enrolled and active students. Anyone else reads why and goes through the registrar's office.
- Registering enrols the student in the linked Moodle course, unless the course access rule in Billing setup says to wait for a payment. A drop or withdrawal suspends the enrolment and never removes it.
- Final grades come from teachers, from the Moodle gradebook or from staff. Staff can correct a grade until the term's grade freeze, and the change history keeps the old value. After the freeze, a change needs a second person to approve it.
- Degree audits, graduation applications and conferral with honors. A program with no course list is audited on hours and GPA: only courses in the program's unit count, each course counts once however many times it was passed, transfer credit counts when it is marked to count toward the program, and a course and its transfer equivalent count once. A program with no course list, no total hours and no minimum GPA has nothing to audit, so approving needs a note.
- Attendance: class meetings, present, late, absent or excused, hours attended, last date of attendance and students at risk.
- Billing: a price list, charges when a student registers, student accounts and statements, online payments through Moodle's payment providers, payments recorded by staff, receipts, payment plans, late fees and holds, refunds by the school's own rules with approval, money reports and a 1098-T spreadsheet. Amounts are in US dollars. Nobody can decide a refund on their own account.
- Automatic messages for registration, payments, holds, refunds, grades, admission and graduation. They say only that there is something new and ask the person to log in, with no grades or amounts. Each can be turned off or reworded, and test mode keeps them away from real people.
- Enrollment verification letters, FERPA annual notices and release authorizations, reports, saved exports for a state agency or accreditor, and a National Student Clearinghouse enrollment file.
- An optional federal aid reporting module with its own Financial Aid role. It is off by default and has no link to federal systems.
- A menu by role, with a home page for students, records staff, finance, financial aid and teachers. Each person sees only the pages their role allows.
- "No LMS access" for staff who never use courses, such as a registrar. They work in Student Records only.

## What it doesn't do

- It stores no Social Security numbers. Export columns for them stay empty.
- It sends nothing to outside agencies or the IRS. Staff download reports.
- It doesn't process federal aid (packaging, COD, NSLDS, ISIRs), and it has no housing, alumni or mobile app features.
- Moodle can't send money back by itself, so staff pay refunds back and record them.
- It isn't tax or legal advice. The school's advisers check the 1098-T, the 90/10 worksheet, the Return of aid helper and state reports. The loan limits and the Return of aid helper are helper math, so an aid compliance expert should check them before a school relies on them. "Federal aid helpers" below says what they cover.

## Requirements

- Moodle 4.5 to 5.3. The CI of the repo runs on Moodle 4.5, 5.1, 5.2 and 5.3.
- PHP 8.1 to 8.4, as your Moodle version requires, with the zip and SimpleXML extensions.
- PostgreSQL, MySQL or MariaDB.
- Optional: [Academic Transcript](../academictranscript/README.md) (`local_academictranscript`) 1.4.3 or later, for Official and Unofficial transcripts and transcript requests.
- Optional: the [Student Records theme](../../theme/studentrecords/README.md) (`theme_studentrecords`) 1.0.3 or later, which puts the Student Records menu in a left drawer and adds cards to the Dashboard and course pages. With another theme, the menu shows at the top of the page.

## Install

1. Back up the database and the Moodle data folder. One without the other can't be restored.
2. Plan a quiet time. The install puts the site in maintenance mode for about a minute.
3. Download `local_studentrecords-<release>.zip` from the [releases page](https://github.com/Clouve/moodle-plugins/releases).
4. Sign in as a site administrator and go to Site administration > Plugins > Install plugins.
5. Drop the ZIP file into "ZIP package" and select "Install plugin from the ZIP file".
6. Select "Continue" and then "Upgrade Moodle database now". Follow the screens.
7. Go to Site administration > Development > Purge caches and select "Purge all caches".

To install by hand, unzip the file into the `public/local/` folder of your Moodle code on Moodle 5.1 and later, or into `local/` on Moodle 5.0 and earlier. Then open Site administration > Notifications. The [README of the repo](../../README.md) has the details.

## Set up

Open the staff area at `/local/studentrecords/staff.php`. Admins also find it at Site administration > Plugins > Local plugins > Student Records staff area. The setup wizard asks for these, in this order:

1. School: name, address, contact, registrar, time zone and logo.
2. Approvals: state agency and license, accreditor, the optional federal aid module (only if the school is approved for Title IV aid), the optional F-1 module (only if the school is approved to issue I-20s), and the FERPA annual notice text.
3. Calendar: calendar type, academic year start, terms, withdraw rule, minimum attendance, the at-risk course grade, attendance and refund options, full-time credit hours, and "Students register themselves". Students at risk lists a Moodle course grade under the at-risk percent. Empty means 70, and 0 leaves grades out.
4. Grading: your grading scale, marks such as W and I, levels and pass rules.
5. Programs.
6. Courses. For programs and courses you can work by hand or import your catalog spreadsheet. Staff review every item first.
7. Admissions: student ID format, application checklist and honors rules.
8. Billing, on the Billing setup page: price list, payment account, course access rule, payment plans, refund rules, statement text and 1098-T settings. Billing stays off until you turn it on. A student has one active payment plan at a time, for any term, so finish or cancel a plan before giving a new one. A returned payment doesn't reopen a completed plan while another plan is active: Finance gets a warning and a note on the plan. A reversal of a line from before a plan doesn't count against that plan.
9. Staff: faculty and staff records, and roles (see below).
10. Import: bring records in from a spreadsheet or CSV file. Staff review every item first.
11. Summary: check that nothing important is missing.

After that:

- Add class days, start and end times and rooms to each course offering. Students see them, and the time clash check uses them.
- Check the messages at Staff area > Messages. All messages are on after install. Use test mode on test sites, and set a test inbox first.
- To make Student Records the start page, go to Site administration > Appearance > Navigation > Start page for users and select Student Records.
- Add the school's own FERPA notice. The plugin has no default text for it.

## Online payments

1. Go to Site administration > Payments > Payment accounts and create an account. Turn on PayPal, which comes with Moodle, or another payment provider such as Stripe, which is an add-on. Use the payment company's test mode first.
2. In Billing setup, pick that account under "Payment account for online payments".
3. Students then see "Pay online" on their account. Card details stay with the payment company.

Each school creates its own payment accounts and keys. Online payments stay off until it does.

A task runs every 5 minutes and records any payment that reached Moodle but not the student's account, with the same checks as a normal delivery and the payment's own date. Until then the student sees the same balance and can still pay online, so Finance pays back a second payment made in that gap. The recovery covers payments made after the plugin was installed or upgraded to this version. Older payments stay as they are, even with no account line.

One course that can't open doesn't keep a student's other courses waiting. It stays on the account page for staff, and the audit log says why. A line that lowers the balance stands even if a course can't open.

Moodle's cron must run. A message written during other work, such as a grade or a registration, waits for the next cron run, and the message log shows Waiting to send until it goes. Test mode and the message's switch are read when it goes. A failed message is logged and not tried again. The daily task "Student Records billing" sends plan reminders and handles late plan payments, and the daily task "Student Records waitlists" ends offers not taken and offers free seats to students waiting. It offers a seat while self-registration is on, the section and term are open, and the add period hasn't ended. A student who couldn't register for any reason other than the full section is passed over and keeps their place.

## Where things are

- Everyone: the "Student Records" tab in the top menu, then the menu. On the Student Records theme the menu is a left drawer. On other themes it is a box at the top of the page, folded on small screens.
- Students: My home, My account, My academic record, Registration and My documents. Registration lets them register themselves when the school turns that on.
- Staff: the Student Records home page and the staff area at `/local/studentrecords/staff.php`, with its own menu.
- Teachers: in a course, More > "Student records" for that course's students, and the "Attendance" tab when the course is linked to a course offering.

## Roles and capabilities

Managers can do everything. Teachers see only the students of their own courses, and take attendance in those courses. Students see their own record and account.

| Capability | Lets the holder | Level | Default roles |
|---|---|---|---|
| `local/studentrecords:viewown` | View own student record | System | Authenticated user |
| `local/studentrecords:viewstudents` | View student records (in a course: its students only) | Course | Teacher, Non-editing teacher, Manager |
| `local/studentrecords:takeattendance` | Take attendance for course offerings | Course | Teacher, Non-editing teacher, Manager |
| `local/studentrecords:managestudents` | Manage student records | System | Manager |
| `local/studentrecords:viewdemographics` | View student demographics (IPEDS) | System | Manager |
| `local/studentrecords:approvegradechange` | Approve or reject grade changes | System | Manager |
| `local/studentrecords:managefaculty` | Manage faculty and staff records | System | Manager |
| `local/studentrecords:viewfaculty` | View faculty and staff records | System | Manager |
| `local/studentrecords:viewbilling` | See student accounts and money reports | System | Manager |
| `local/studentrecords:managebilling` | Manage student accounts, prices, payments, payment plans and refunds | System | Manager |
| `local/studentrecords:approverefund` | Approve or reject refunds | System | Manager |
| `local/studentrecords:viewaid` | See aid awards and aid reports (federal aid module) | System | Manager |
| `local/studentrecords:manageaid` | Manage aid awards, and record and reverse aid disbursements (federal aid module) | System | Manager |
| `local/studentrecords:configure` | Set up the school, programs, courses, terms and grading | System | Manager |
| `local/studentrecords:import` | Import from spreadsheets | System | Manager |
| `local/studentrecords:viewlog` | View the audit log | System | Manager |

Set up these roles for your staff:

- Registrar: make a system role with manage students, view students, view student demographics, view faculty and view log at Site administration > Users > Permissions > Define roles, and assign it at system level. Saved exports that list race, ethnicity or sex need view student demographics, and all three starter exports do. Without it the card of such an export says so and has no Run button. The Counts tab needs it too: without it there are no counts by race and sex and no download of them, and completions by program stay. Add approve grade changes if the Registrar decides them. Someone with approve grade changes who doesn't manage students opens Grade changes and sees only the waiting requests they can decide. Nobody decides their own request or their own grade, enters or posts their own final grade, or reviews or confers their own graduation application. On their own record, staff can't change the status, award or program, add or edit transfer credit, approve a substitution, record an admission decision or create the record. Contact details stay theirs to edit. Staff with a student file request a grade change like anyone else. With Academic Transcript, add its issue, approve and manage capabilities too.
- Finance: Billing setup > "Create the Finance role", then "Give people the Finance role".
- Financial Aid, only with the federal aid module on: Staff area > Federal aid > "Create the Financial Aid role", then "Give people the Financial Aid role". It is separate from Finance. Reversing an aid line needs manage aid, and Financial Aid staff do it under Disbursements on the student's aid page. That includes the "Federal aid returned" charge.
- Staff who never use courses: tick "No LMS access" on their staff record.

## With Academic Transcript

Install [Academic Transcript](../academictranscript/README.md) and set its "Source of the transcript" setting to Student Records. Student Records then feeds the transcript, teachers' "Post final grades" saves grades on the student's registration, and teacher change requests arrive in the grade change queue here. A transcript request adds its fee to the student's account when billing is on, and holds here stop a transcript from going out.

When Academic Transcript reads from Student Records, it counts on the grading scale here, so there is one scale to keep. The record and the transcript show the same GPA and hours earned, and that includes a program's minimum passing grade and transfer credit marked "in GPA". The transcript stops an Official transcript when a grade isn't on the scale.

Student Records follows Academic Transcript's repeat rule, and it asks that plugin's calculator which attempts count, so the record, the degree audit, academic standing and the transcript leave out the same ones. An attempt the rule leaves out stays on the record with the tag "Repeated: not counted". It counts as attempted, but not as earned and not in the GPA, and it meets no degree requirement. A retake in progress leaves the earlier grade counting until the retake has a grade. A standing for an earlier term uses what counted at the end of that term. Without Academic Transcript every attempt counts.

## Federal aid helpers

The loan check and the Return of aid worksheet follow the federal rules as of the 2026-27 award year, and they are helpers. Check them against the official worksheet and the current Federal Student Aid Handbook.

Yearly loan check, for undergraduates:

- The limits are the Direct Loan limits by year in school and dependency. A dependent student whose parents are refused a PLUS Loan has the independent limits, so choose Independent in the aid year details. A parent who has reached the PLUS limit doesn't count.
- From 2026-27 the limits shrink for less than full time (34 CFR 685.203(m)). The share is the student's credits over full-time credits, a whole percent, across the terms of their loans, and the page says so. Clock-hour programs aren't reduced. A loan for only part of the academic year takes the term's share of the limit first, which the page doesn't work out.
- A program at a level marked as graduate in Billing setup isn't checked, and the page says so.

Return of aid worksheet (34 CFR 668.22):

- The withdrawal date comes from the last day of attendance in the term, then the student's file, then the day their last class ended. Staff can give another date. With no date the worksheet shows nothing until staff enter one.
- A credit-hour program counts calendar days to that date. Staff enter scheduled breaks of 5 days or more, and those days come off. A clock-hour program counts scheduled hours, and the worksheet can't be sent until staff enter them.
- The percent is worked out to four decimals and rounded to three. A student past 60% by any amount has earned all aid.
- Earned aid comes from aid paid out by the date the school determined the withdrawal, plus aid that could have been disbursed. Staff enter the second one, and the page shows what was accepted and not paid out.
- A student who earned more than was paid out may be due a post-withdrawal disbursement, and nothing goes back.
- The charges are those on the account before the day the student left, and staff can change them. Later charges and changes don't count, and a refund counts from the day of its event. Leave out application fees and anything else that isn't institutional.
- The school returns the smaller of the unearned aid and the unearned charges, in the federal order. For the student's share, loans stay on their terms, half of the grants are protected, and $50 or less in a grant program isn't paid back.
- The return is due 45 days after the date the school determined the student withdrew.

The worksheet doesn't save what staff enter. The Return of aid item for Finance holds the result, and the audit log keeps the details. If the figures change between opening the worksheet and sending it, nothing is sent. Finance approves the item, which puts the returned aid back on the student's account, and sends the money to the Department of Education. Nothing is paid to the student.

Federal aid needs a term on its award, because every worksheet and loan check works by term. The worksheet shows the total of older awards with no term.

## Upgrade

1. Back up the database and the Moodle data folder.
2. Install the new ZIP file the same way. The upgrade keeps all data.
3. Purge all caches.

## Uninstall

Uninstalling deletes all student records, accounts and history in the plugin's tables. Schools must keep permanent records, so export what you must keep first.

1. Go to Site administration > Plugins > Plugins overview. Find Student Records under Local plugins and select Uninstall.
2. Confirm. Moodle deletes the plugin's data.
3. Delete the plugin folder from your Moodle code folder if Moodle can't do it for you.

The uninstall also puts the start page back to Moodle's default if it was Student Records, and clears that choice from users who picked it. It deletes the Finance and Financial Aid roles, unless a school gave them capabilities of other plugins. A role like that stays.

## Privacy

The plugin supports Moodle's privacy tools (Site administration > Users > Privacy and policies > Data requests). The student is the data subject. An export holds the person's data: the record, documents, account, plans, refunds, attendance, aid, the message log, the change history, audit log entries without their details, and what the person did as staff. For staff, that last part lists record ids and dates, without the student's data.

A deletion request removes what a school doesn't have to keep:

- On the student record: preferred name, email, phone, addresses, home country, visa details, residency, citizenship, first generation status, high school and staff notes. The same values are blanked in the change history.
- Emergency contacts, demographics, and the notes on the application checklist and on prior education.
- Student documents, the self-registration cart, waitlist places and the message log.
- On a faculty record: email, phone, office hours and notes.
- Moodle's record of the student's online payments, and what the payment provider keeps.
- The details of audit log entries about the person. The entries stay.

It keeps the rest, because a school must:

- The permanent academic record: legal name, student ID, date of birth, program, terms, status, award, admission decisions, prior education, registrations, grades, grade changes, transfer credit, academic standing, attendance, holds and graduation applications.
- FERPA records: notice acknowledgments, release authorizations, and letters issued.
- The student account: account lines, online payment requests, refunds and payment plans.
- Aid awards and aid year details.

An export also includes Moodle's record of the student's online payments.

On every supported Moodle version, core's fee enrolment plugin raises an error while Moodle deletes payment data for a user, so the privacy officer gets an "Exception occurred while processing privacy data" notice. The request still ends as deleted, and this plugin removes the payments itself.

A deletion request for an account Moodle has already removed is finished within the hour by the task "Student Records privacy". It acts only on requests a privacy officer approved and Moodle finished, and it erases what any deletion erases, payments included.

The plugin's retention choices are a starting point. Each school should check them against its own legal duties, and add its own FERPA notice.

## Tests

The unit tests are in `tests/`, with a test data generator in `tests/generator/`. The browser tests in `tests/behat/` need a Behat test site, such as the one moodle-plugin-ci sets up. They don't run on a live site.

## Documentation

The [user manual](../../docs/user/student-records/README.md) has a guide for each role and each area, with screenshots. The [developer docs](../../docs/developer/student-records/README.md) explain the code.

## Help

Report bugs and ask questions on the [issue tracker](https://github.com/Clouve/moodle-plugins/issues). To report a security problem in private, follow [SECURITY.md](../../SECURITY.md). The release notes are in [CHANGES.md](CHANGES.md).

## License

Student Records is under the MIT License. Anyone can use, change and share it. The full text is in the `LICENSE` file in the plugin folder. Copyright 2026 Clouve Inc.
