# Troubleshooting

This page lists what can go wrong with Student Records, Academic Transcript and the Student Records theme, as a person meets it: what you see, why, and what to do. It is grouped by what you were doing. Search it for the words on your screen.

Each message is quoted as it appears. Where a message holds a name or a number, the page shows three dots.

## Contents

- [Finding your pages](#finding-your-pages)
- [A page says no](#a-page-says-no)
- [Rules about your own record](#rules-about-your-own-record)
- [The theme](#the-theme)
- [Setup and settings](#setup-and-settings)
- [Terms, grading and the catalog](#terms-grading-and-the-catalog)
- [Sections and offerings](#sections-and-offerings)
- [Faculty and staff records](#faculty-and-staff-records)
- [Import](#import)
- [Student records](#student-records)
- [Admissions](#admissions)
- [Registering students](#registering-students)
- [Registering online](#registering-online)
- [Attendance](#attendance)
- [Final grades in Student Records](#final-grades-in-student-records)
- [Holds and standing](#holds-and-standing)
- [Graduation](#graduation)
- [Transcripts for students](#transcripts-for-students)
- [Transcripts for teachers](#transcripts-for-teachers)
- [Transcripts for staff](#transcripts-for-staff)
- [Setting up transcripts](#setting-up-transcripts)
- [Billing setup](#billing-setup)
- [Student accounts](#student-accounts)
- [Payment plans](#payment-plans)
- [Paying online](#paying-online)
- [Refunds](#refunds)
- [Financial aid](#financial-aid)
- [Messages](#messages)
- [Reports and exports](#reports-and-exports)
- [Privacy and uninstall](#privacy-and-uninstall)
- [Cron](#cron)
- [Caches and upgrades](#caches-and-upgrades)
- [If you are still stuck](#if-you-are-still-stuck)
- [Related pages](#related-pages)

## Finding your pages

| What you see | Why | What to do |
|---|---|---|
| No "Student Records" tab in the top menu | You have no student record linked to your account and no staff capability, so you have no page there | Staff: ask for a role at system level. See [Roles and permissions](roles-and-permissions.md). Students: ask the Registrar's office to link your record |
| "There is nothing here for you yet. If you expected to see your records, please contact the school office." | The same, on the Student Records home page | The same. A role given in a course and not at system level gives this too |
| The home page offers only "Go to my courses" | You have courses and no Student Records pages | Nothing is wrong |
| Student Records is the start page, and you land in a course | You have exactly one course, no student record and no staff pages, so the page sends you there | Nothing is wrong |
| You are staff, and you land on My home | You also have a student record, and that home comes first | Select "Staff home" in the menu |
| The Dashboard tab is gone, and course pages send you back to Student Records | "No LMS access" is ticked on your staff record | That is what it does. Ask whoever keeps [staff records](student-records/faculty-and-staff.md) to untick it. It never applies to a site administrator |
| No "Site administration" tab, so you can't follow a path to Academic Transcript's pages | Your role has no `moodle/site:configview` | [Add it to the role](roles-and-permissions.md#registrar), or open the pages as [Where things are](academic-transcript/README.md#where-things-are) says |
| A page has no menu | It runs inside a course, so the drawer holds the course index, or it is a printable page. On another theme, some pages draw no menu | Use the "Student Records" tab to get back. See [The menu on another theme](theme/README.md#the-menu-on-another-theme) |
| "Course offerings" isn't in the menu of someone with `local/studentrecords:configure` alone | It sits in the records group, which needs a student capability | Open it from "All staff pages" |
| "Students with holds" opens a page with no holds on it | That link and the menu item "Holds and standing" open Academic standing | Find the student, and open the Holds tab of the file |

## A page says no

| What you see | Why | What to do |
|---|---|---|
| "Sorry, but you do not currently have permissions to do that (...)." | Your role lacks the capability named in the brackets, in Moodle's words | Find those words in [Roles and permissions](roles-and-permissions.md), and ask an administrator to add it |
| "Sorry, but you do not currently have permissions to do that (Set up the school, programs, courses, terms and grading)." on Course offerings or Terms | The role has `local/studentrecords:managestudents` and not `local/studentrecords:configure` | [Add it to the Registrar's role](roles-and-permissions.md#registrar) |
| "Sorry, but you do not currently have permissions to do that (View student records (in a course: its students only))." on the staff area | You have no staff page at all | Ask for a role at system level |
| "Sorry, but you do not currently have permissions to do that (See aid awards and aid reports (federal aid module))." as a Manager or site administrator | The federal aid module is off, and then the page opens for nobody | [Turn the module on](student-records/federal-aid.md#turn-the-module-on), if the school is approved for it |
| "Access denied" | Moodle's own words on its admin pages, and on most Academic Transcript pages | Check the capability of that page in [Academic Transcript](academic-transcript/README.md#where-things-are) |
| "Access denied" on Transcript settings, as a Manager | That page needs `moodle/site:config` | Ask a site administrator. See [What a Manager can't do](roles-and-permissions.md#what-a-manager-cant-do) |
| "Access denied" on the Terms link of the transcript wizard | The Terms page needs `local/academictranscript:managerecords`, and the wizard needs `local/academictranscript:configure` | Add the first one |
| A refusal that names "Issue Official transcripts", on a student's transcript | `local/academictranscript:issueofficial` is also the right to see a transcript. People who hold only `local/academictranscript:managerecords` or `local/academictranscript:viewissued` get this. A student who has lost `local/academictranscript:viewown` gets it too | Add the capability to the role |
| "Sorry, but you do not currently have permissions to do that (View user full information)." in the "Moodle user" or "Moodle account" box | The role has no `moodle/user:viewalldetails` | Add it to the role |
| A role was given, and nothing changed | A system capability counts only from a role given at system level | Give the role at Site administration > Users > Permissions > Assign system roles. [Check what the person can do](roles-and-permissions.md#check-what-a-person-can-do) |
| The role isn't offered in Assign system roles | It has no tick at "System" under "Context types where this role may be assigned" | Edit the role |
| "Withheld (FERPA)", or "This student withheld directory information (FERPA). Contact details and addresses are not shown." | The student opted out, and you don't hold `local/studentrecords:managestudents` | Ask the Registrar's office. See [FERPA and letters](student-records/ferpa-and-letters.md) |
| The Documents tab says "No files on your record yet." on a student's file | You read the file with `local/studentrecords:viewstudents`, which shows no document names. The message is written for a student | Only holders of `local/studentrecords:managestudents` see document names |
| No History tab on a file | The same | The same |
| Finance can't open a student's file | The Finance role reaches a student through Student accounts only | That is by design. See [The roles the plugins make](roles-and-permissions.md#the-roles-the-plugins-make) |
| "Unknown action." | The address was changed by hand, or the page is old | Go back to the page and try again |
| "Your session has most likely timed out. Please check if you are logged in or go back to the previous screen and reload the page." | A form stayed open too long | Go back, reload, and enter it again. Some Academic Transcript pages save nothing in this case and show no message, so check that your change is there |

## Rules about your own record

These hold for everyone, site administrators too. Another person must act. [Rules that aren't capabilities](roles-and-permissions.md#rules-that-arent-capabilities) has the full list.

| What you see | Who can do it instead |
|---|---|
| "This is your own record, so another person must make this change." | Another person who can edit the record |
| "This is your own record, so another person must change your program, status and award." | The same. The fields are frozen on the form |
| "This is your own grade, so another person must enter it. To change it, request a grade change." | Another records staff member |
| "You can't post your own grade. Another teacher or an admin must post it." | Another teacher, or the Registrar |
| "You asked for this grade change; another person must approve or reject it.", "Your request: another person must decide" | A second approver. Give `local/studentrecords:approvegradechange` to at least two people |
| "This is your own grade; another person must approve or reject the change.", "Your own grade: another person must decide" | The same |
| "You asked for this change. Another person must approve or reject it.", "This is your own grade. Another person must approve or reject the change." | Another holder of `local/academictranscript:approvechanges`, on own data |
| "This is your own graduation application, so another person must review and confer it.", "Your own application: another person must decide" | Another records staff member |
| "You cannot decide a refund on your own account. Ask someone else who approves refunds." | Another person who holds `local/studentrecords:approverefund` and `local/studentrecords:viewbilling` |
| "This is your own transcript, so another person must issue it." | Another person who can issue |

## The theme

| What you see | Why | What to do |
|---|---|---|
| The old colour or logo after a save | Moodle rebuilds the styles on the first page after a save, and the browser may keep the old ones | Reload the page. If that doesn't help, select "Clear theme caches" at Site administration > Appearance > Themes |
| "This colour is too light: its contrast with white is ... to 1, and at least 4.5 to 1 is needed. Pick a darker colour." | The colour fails the [contrast rule](theme/settings.md#accent-colour) | Pick a darker colour |
| "Enter the colour as # and six letters or numbers, for example #416180." | It isn't a colour the setting reads, such as a name | Type it as `#` and six digits, 0 to 9 and a to f |
| "This value is not valid" under a text setting | The text has HTML tags | Take the tags out |
| "Some settings were not changed due to an error." | One field was refused. The others were saved | Fix the field in red and save again |
| The login card says "Log in to" and the school's name | "Login page welcome text" is empty | [Fill it in](theme/settings.md#login-page-welcome-text) |
| Line breaks in the welcome or help text are gone | The two texts show as one paragraph | Keep them short |
| The welcome and help lines aren't on "Forgot password?" | They show only on the main login page | Nothing is wrong |
| The school's name isn't in the phone menu's head, the browser tab, the footer or emails | Those come from Moodle, from the site's own names | Change the site's full and short names at Site administration > General > Site home > Site home settings |
| A setting of Boost does nothing, such as "Brand colour" | The theme uses few of Boost's settings | See [Settings of Boost](theme/settings.md#settings-of-boost) |
| The strip or card is missing | The person isn't one the card is for. See [The Dashboard](theme/README.md#the-dashboard) and [Course and activity pages](theme/README.md#course-and-activity-pages). A card also goes silently if the plugin hits an error | Check the person's student record, and that the course is linked to a section of an active term. A site administrator can turn on debugging to see an error |
| No colour mode icon (a sun, a moon or a half circle) in the top bar | Colour modes are off, or the site runs Moodle 5.2 or earlier, or you aren't signed in | [Turn dark mode on](theme/on-phones-and-dark-mode.md#turn-dark-mode-on-for-the-site) |
| The login page is dark for someone who chose light | The browser remembers the last mode chosen on it | Nothing is wrong. It follows their choice once they sign in |

> Known problem in 1.0.3: below 576 pixels wide, "More" in a tab row opens nothing you can use. Turn the phone sideways. See [A course on a phone](theme/on-phones-and-dark-mode.md#a-course-on-a-phone).

## Setup and settings

| What you see | Why | What to do |
|---|---|---|
| "Enter a whole number from 1 to 10." | "Digits in the number" is out of range | Enter a number in the range |
| "Enter a number from 1 to 100.", "Enter a number from 0 to 100." | A percent is out of range. The first message gives 1 as the lowest value, though its fields take any number above 0, such as 0.5. A known problem in 1.4.6 | Enter a number in the range |
| "Enter a number of hours, for example 12." | "Full-time credit hours per term (Clearinghouse)" isn't a number above 0, up to 100 | Enter the hours |
| "OPEID" isn't on the School step | It shows only once federal aid is saved as on | Save the Approvals step, and go back to the School step |
| A term shows a day earlier than you set | The school's time zone changed after the term was saved. A known problem in 1.4.6 | Set the time zone first. To repair a term, open it, set each date again and save. See [Change a term](student-records/terms-and-calendar.md#change-a-term) |

## Terms, grading and the catalog

| What you see | Why | What to do |
|---|---|---|
| "The end date is before the start date." | The term's dates are the wrong way round | Correct them |
| "This date is outside the term." | A date on the term falls outside its start and end | Correct the date |
| "The grade freeze cannot be before the end of the term." | The freeze date is too early | Set it after the term ends |
| "This is already used by another record." | Another record has this code, letter, name or level, even if it's inactive. `mkt 310` and `MKT310` are the same code | Use another, or edit the one that's there |
| "Grade points" shows "Required" on a mark | "Counts in GPA" is ticked with no points | For a mark, untick "Counts in GPA" |
| "Enter a number." | A number field, such as hours, GPA, a percent or grade points, holds something else | Enter a number |
| "Give either a lowest passing letter or a lowest passing percentage." | A pass rule needs one of the two | Fill in one, and leave the other empty |
| "Choose a level, or a program (optionally with a group or a course), not both." | A pass rule applies to one scope | Choose one |
| "No course with this code in the catalog." | The course code on the pass rule doesn't match | Check the code |
| You add a course to a program's list, and nothing changes | A row with the same course in the same group is skipped, with no message | See [Fill in a program's course list](student-records/programs-and-courses.md#fill-in-a-programs-course-list) |
| A program's course list has a course you didn't pick | "Course" on the form starts with the first course of the catalog in it. A known problem in 1.4.6 | No page removes a row, so check the box before you add a course. See [Fill in a program's course list](student-records/programs-and-courses.md#fill-in-a-programs-course-list) |
| A course is in the wrong group of a program | No page can remove it | An import can change a row's requirement, and the row stays in its group |
| A course's prerequisite isn't checked | Text typed in "Prerequisites" makes no link. Only the import makes them | See [Programs and courses](student-records/programs-and-courses.md) |
| You can't delete a term, program, course or grade | No page deletes them | Turn off "Active" on a term, program or course. A grade on the scale can't be switched off |

> Known problem in 1.4.6: once a program is inactive, saving a student who is in it clears the student's program, with no warning. See [Programs and courses](student-records/programs-and-courses.md).

> Known problem in 1.4.6: when new term dates leave the old worked-out last day to withdraw outside the term, saving stops with "This date is outside the term." under "Last day to withdraw". Untick "Enable" next to that date and save again. See [How the last day to withdraw is worked out](student-records/terms-and-calendar.md#how-the-last-day-to-withdraw-is-worked-out).

## Sections and offerings

| What you see | Why | What to do |
|---|---|---|
| "Enter a whole number of seats, or leave it empty." | "Capacity" isn't a whole number of 1 or more | Enter a number |
| "Enter the time as HH:MM in 24 hours, for example 09:00 or 14:30." | The time has another form | Type it that way |
| "The end time must be after the start time." | The times are the wrong way round | Correct them |
| "This instructor is not marked qualified for the course (faculty record > qualified courses). Tick the box below to save anyway." | The course isn't on the teacher's qualified courses | Add it on the [faculty record](student-records/faculty-and-staff.md), or tick the box |
| The "Copy from last term" button isn't there | The list is on "All" terms | Choose a term |
| "There is no earlier term to copy from." | The chosen term is the earliest | Nothing to copy |
| The copy says 0 sections | The term before it, by start date, has no section that this term lacks. Cancelled sections aren't copied | Nothing to do |
| The "Get final grades from Moodle" button isn't there | The section has no linked Moodle course, or you don't hold `local/studentrecords:managestudents` | [Link the section](student-records/course-offerings.md#link-a-section-to-a-moodle-course), or ask for the capability |
| A new section is for a course you didn't pick | "Course" on the form opens with the first course of the catalog in it. A known problem in 1.4.6 | Edit the section and pick the course. See [Add a section](student-records/course-offerings.md#add-a-section) |

> Known problem in 1.4.6: the "Instructor" list holds only active faculty, so a section whose teacher was turned off shows None. Saving the form then takes the teacher off the section. Pick a teacher each time you edit such a section.

> Known problem in 1.4.6: "Get final grades from Moodle" can record a far lower percent than the gradebook, when a grade item has no grade for a student. See [Get final grades from Moodle](student-records/course-offerings.md#get-final-grades-from-moodle).

## Faculty and staff records

| What you see | Why | What to do |
|---|---|---|
| "This user already has a record." | A Moodle account can have one record | Open that record |
| "Sorry, but you do not currently have permissions to do that (View user full information)." when you type in the "Moodle account" box | The role has no `moodle/user:viewalldetails`, so the box can't search | Add it to the role |

> Known problem in 1.4.6: the form for a credential can't be used. "Save changes" and "Cancel" stop with "There is no record." or "Can't find data record in database.", and nothing is saved. Leave the form with the browser's back button. See [Faculty and staff](student-records/faculty-and-staff.md).

## Import

| What you see | Why | What to do |
|---|---|---|
| "The file could not be read as a spreadsheet (.xlsx)." | The file has the wrong format, is damaged, or has a password | Open it in your spreadsheet program and save it as .xlsx again |
| "This file is too large to read. Split it into smaller files, or remove the rows and columns that are not used." | It is over a limit | Split it |
| "...: not saved, because it has an error to fix first." | You ticked an item with an error. It stays waiting | [Fix it](student-records/import.md#fix-an-item) or reject it |
| "A program, course, level or faculty record this item needs is not saved yet. Approve it first." | Items depend on each other | See [The order of parents](student-records/import.md#the-order-of-parents) |
| A term has no last day to withdraw after you fix its date | The import doesn't work one out for a term that had a date error | Type `1` in `withdrawcalc` in the same fix, or open the term after you approve it and save |
| A date you typed in "Fix" comes out empty | Fix doesn't check what you type, and a date in another form is saved as empty, even over a saved date. A known problem in 1.4.6 | Type dates as `2028-06-05`. See [Fix an item](student-records/import.md#fix-an-item) |
| A course, program or rule has no level, or a course has no prerequisite link, after you approved it | It was approved before what it points at. A known problem in 1.4.6 | Approve items in order. See [The order of parents](student-records/import.md#the-order-of-parents) |
| A check on an item you don't understand | The import explains what it read | See [What each check means](student-records/import.md#what-each-check-means) |
| Students or grades aren't imported | The import reads the school's setup only | See [What the import can't read](student-records/import.md#what-the-import-cant-read) |
| "Sheets in the file" says "not used" for a CSV file whose headers are right | A line with only commas, semicolons, tabs or spaces above the headers is read as the header row. A known problem in 1.4.6 | Delete that line and read the file again. See [Make your file](student-records/import.md#make-your-file) |
| A term's last day to withdraw differs from the date its item showed | The item works the date out from the rule in the file, or at 60%, and approving it uses the school's saved rule. A known problem in 1.4.6 | Go by the date on the term. See [Terms](student-records/import.md#terms) |
| A course needs every course its prerequisite text names, as in `ENG 101 or ENG 100`, or a code such as `CS-101` made no link | The import links each code it reads, even codes joined by "or", and it doesn't read a code with a hyphen. A known problem in 1.4.6 | For a choice of courses, put only the code that registration should check. See [Courses](student-records/import.md#courses) |
| A program's item has the level Graduate, or no level, though the file says Undergraduate | The import finds the word graduate inside Undergraduate. A known problem in 1.4.6 | Write undergrad, associate or bachelor in the file, and read it again. See [Programs](student-records/import.md#programs) |
| A program's grade rules made no pass rules, and no note says why | The file has no grading scale, and the import reads grade rules only with one. A known problem in 1.4.6 | Put the grading scale in the same file, or add the pass rules by hand. See [Programs](student-records/import.md#programs) |
| A level's description is gone after you approved a course file | The Level column of a course sheet makes a Levels item with no description. A known problem in 1.4.6 | Edit the level on the Grading page and type the description again. Don't approve such a Levels item. See [Add a prerequisite link](student-records/programs-and-courses.md#add-a-prerequisite-link) |

> Known problem in 1.4.6: an academic rule approved before its level is saved without it, and it also replaces the rule with the same title that is saved for all levels. See [The order of parents](student-records/import.md#the-order-of-parents).

> Known problem in 1.4.6: an update saves each value that the file leaves out as empty. Fill in every column for that kind, or reject the update. See [Import a file again](student-records/import.md#import-a-file-again).

## Student records

| What you see | Why | What to do |
|---|---|---|
| A search finds nobody by a whole name, an email or a middle name | The list searches each name and the student ID on its own, though the box on the Staff home says "Find a student by name, student ID, or email". A known problem in 1.4.6 | Search by last name or student ID |
| "Error writing to database" when you add a record | The person you picked already has a file | Open that file |
| "Enter a year such as 2021." | "Year completed" isn't a year from 1900 to 2100 | Type four digits |
| "Pick day, month and year, or leave all three blank. The date must exist." | The date of birth is only partly filled in, or isn't a real date | Correct it |
| "The date of birth cannot be in the future." | The date is ahead of today | Correct it |
| "Enter a phone number or an email." | An emergency contact needs one of the two | Fill in one |
| ""Not reported" cannot be chosen together with a race." | The two exclude each other | Choose one |
| An edit clears the student's program or advisor | The lists hold active ones only | See the known problem below |
| The status didn't change after a registration, a withdrawal from every course, or a term | Nothing sets Enrolled, Active, Withdrawn or On leave by itself | [Change the status](student-records/student-file.md#student-status) on the file |
| The History tab doesn't show a change to a hold, grade or checklist | It covers the main record only | Nothing to do. The rest is stored and has no screen |
| A student's notes aren't on the file | Staff notes show only on the edit form and in History | Open "Edit profile" |
| The release check stops listing an authorization on its Until day | An authorization stops being in force when its Until day starts. A known problem in 1.4.6 | Set Until to the day after the last day it should cover. See [Add a release authorization](student-records/ferpa-and-letters.md#add-a-release-authorization) |

> Known problem in 1.4.6: saving "Add student record" or "Add applicant" stops with "There is no record.", and no record is made, for every role. "Cancel" stops the same way once a person with no file is picked. A site administrator has to make new records with the script in [Add a student record](student-records/student-file.md#add-a-student-record).

> Known problem in 1.4.6: the PDF of an enrollment verification letter has three rows that "What the letter will show" leaves out: Date of birth, Credential and Admit term. It prints the date of birth even when the student withheld directory information. See [Issue an enrollment verification letter](student-records/ferpa-and-letters.md#issue-an-enrollment-verification-letter).

> Known problem in 1.4.6: the Program list holds active programs only, and the Advisor list active faculty only. If a student's program or advisor has been switched off, saving the form clears that field with no warning. Switch it back on before you save. See [Edit a student record](student-records/student-file.md#edit-a-student-record).

## Admissions

| What you see | Why | What to do |
|---|---|---|
| "No applicants here." at first | The list opens on "Ready for decision", and nobody is ready | Choose another filter |
| Everyone counts as ready | The checklist has no documents that apply | [Set up the checklist](student-records/admissions-process.md#set-up-the-application-checklist) |
| "Please write a note for the file to deny an application." | You selected "Deny" with no note | Add the note |
| "A denial needs a reason." | The decision form was saved with no reason | Add it |
| Two decision rows, and two sets of messages | The form saves a new decision each time. It was submitted twice. A known problem in 1.4.6 | Nothing undoes it. Tell the student |
| A decision doesn't change the status | The student is past admissions, such as Active | Change the status on the file |
| Deferring isn't on the Admissions page | Its buttons can't defer | Use "Record a decision" |
| "A withdrawal needs the withdrawal date.", "A withdrawal needs the last date of attendance." | Both are required for the status Withdrawn | Fill them in |
| "The last date of attendance cannot be after the withdrawal date." | The dates are the wrong way round | Correct them |
| "Nothing to report." | No student matches the filters | Change the filters |
| A count on Pipeline looks low | It leaves out Graduated, Withdrawn and On leave | Nothing is wrong |

## Registering students

| What you see | Why | What to do |
|---|---|---|
| "A hold blocks registration: .... Release the hold first (it cannot be overridden)." | A hold marked "Blocks registration" is in force. "Override the rules" doesn't help | [Release the hold](student-records/holds-and-standing.md#release-a-hold) |
| "Already registered in this offering." | The student has this section | Nothing to do |
| "This offering is full (... seats)." | No seat is free | Tick [Override the rules](student-records/registration.md#override-the-rules). Staff can't put a student on a waitlist: the student joins it online, when self-registration is on |
| "This offering is full (... seats).", while Course offerings shows the section "Open" with a free seat | That seat is held for 2 days for a student from the waitlist, and the list doesn't count it. A known problem in 1.4.6 | Open the section, and look for "Seat offered" in its "Waitlist" table. See [Seats and the waitlist](student-records/course-offerings.md#seats-and-the-waitlist) |
| "This offering is ...." | The section is closed or cancelled | Choose another, or reopen it |
| "Prerequisites not passed: ...." | The student hasn't passed a prerequisite course | Override it if the school agrees |
| "The add period of this term is over." | The add/drop end date has passed, or with none the term's end | Override it if the school agrees |
| "An override needs a reason." | "Override the rules" is ticked and the note is empty | Add a reason |
| "The linked Moodle course has no manual enrolment method; add one in the course first." | The plugin enrols through Moodle's manual enrolment | Add or enable the method in the course and try again |
| "The drop period (add/drop) is over; use Withdraw." | Add/drop has ended | Use "Withdraw" |
| "Add/drop is still open; use Drop." | Add/drop hasn't ended | Use "Drop" |
| "The last day to withdraw has passed." | The term's date has passed | Tick "Override the rules" if the school allows it |
| "Only a registered course can be dropped or withdrawn." | The row is completed, dropped or withdrawn | Nothing to do |
| "Someone else is working on this record. Try again in a moment." | Two people worked on the same seats or account | Try again |
| A student ended up in the wrong section | The "Course offering" box starts with the first section on the list, of any term. A known problem in 1.4.6 | Check the box before you select "Register". See [Register a student for a course](student-records/registration.md#register-a-student-for-a-course) |
| A registered student isn't in the Moodle course | The course access rule says to wait for payment | See [Course access](student-records/registration.md#course-access) |
| A drop didn't suspend the enrolment | Another active registration of the student uses the same Moodle course, or the student was enrolled in it another way | Nothing is wrong |

## Registering online

| What you see | Why | What to do |
|---|---|---|
| "The registrar's office registers students at this school. Your classes are below." | Self-registration is off | Go through the Registrar's office |
| "Students cannot register themselves at this school. Please contact the registrar's office." | The same, at the moment of adding a class | The same |
| "Your status is ..., so you cannot register online. Please contact the registrar's office." | Only admitted, enrolled and active students can | Go through the Registrar's office |
| "Registration is not open for any term right now. Check back when the add period starts." | No active term has its add period open | Look at the term's dates. The add period has no start date: an active term is open until the add/drop end date |
| "Registration for this term is not open." | The term's add period is over, or the term is inactive | Choose another term |
| "Two of your classes meet at the same time. Remove one before you confirm." | The time clash check found two classes | Remove one |
| "This section still has seats, so it has no waitlist. Add it to your classes." | You asked to wait for a section with seats | Add it |
| A class in the cart fails at Confirm | The cart holds no seat, so a class can fill up meanwhile | Choose another, or join its waitlist |
| A student can't drop a class online | Only staff drop and withdraw | Ask the Registrar's office |
| Students register for a term before its "Add/drop starts" | No rule reads that date, so an active term takes registrations at once. A known problem in 1.4.6 | Leave "Active" off until registration opens. See [What each date does](student-records/terms-and-calendar.md#what-each-date-does) |
| Two picks in the panel look the same, and so do their "Remove" buttons | The panel lists a pick by its course code alone, so two sections of one course can't be told apart. A known problem in 1.4.6 | Remove both, then add the section you want again. See [Register for classes online](student-records/registration.md#register-for-classes-online) |
| A seat offer has gone | It lasts 2 days. A lapsed offer takes the student off the list | Join the waitlist again. See [Waitlists and seat offers](student-records/registration.md#waitlists-and-seat-offers) |
| No seat is offered, though one is free | Seats are offered only while self-registration is on and the add period runs, and only to a student who could take the seat. After an offer lapses, the next one goes out when the daily task runs | Check the term's dates. See [Cron](#cron) |

## Attendance

| What you see | Why | What to do |
|---|---|---|
| "There is already a meeting that day." | A section has at most one meeting a day | Use that meeting |
| "Attendance can be taken for meetings up to today only." | The meeting is in the future | Wait for the day |
| "Pick at least one day of the week." | "Add a weekly schedule" needs a day | Pick one |
| "Enter the length in hours, for example 1.5 or 5." | The length isn't a number above 0, up to 24 | Enter it |
| No "Attendance" tab in a course | The course isn't linked to a section, or you can't take attendance there | [Link the section](student-records/course-offerings.md#link-a-section-to-a-moodle-course) |
| A teacher can't mark a section | The section has no linked Moodle course. Only records staff can mark those | Link it, or ask records staff |
| The weekly schedule filled a holiday | It fills every chosen weekday of the term | Leave that day unmarked, or mark it excused |
| A student's rate looks too high | Unmarked students and excused meetings don't count | Mark every student |
| A student got "Low attendance warning" after one absence | The first mark set a rate under the minimum | Nothing is wrong. See [The low attendance warning](student-records/attendance.md#the-low-attendance-warning) |
| A student with a good gradebook total is on Students at risk | The plugin works the percent out from the course's whole maximum, so an activity not graded yet lowers it. A known problem in 1.4.6 | See [Students at risk](student-records/attendance.md#students-at-risk) |
| A mark can't be cleared, or a meeting deleted | No page does that | Correct the mark instead |

## Final grades in Student Records

| What you see | Why | What to do |
|---|---|---|
| "The grades of this term are frozen; request a grade change instead." | The grade freeze has passed | [Request a grade change](student-records/final-grades.md#request-a-grade-change) |
| "A final grade set by staff or by an approved grade change is kept. Request a grade change instead." | The gradebook never replaces those | Request a grade change, or enter it by hand before the freeze |
| "Only a registered or completed course can have a final grade." | The student dropped or withdrew | Nothing to do |
| "Enter a percentage from 0 to 100." | The percent is out of range | Correct it |
| "Choose a different grade." | The new grade equals the old one | Choose another |
| "There is already a grade change waiting for this course." | One can wait at a time | Wait for the decision |
| "A grade change needs a reason." | The reason is empty | Add it |
| "Only a completed course with a final grade can have a grade change." | The course has no final grade yet | Enter the grade |
| "This grade change was already decided." | Someone else got there first | Reload the page |
| "The grade changed after this request was made, so it can't be approved. Reject it and ask again." | Someone corrected the grade while the request waited | Reject it, and ask again if a change is still needed |
| "Final grades from Moodle: ... set, ... entered by staff kept, ... without a course total yet." shows kept grades you didn't enter | The kept count holds grades from teachers and from approved changes too | Nothing is wrong |
| Nobody was told about a grade change | Requests and rejections send no message, and staff get none. An approval sends the student only "Final grade posted" | Approvers look at Grade changes, and on the Staff home under "Needs your attention" |
| The course's percentage is gone after a grade change was approved | The request had no percentage, and an approval saves the request's percentage, even an empty one. A known problem in 1.4.6 | Before the grade freeze, [enter the final grade](student-records/final-grades.md#enter-a-final-grade-by-hand) again with its percentage. On a request, fill in "Percentage (optional)" |
| A W is gone from the record after the student registered again in the same section | The new registration reuses the row | It stays in the stored history, which no page shows, and as a line in the [Audit log](student-records/audit-log.md). See [Drop or withdraw a student from a course](student-records/registration.md#drop-or-withdraw-a-student-from-a-course) |

## Holds and standing

| What you see | Why | What to do |
|---|---|---|
| A hold didn't lift after the student paid | A payment doesn't release a hold, and the Finance role can't release one | Records staff [release it](student-records/holds-and-standing.md#release-a-hold) |
| A student on a hold can still register | Only a hold with "Blocks registration" set to Yes stops registration | Place the hold with that setting |
| "This is already used by another record." on Standing rules | That level has a rule, active or not | Edit it |
| A standing of Dismissal changed nothing | A standing has no side effects: no message, no hold and no change of status | Staff act on it |

## Graduation

| What you see | Why | What to do |
|---|---|---|
| "This student already has an open graduation application." | One can be open at a time | Use that one |
| "The student has no program." | The file has no program | Set it on the file |
| "The degree audit is not complete; approving needs a note." | The audit has open items | Add a note, or finish the audit |
| "This graduation was approved as conditional: it can be conferred when the degree audit is complete (all grades posted and passed)." | The approval was conditional | Post the last grades, then confer |
| "This application cannot be changed in its present state." | It was decided or conferred, or the page is old | Reload the page |
| "Pick two different courses and write the reason." | A substitution needs both | Fill them in |
| "There is no open graduation application. The student (or staff) applies on the Graduation page." | Nobody has applied, or the application is already approved. Only staff can apply, on the Graduation tab of the file. A known problem in 1.4.6 | If it's approved, confer it on the Graduation page. Otherwise [apply for the student](student-records/degree-audit-and-graduation.md#apply-for-graduation) |
| "This program has no course list, total hours or minimum GPA, so the audit has nothing to check. The Registrar sets these on the program." | The program holds nothing to check | Fill in the program |
| Conferring went through with a hold or a balance | Neither blocks conferral. The row "No holds, balance paid" is information only | Check them yourself first |

## Transcripts for students

| What you see | Why | What to do |
|---|---|---|
| "Enter who it goes to (a person or office)." | "Send to (person or office)" is empty | Fill it in |
| "Choose how to send it.", "Choose when to send it." | A choice is missing | Choose one |
| "Enter a valid email address." | "Their email" is empty or wrong | Check it |
| "Enter the mailing address." | "Mailing address" is empty | Fill it in |
| "You sent this same request a moment ago, so it was not sent again and there is no second fee. To order another copy, send it again in a minute." | Same request within 60 seconds | Nothing was charged twice. Wait a minute to order another copy |
| A course still shows IP after the term | Its grade isn't posted | Ask your teacher |
| You can't download your Official transcript | Students get an Unofficial copy. The Official one goes to the recipient | Ask the Registrar's office |

## Transcripts for teachers

| What you see | Why | What to do |
|---|---|---|
| "Not saved for ...: a reason is required when a code is set or changed." | That row wasn't saved, and it shows its saved code again. The others were. A known problem in 1.4.3 | Choose the code again, enter the reason and save again. See [Enter a transcript code](academic-transcript/teacher-guide.md#enter-a-transcript-code) |
| "No course total or code to post yet" | The gradebook has no total and no code is set | Grade the student, or enter a code |
| "The grading scale has no grade for this percentage" | The scale has a gap at this percent | Ask whoever keeps the [grading scale](academic-transcript/grading-and-marks.md) |
| "Cannot post: ..." | Something changed after the page opened. The reason is one of the row messages above and below | Read the reason |
| "The final grade for this term is already posted. Use "Request change" instead." | One posted grade per student, course and term | [Request a change](academic-transcript/teacher-guide.md#request-a-change-to-a-posted-grade) |
| "A change request for this grade is already waiting for approval." | One can wait at a time | Wait for the decision |
| "You must supply a value here." or "Required" under "Reason" | The reason is empty | Add it |
| "This grade is not allowed." | The new grade isn't on the menu | Choose one from the menu |
| "The Registrar already set this final grade in Student Records (...); it is not changed. Ask the Registrar for a grade change." | A grade from staff or an approved change is kept | Ask the Registrar |
| "A teacher already posted a final grade (...) on this registration in Student Records. For a new attempt, ask the Registrar to register the student for this term." | The row has a posted grade | Ask the Registrar |
| "The grades of this term are frozen in Student Records (after ...); ask the Registrar for a grade change." | The freeze has passed | Ask the Registrar |
| "The student is not registered for this course in Student Records; ask the Registrar." | There's no registration to post on | Ask the Registrar |
| "The student has more than one open registration for this course in Student Records; ask the Registrar." | The plugin can't tell which | Ask the Registrar |
| "This grade is not on the school's grading scale in Student Records; ask the Registrar." | The two scales differ | Ask the Registrar |
| "Post final grades" and the other course pages can't be reached on a phone | See [The theme](#the-theme) | Turn the phone sideways |
| "Grade to post" offers a letter for a student who has a code on Transcript codes | The code's mark is hidden, and Post final grades ignores the code with no warning. A known problem in 1.4.3 | Ask whoever keeps the marks to show it again. See [Add, change or hide a mark](academic-transcript/grading-and-marks.md#add-change-or-hide-a-mark) |

> Known problem in 1.4.3: "Course total" on Transcript codes and "Grade to post" on Post final grades can be lower than the gradebook, when an item has no grade for a student. See [Post final grades](academic-transcript/teacher-guide.md#post-final-grades).

## Transcripts for staff

| What you see | Why | What to do |
|---|---|---|
| "An Official transcript cannot be issued while the student has a hold: ...." | A hold stops every way of issuing | Release the hold |
| "An Official transcript cannot be issued until these are fixed: ..." | The student's page lists the problems | See [What stops an Official transcript](academic-transcript/admin-guide.md#what-stops-an-official-transcript) |
| "Fix the items listed above first; then an Official transcript can be issued here." | The same | The same |
| "Course "..." has no course code.", "Course "..." has no hours.", "Course "..." has no term." | A course field is empty, on own data | Fill in the [course fields](academic-transcript/terms-and-course-fields.md#fill-in-the-course-fields) |
| "Course "..." uses ..., which does not match the student's program." | The course's Hour type and the program differ, on own data | Change the course's Hour type, or the student's program |
| "The grade ... posted for ... is not in Student Records, so the transcript leaves it out. Ask the Registrar to enter it there." | It was posted while the source was the plugin's own data, or before the student had a file | Enter it in Student Records. See [With Student Records](academic-transcript/with-student-records.md#what-stops-an-official-transcript) |
| "Grade ... in ... is not on the grading scale in Student Records, so the transcript cannot count it. Add it to the scale there, or correct the grade." | The grade is off the scale | Add it, or correct it |
| "Enter who the Official transcript is issued to." | The recipient is empty | Fill it in |
| "This Official transcript was issued a moment ago, so no new copy was made: ..., verification code .... To make another copy, issue it again in a minute." | Same issue within 60 seconds | See [Double clicks and two people at once](academic-transcript/registrar-guide.md#double-clicks-and-two-people-at-once) |
| "This request can't be issued yet. There is a hold, or it waits for grades or the degree." | The request isn't ready | Read its row in the [queue](academic-transcript/registrar-guide.md#read-the-queue) |
| "This request is already issued, so no new copy was made. Use Reissue to make a new copy." | Someone issued it first | Reload the page |
| "This request was issued a moment ago, so no new copy was made. To make another copy, use Reissue again in a minute." | The same, within 60 seconds | Wait a minute |
| "This request was cancelled, so no transcript was made." | Someone cancelled it first | Reload the page |
| "This request is already issued, so it was not cancelled." | Someone issued it first | Reload the page |
| "This request is not pending any more." | Its state changed | Reload the page |
| "The last action on this transcript is still being saved. Wait a moment and reload the page before you try again." | Two people, or a double click | Wait, and reload |
| "This transcript was issued in an older layout, so it cannot be downloaded again. Its verification code still works. To give someone a new copy, issue a new Official transcript." | It comes from a build before the first public release | Issue a new one |
| The PDF's signature is by a self-signed certificate, after you uploaded your own | The password was wrong or the file couldn't be read, and no message says so | [Upload it again](academic-transcript/settings.md#upload-a-signing-certificate) |
| "Digital signature not available on this server." on an Official transcript | PHP's OpenSSL extension is missing, or the server can't make its certificate | See [Requirements](academic-transcript/install-and-setup.md#requirements) |
| A graduate isn't on Students' transcripts | The list holds people with a student role in a course | Open their transcript from their profile, or from Issued Official transcripts |
| Post final grades, Transcript codes, "Request change" or a course's Students' transcripts opens the course's "Enrolment options" page for a Registrar | A system role with no `moodle/course:view` can't enter a course it isn't enrolled in | A teacher of the course does this, or enrol the person in the course, or add `moodle/course:view` to the role |
| No "Reject" on a row of Grade change approvals | The table is wider than the page, and the button sits past its right edge. A known problem in 1.4.3 | Scroll the table sideways, or close the drawer. See [Grade change approvals](academic-transcript/admin-guide.md#grade-change-approvals) |
| "(Footnote: )" in the Change column of Grade change approvals | The request clears the footnote. A known problem in 1.4.3 | Nothing is wrong with the request: approving it removes the footnote. See [Grade change approvals](academic-transcript/admin-guide.md#grade-change-approvals) |
| A term's name says "(continued) (continued)" on a transcript | The term is so long that it was cut more than once. A known problem in 1.4.3 | Nothing is left out. See [The pages](academic-transcript/reading-a-transcript.md#the-pages) |

> Known problem in 1.4.3: once one request has been issued, Transcript requests stops at the heading "Recently issued" with the error "Exception - Object of type stdClass is not callable". Issue and Cancel above it still work. See [Read the queue](academic-transcript/registrar-guide.md#read-the-queue).

> Known problem in 1.4.3: a grade that isn't on the grading scale of Student Records still counts its hours as earned, on the student's page and on an Unofficial copy. Add the grade to the scale, or correct it. See [What stops an Official transcript](academic-transcript/with-student-records.md#what-stops-an-official-transcript).

## Setting up transcripts

| What you see | Why | What to do |
|---|---|---|
| "Enter a colour as # and six hex digits, e.g. #1F3A5F." | "Accent colour" isn't six hex digits | Correct it. The `#` is optional |
| "This value is not valid" under a fee on Transcript settings | The box is empty, or the fee has a trailing zero or a money sign, such as 12.50 or $5. The old fee stays | Type a plain number, such as 12.5 or 5. 0 means free. See [Official transcript fees](academic-transcript/settings.md#official-transcript-fees) |
| "You must supply a value here." under School name in the wizard | The box is empty | Fill it in |
| "Grade ... appears twice.", "Grade ...: the minimum must not be above the maximum (0 to 1000).", "Grade ...: grade points must be between 0 and 100.", "The percentage ranges of ... overlap.", "The grading scale needs at least one row." | The row fails a check, and nothing was saved. The page shows the saved rows again, a known problem in 1.4.3 | Type your changes once more. See [Edit the grading scale](academic-transcript/grading-and-marks.md#edit-the-grading-scale) |
| "A grade row has no letter." | On Drafts to review, a ticked row of the grading scale has an empty "Grade". Nothing was saved | Fill in the letter, or untick the row. See [Drafts to review](academic-transcript/programs-and-catalog.md#drafts-to-review) |
| "This already exists." | Another program has that name, or another catalog entry that code | Use another |
| "Data submitted is invalid" on a catalog course | The hours are below 0 or above 5000 | Correct them |
| "The CSV file is missing these columns: .... Use the template." | A column name is missing or misspelled. Nothing was read | Correct the file |
| "Line ... was skipped: required values are missing." | A required cell of that row is empty, or a required number cell holds something else | Correct the row |
| "The AI provider could not read the document: ..." | The provider failed. Nothing from that file is kept | Try again, or enter the data by hand |
| "No text could be read from ...." | The file holds no text the plugin can read, such as a scan | Enter the data by hand |
| "No text could be read from .... Save it as PDF or Word (.docx) and upload it again." | A .doc, .odt or .rtf file, and no document converter on the site could read it | Save it as PDF or Word (.docx) and upload it again |
| "No AI provider is set up on this site, so documents cannot be read automatically." | The site has no provider | Enter the data by hand, or set one up |
| "A term with this name already exists.", "The end date must be after the start date." | The term form refused it | Correct it |
| "You must supply a value here." under Course code | A course can't be saved without one | Fill it in |
| "This value is already used." under Course code | Each Moodle course needs its own code | On own data, repeats are matched on the code, so run the course again in the same Moodle course. See [Repeat a course in the same Moodle course](academic-transcript/terms-and-course-fields.md#repeat-a-course-in-the-same-moodle-course). With Student Records as the source, the transcript of a student with a file there prints the codes of Student Records, and a code such as `ACC201 FA25` does no harm |
| A program is gone from a student's details or a catalog course after a save | An inactive program isn't in the menu, so the form falls back on "None". A known problem in 1.4.3 | Set the program to active, or pick it again |
| A code a student had is gone from Transcript codes | The mark was hidden, and the next save cleared it | Show the mark again, and enter the code |
| The Transcript Key says "only the latest grade counts in GPA" with another repeat rule | That default text doesn't follow the setting | [Change the text](academic-transcript/settings.md#transcript-key-last-page) |
| The key shows text in brackets | Placeholders print as they are until someone edits them | Edit the key on [Transcript settings](academic-transcript/settings.md) |

> Known problem in 1.4.3: the links "Grading scale template", "Marks template", "Programs template" and "Courses template" stop with the error "Exception - Call to undefined function send_file()". Make the file yourself. See [Use a CSV file](academic-transcript/programs-and-catalog.md#use-a-csv-file).

## Billing setup

| What you see | Why | What to do |
|---|---|---|
| "Create the Finance role" isn't there | Your role has no `moodle/role:manage` | Ask a site administrator |
| A person with the Finance role sees no Finance menu | Billing is off | [Turn it on](student-records/billing-setup.md#turn-billing-on-or-off) |
| Courses still wait for payment after billing was turned off, or after Course access was changed to "right away, when they register" | Neither change opens the courses that already wait. A known problem in 1.4.6 | [Give course access now](student-records/student-accounts.md#give-course-access-now), for each one |
| "Enter an amount such as 150 or 1500.00." | An amount has digits, at most two decimals, and an optional dollar sign. Commas go only between thousands | Correct it |
| "Tuition must be charged per hour, per course, per term or per program." | A tuition can't be Added by staff or Late fee | Make it a fee, or choose another option |
| "Enter ... dates written year-month-day (for example 2027-02-01), separated by commas." | The number of dates must match the number of payments, and each must be a real date | Correct them |
| "Enter a number of payments from 1 to 24." | The number is out of range | Correct it |
| "Enter a number from 1 to 100." on a payment plan | The down payment is a percent over 100. The message gives 1 as the lowest value, though the form takes 0 too. A known problem in 1.4.6 | Enter a percent from 0 to 100 |
| "Enter 0 or more days." | The number is below 0 | Correct it |
| A refund rule you added is never used | Rules run in order, and an earlier rule catches every case | Check its Order. See [Change a refund rule or its order](student-records/billing-setup.md#change-a-refund-rule-or-its-order) |

> Known problem in 1.4.6: the heading of the form that edits a payment plan shows [[editplan]] in place of a title. The form works as it should.

## Student accounts

| What you see | Why | What to do |
|---|---|---|
| "Enter an amount above zero, such as 150 or 1500.00." | The amount is 0, or it isn't an amount | Correct it |
| "This charge has a refund on it. Reverse the refund lines first." | A refund and a reversal would give the same money back twice | Reverse each Refund line of this charge, then the charge |
| "This line was already reversed, or it is itself a reversal." | Neither can be reversed | Nothing to do |
| "Give a reason." | A reversal needs a reason | Add it |
| No reverse link on a line | It is reversed, or a reversal, or you have no `local/studentrecords:managebilling`. On an aid line you need `local/studentrecords:manageaid` | See [Reverse a line](student-records/student-accounts.md#reverse-a-line) |
| "This line is not an aid disbursement, so it can't be reversed here. Finance reverses it on the student account." | You are on the aid page | Finance reverses it |
| A course stays on the notice after a payment | The access rule isn't met yet, or the course can't open | The payment stands. Check the [Audit log](student-records/audit-log.md), and use [Give course access now](student-records/student-accounts.md#give-course-access-now) |
| A charge comes back after you reversed it | "Add missing charges for a term" adds it again, at today's price | To let a student off, add a credit |
| A price per credit hour added by hand isn't multiplied | The line gets the price itself | Type the amount you want |
| "Paid in ..." leaves out an online payment | Online payments have no term | Nothing is wrong. They are in the balance |
| The Dashboard strip says $0.00 for a student with a credit | The strip shows no credit | Open the account |

> Known problem in 1.4.6: the credit form doesn't mark Description as needed, but it refuses a credit without one, with "Required". Type a description. See [Add a credit](student-records/student-accounts.md#add-a-credit).

## Payment plans

| What you see | Why | What to do |
|---|---|---|
| "This student already has an active payment plan, and a plan covers the whole balance. Finish or cancel that plan first, then give a new one." | One active plan per student, in any term | [Cancel it](student-records/payment-plans.md#cancel-a-plan), or finish it |
| "Nothing is owed, so there is nothing to put on a plan." | The balance is $0.00 or less | Nothing to do |
| "The due dates of this plan do not work for this term. Check the plan's due dates." | The plan's fixed dates don't match its number of payments | Correct the plan in [Billing setup](student-records/billing-setup.md#change-a-payment-plan-or-stop-offering-it) |
| "A custom plan needs a note saying why." | The note is empty | Add it |
| A warning that a plan is no longer paid in full and can't reopen | A payment was reversed, and another plan is active | See [When a payment on a plan is reversed](student-records/payment-plans.md#when-a-payment-on-a-plan-is-reversed) |
| A new plan's dates are all in the past | "Term" on the form starts with your oldest term. A known problem in 1.4.6 | Cancel the plan, reverse its plan fee, and give it again with the right term |
| A plan's hold stays after the student catches up | Staff release holds | Ask records staff |
| A charge added after the plan isn't on it | A plan covers the balance at the moment it's given | Collect it separately, or cancel the plan, reverse its plan fee, and give a new one |
| A plan says "Paid in full", and the account still has a balance | Money paid for a late fee, or for a charge added after the plan started, counts toward the plan. A known problem in 1.4.6 | Collect the rest separately. See [Read a plan on the account](student-records/payment-plans.md#read-a-plan-on-the-account) |
| "Email the plan agreement to the student" went to Moodle's notifications | It's a Moodle message, so email follows the person's preferences | See [What a person gets](student-records/messages.md#what-a-person-gets) |

> Known problem in 1.4.6: the schedule previews on the plan form always use the current term, whatever you choose in "Term". The amounts are right. See [Give a payment plan](student-records/payment-plans.md#give-a-payment-plan).

> Known problem in 1.4.6: due dates aren't moved forward. A plan given after a due date has passed starts with that payment past due. Once its grace days are over, the next daily task adds the plan's late fee, if it has one, and a hold. See [Give a payment plan](student-records/payment-plans.md#give-a-payment-plan).

## Paying online

| What you see | Why | What to do |
|---|---|---|
| "Online payment is not set up yet. Use one of the other ways to pay, or ask the school." | No usable payment account is chosen. The My home button shows whenever a student owes money | [Choose the account](student-records/online-payments.md#choose-the-account-in-billing-setup) |
| "Enter an amount from $1.00 up to your balance." | The amount is under $1.00 or over the balance | Enter another amount |
| "This payment was already made or is no longer open." | An old payment page was opened | Start again from My account |
| "Nothing is owed right now." | The balance is $0.00 or less | Nothing to pay |
| "Only the student can pay their own account online." | Someone else opened the payment page | Record the payment on the account |
| A payment left the student's card, and the account doesn't show it | Moodle's delivery didn't finish. A task runs every 5 minutes | Wait for it. See [If a payment doesn't reach the account](student-records/online-payments.md#if-a-payment-doesnt-reach-the-account) |
| The student paid twice | They paid again before the task ran, or from two tabs | Finance [pays it back](student-records/online-payments.md#pay-back-an-online-payment) |
| The [Audit log](student-records/audit-log.md) says "does not fit request" | The payment failed the checks and was not recorded | See [If a payment doesn't reach the account](student-records/online-payments.md#if-a-payment-doesnt-reach-the-account) |

> Known problem in 1.4.6: the Pay online card says "You get a receipt by email." The message has no receipt in it. The receipt is on the account.

> Known problem in 1.4.6: with no payment plan, the Pay online card says "Pay your next plan payment (-)". The dash means there is no plan payment.

> Known problem in 1.4.6: a balance under $1.00 can't be paid online. The card and the button still show, and the form refuses every amount. The student pays another way, or Finance adds a credit.

## Refunds

| What you see | Why | What to do |
|---|---|---|
| "A charge in this refund was reversed or refunded after the refund was figured. Figure the refund again." | Nothing was saved | [Figure it again](student-records/refunds.md#figure-a-refund-again), then decide it |
| "This refund was already decided." | Someone else got there first | Reload the page |
| "Give a reason." | Rejecting needs a note | Add it |
| "Enter an amount above zero and not more than the school owes (...)." | The amount is over what's owed | Correct it |
| The form says the school owes $0.00, and every amount is refused | The refund didn't take the account below zero | Nothing is owed to the student |
| An approved refund still says Approved after the money went back | A refund has no paid state, and the button stays | Check the account's payout line |
| A student got no message after a rejection | No message goes for a rejected refund | Tell the student |
| No refund came up after a drop | Billing was off, or the drop had no charges | See [How a refund comes up](student-records/refunds.md#how-a-refund-comes-up) |
| Term charges weren't refunded | The student has another active registration in the term | See [How a refund is figured](student-records/refunds.md#how-a-refund-is-figured) |

> Known problem in 1.4.6: when a Nothing back refund becomes Waiting for approval after you figure it again, no message goes to the people who approve refunds. Tell them yourself.

## Financial aid

| What you see | Why | What to do |
|---|---|---|
| The Financial Aid menu isn't there | The module is off, or the role is missing | See [Turn the module on](student-records/federal-aid.md#turn-the-module-on) |
| "The federal aid module is off. Schools approved for federal aid turn it on in the setup wizard > Approvals." | The award letter page was opened with the module off | The same |
| "Write the award year like 2026-27." | The year has another form | Correct it |
| "Choose a term. Federal aid is counted by term." | The term is empty | Pick a term |
| An award isn't in the table after you save | It's for another award year, and the page went back to the current one. A known problem in 1.4.6 | See [Read a student's aid page](student-records/federal-aid.md#read-a-students-aid-page) |
| "Enter an amount above zero and not more than what is left to disburse (...)." | The amount is over what's left | Correct it |
| "Only accepted awards can be disbursed, and Federal Work-Study is paid to the student, not to the account." | The award isn't accepted, or it's work-study | Change its status, or leave it to payroll |
| The student doesn't appear on Verification | Their aid year details aren't saved yet | [Enter them](student-records/federal-aid.md#enter-aid-year-details) |
| "Send award letter" warns | The message didn't go. The notice names its status in the message log, such as test mode | See [Send an award letter](student-records/federal-aid.md#send-an-award-letter) |
| "The worksheet shows nothing for the school to return." | Nothing goes back | See [When nothing goes back](student-records/aid-helpers.md#when-nothing-goes-back) |
| "Enter the withdrawal date before you send this worksheet." | The date is empty | Fill it in |
| "Enter the scheduled clock hours before you send this worksheet." | It's a clock-hour program | Fill them in |
| "The figures changed since you opened this worksheet. Check them and send it again." | Something changed after you opened it, and nothing was sent | Check the figures, and send again |
| "A Return of aid item for this student and term is already in Refunds." | One item per student and term, unless Finance rejected it | Open it in Refunds |
| The worksheet went back to its first figures | It saves nothing | Enter the details again |
| A loan of a student in a clock-hour program isn't tagged "Held · below half time", or the student shows F or H on the Enrollment status report | Clock hours count the same as credit hours. A known problem in 1.4.6 | Check the enrollment of those students yourself. See [Run disbursements for a term](student-records/federal-aid.md#run-disbursements-for-a-term) |

> Known problem in 1.4.6: the line about students not meeting academic progress links to the Standing page, which the Financial Aid role can't open. Ask the Registrar about those students.

> Known problem in 1.4.6: the award letter message tells the student to log in to accept or decline, and links to the account. No page links a student to the letter, and students can't accept or decline online. See [Send an award letter](student-records/federal-aid.md#send-an-award-letter).

> Known problem in 1.4.6: a return that Finance approved stays Approved after its "Federal aid returned" charges are reversed, and the worksheet then refuses a new return for that term. See [Send the return to Finance](student-records/aid-helpers.md#send-the-return-to-finance).

## Messages

| What you see | Why | What to do |
|---|---|---|
| "Waiting to send" in the message log | Most messages wait for the next cron run | See [Cron](#cron) |
| "Sent" in the log, and no email arrived | "Sent" means handed to Moodle. Email then follows the person's preferences and the site's mail settings | Check the person's notification preferences |
| "Not sent (error)" | Sending failed, or a staff message went to someone without `local/studentrecords:viewbilling`. It isn't tried again | Check Moodle's mail settings. For a staff message, see the known problem below |
| "Not sent: message switched off" | The message is off | [Turn it on](student-records/messages.md#change-a-message) |
| "Not sent: no Moodle account" | The student has no linked account, or it's suspended or deleted, or nobody holds a staff message's capability. A site administrator with no role gets no staff message | Link the account, or give the capability to someone |
| "Not sent: test mode (not a test address)" | Test mode is on with no inbox | [Turn test mode off](student-records/messages.md#test-mode-and-the-test-inbox), or set an inbox |
| "The test message could not be sent." | "Send me a test" failed | Check Moodle's mail settings. The test uses plain email, so a normal message may still work, and the other way round |
| "Enter a valid email address for the test inbox, or leave it empty." | The address has another form | Check it |
| A message shows a word in braces, such as `{amount}` | Only the listed fill-ins work. Anything else stays as typed | See [Change a message](student-records/messages.md#change-a-message) |
| "You can use" on a message's form leaves out `{due_date}`, `{pay_link}` and `{office_phone}` | The line names five of the eight fill-ins. A known problem in 1.4.6 | All eight work. See [Change a message](student-records/messages.md#change-a-message) |

> Known problem in 1.4.6: a staff message goes only to people who also hold `local/studentrecords:viewbilling`. Someone who holds `local/studentrecords:approverefund` or `local/studentrecords:managebilling` without it gets nothing, and the log says "Not sent (error)" for them. See [The 22 messages](student-records/messages.md#the-22-messages).

> Known problem in 1.4.6: saving a message's form always keeps its "Text" as your own, even when you changed only "On". Select "Reset to default" afterwards to go back to the default text. See [Change a message](student-records/messages.md#change-a-message).

## Reports and exports

| What you see | Why | What to do |
|---|---|---|
| "Nothing found.", "Nothing to report." | No row matches the filters | Change the filters |
| An export's card has no Run button | It uses race, ethnicity or sex, and you have no `local/studentrecords:viewdemographics` | Add it to the role |
| The Counts tab shows no counts by race, ethnicity or sex, and no download of them | The same | The same |
| "Required" under "Fields" or "Term" on an export | An export needs at least one field, and a list by term needs a term | Fill them in |
| "Hours in the term" is empty | The export has no term | Give it one |
| You can't delete an export | No page does that | Change it, or leave it |
| The 1098-T opens on last year | It does until the end of March | Choose the year |
| The taxpayer ID column is empty | The plugin stores no Social Security numbers | Your tax service adds it |
| A refund decision shows in the [Audit log](student-records/audit-log.md) as "Approved import items" or "Rejected import items" | Refund decisions use the same two labels as the import | Read "Record". "Refund" with a number is a refund, and "Import" is an import. See [Filter by action](student-records/audit-log.md#filter-by-action) |
| "Nothing logged yet." on the Audit log, when you know it has rows | The page says this whenever no row has the action chosen under "Action" | Choose "All" |
| A row of the Audit log names someone who didn't do it | Someone else did it with Moodle's "Log in as", and the row names the person logged in as. A known problem in 1.4.6 | Check Moodle's logs at Site administration > Reports > Logs. See [Read a row](student-records/audit-log.md#read-a-row) |
| A student in a clock-hour program shows F or H in the Clearinghouse file | Clock hours count the same as credit hours. A known problem in 1.4.6 | Check those students yourself. See [The Clearinghouse file](student-records/reports-and-exports.md#the-clearinghouse-file) |
| A starter export's columns change order after you save its Settings | A save puts the fields in the order of the "Fields" list. A known problem in 1.4.6 | Write your own column names in that order. See [The starter exports](student-records/reports-and-exports.md#the-starter-exports) |

> Known problem in 1.4.6: a refund decision shows in the Audit log as "Approved import items" or "Rejected import items". Read "Record" to tell them apart. See [Filter by action](student-records/audit-log.md#filter-by-action).

> Known problem in 1.4.6: an empty first line in "Own column names" is lost when you save, so every name moves up one field. Type the first field's own name on the first line. See [Add or change an export](student-records/reports-and-exports.md#add-or-change-an-export).

## Privacy and uninstall

| What you see | Why | What to do |
|---|---|---|
| A data request stays at "Pending" or "Approved" | Cron hasn't run | See [Cron](#cron) |
| A student's name and grades are still there after a deletion | A deletion keeps the permanent record | See [What a deletion keeps](student-records/privacy-and-uninstall.md#what-a-deletion-keeps) |
| The request says "Deleted", and the file still has the contact details | The site has data registry defaults, and Moodle didn't call the plugin | See [Sites with a data registry](student-records/privacy-and-uninstall.md#sites-with-a-data-registry) |
| The file keeps its data after an account was deleted by hand | A deletion request waits for approval | See [Deleting a Moodle account by hand](student-records/privacy-and-uninstall.md#deleting-a-moodle-account-by-hand) |
| A notice of an error in Enrolment on payment during a deletion | That is Moodle's own call | The request still ends as "Deleted" |
| "Error reading from database" on My transcript after an uninstall | The folder of Student Records is still there | [Remove the folder](student-records/privacy-and-uninstall.md#uninstall-the-plugin) |
| A verification code stops working | The student's data was deleted, or Academic Transcript was uninstalled | See [Privacy and uninstall](academic-transcript/privacy-and-uninstall.md) |

## Cron

Moodle's cron does the work that doesn't happen on a page. If it doesn't run, several things go quiet at once:

| What you see | Why |
|---|---|
| Messages stay at "Waiting to send" | They go at the next run |
| No plan reminders, late fees or billing holds | The task "Student Records billing: plan reminders and late payments" didn't run |
| Seat offers don't go out or end | The task "Student Records waitlists (offers not taken, and free seats)" didn't run |
| An online payment stays off the account | The task "Student Records online payments: record any that did not reach the student account" didn't run |
| Data requests don't move | Moodle prepares and carries them out in the background |
| Finished deletion requests of removed accounts aren't carried out | The task "Student Records privacy: carry out finished deletion requests of removed accounts" didn't run |

What to do: a site administrator goes to Site administration > Server > Tasks > Scheduled tasks, and checks "Last run" on the tasks. Then see [Make sure cron runs](student-records/install-and-setup.md#make-sure-cron-runs).

Academic Transcript has no scheduled tasks. A request's state is worked out when a page opens.

## Caches and upgrades

| What you see | Why | What to do |
|---|---|---|
| A page shows an old label, or a text in double square brackets, after an upgrade | Moodle keeps language strings and templates in caches | Go to Site administration > Development > Purge caches and select "Purge all caches" |
| New pages or menu links are missing after an install | The same | The same |
| The site says it is being upgraded, or is in maintenance | An install or an upgrade takes it down for about a minute | Wait |
| Site administration > Notifications asks to upgrade, and you didn't plan one | A plugin folder is on the server and not installed | Finish the upgrade, or remove the folder |
| "Error reading from database" | A table is missing, usually after a failed upgrade or an uninstall with the folder left in place | A site administrator turns on debugging at Site administration > Development > Debugging, reads the message, and runs the upgrade |
| A change to a role doesn't show | It works from the person's next page | Reload the page |

## If you are still stuck

1. Note what you did, and the exact words on the screen.
2. Ask your site administrator to look at the [Audit log](student-records/audit-log.md) of Student Records, at the [Activity log](academic-transcript/admin-guide.md#activity-log) of Academic Transcript, and at Moodle's logs at Site administration > Reports > Logs.
3. A site administrator can turn on debugging at Site administration > Development > Debugging, to see the full error. Turn it off afterwards.
4. Report a bug on the [issues page](https://github.com/Clouve/moodle-plugins/issues) of the repository. Say which plugin and release you use, and your Moodle version, PHP version and database. Leave out real student data.

Report a security problem in private, as [SECURITY.md](https://github.com/Clouve/moodle-plugins/blob/develop/SECURITY.md) says. Don't open a public issue for it.

## Related pages

- [The user manual](README.md)
- [Roles and permissions](roles-and-permissions.md)
- [Glossary](glossary.md)
- [Student Records](student-records/README.md)
- [Academic Transcript](academic-transcript/README.md)
- [Student Records theme](theme/README.md)
