# Academic Transcript

Academic Transcript (`local_academictranscript`) is a Moodle plugin for Moodle 4.5 to 5.3. It makes academic transcripts from the final grades in your courses. Students can download an Unofficial copy at any time. Staff issue Official transcripts as PDF files with a verification code and a digital signature, and anyone who holds a code can check an Official transcript on a public page.

[Clouve Inc.](https://clouve.com) maintains the plugin. It is licensed under the MIT License.

## What it does

- Students see their own transcript on My transcript and download an Unofficial copy, a PDF with an unofficial watermark. They can also request an Official transcript there: by email, by mail or for pick up. With Student Records as the source, they can ask for it right away or after grades or the degree.
- The registrar works through those requests on the Transcript requests page.
- Teachers and managers issue Official transcripts for a named recipient (the "Issued To" field is required). Each Official transcript gets a transcript number and a 24-character verification code, is frozen as issued, and is digitally signed.
- Anyone can check a code on the public verify page, or scan the QR code on an Official transcript. The page confirms the student, the date, the degree or program and who it was sent to. It shows no grades and no date of birth.
- Teachers post final grades from a course. Posting copies the grade to the transcript and locks the student's course total in the gradebook. A later change needs a reason and an admin's approval. Until a grade is posted, the course shows as IP (In Progress).
- Teachers can enter a transcript code (P, NP, I, NC, W or a mark of your own) and a note for a student in a course. The code replaces the letter grade on the transcript. The gradebook doesn't change.
- The school sets its own grading scale, programs (credit hours or clock hours) and course catalog. It also decides which marks don't count in the GPA and which attempts of a repeated course count. All attempts stay on the transcript.
- A setup wizard takes an admin through the setup. It can read grading policies and course catalogs with the site's AI provider, or take CSV files. Nothing is saved until an admin approves it.
- An activity log records views, downloads, issues, verifications, postings, requests and changes.
- With the Student Records plugin, the transcript can read its data from there. See "With Student Records".

An Official transcript shows the academic record grouped by term, with the GPA of each term, the transfer credit and a summary. It has a signature, seal and verify block and a privacy footer. The last page is the Transcript Key. The paper size is US Letter or A4.

## Requirements

- Moodle 4.5 to 5.3. The CI of the repo runs on Moodle 4.5, 5.1, 5.2 and 5.3.
- PHP 8.1 to 8.4. Moodle 4.5 needs PHP 8.1 or later, Moodle 5.0 and 5.1 need 8.2 or later, and Moodle 5.2 and 5.3 need 8.3 or later. PHP 8.4 is tested with Moodle 5.3.
- PostgreSQL, MySQL or MariaDB.
- The PHP OpenSSL extension, which Moodle needs anyway. The plugin uses it to sign Official transcripts. Without it, Official transcripts are issued unsigned and say so.
- Optional: an AI provider with the "Generate text" action (Site administration > AI > AI providers), to read documents in the setup wizard.
- Optional: [Student Records](../studentrecords/README.md) (`local_studentrecords`) 1.4.6 or later. The plugin works without it.

## Install

1. Back up the database and the Moodle data folder.
2. Download `local_academictranscript-<release>.zip` from the [releases page](https://github.com/Clouve/moodle-plugins/releases).
3. Sign in as a site administrator and go to Site administration > Plugins > Install plugins.
4. Drop the ZIP file into "ZIP package" and select "Install plugin from the ZIP file".
5. Select "Continue" and then "Upgrade Moodle database now". Follow the screens. On the "New settings" page, select "Save changes".

To install by hand, unzip the file into the `public/local/` folder of your Moodle code on Moodle 5.1 and later, or into `local/` on Moodle 5.0 and earlier. Then open Site administration > Notifications as a site administrator. The [README of the repo](../../README.md) has the details.

A new install creates the Transcript course fields (see "Course fields"), loads the standard 4.0 grading scale and the standard marks, and marks the setup as not finished. Until you finish the setup wizard, some admin pages send you back to it.

## Set up

Go to Site administration > Plugins > Local plugins > Academic Transcript > Setup wizard. It has six steps.

1. On School details, enter the school's name, address, phone, email and website, its logo, and the registrar's name and title. They are printed on every transcript. If the school name is empty, transcripts and the digital signature use the full name of the site.
2. On Grading, set up the scale: load the standard 4.0 scale, read your grading policy, upload a CSV file, or edit the rows by hand. Each row has a letter, a percentage range, grade points, and whether it counts in the GPA.
3. On Marks, review the marks that don't count in the GPA, such as P, NP, I, NC, W, TR and IP. Add your own if your policy has others.
4. On Programs and hours, enter your programs and their courses. Degree programs use credit hours, and non-degree programs use clock hours.
5. On Transcript look, choose the page size, the accent color, the signature style, rounding, the repeat rule, and the labels for credit hours and clock hours.
6. On Finish, the wizard checks six things: the school name, a grading scale, marks, at least one program, at least one term, and no drafts waiting for review. The Terms page opens while the setup is not finished, so you can add the term there first. The term check in the list links to that page. "Finish setup" works even when a check is still open.

In steps 2 to 4 you can upload up to five documents of up to 20 MB each, such as a grading policy or a course catalog. The text of the documents goes to the site's AI provider, which proposes grades, marks, programs and courses as drafts. Each draft is shown next to the passage it came from, and nothing is saved until you approve it. Each admin accepts the site's AI policy once, before the first upload. PDF, Word (.docx) and text files are read directly. Older Word files, OpenDocument files and RTF files need one of Moodle's document converters. Without an AI provider, enter the data by hand or with the CSV templates that the wizard offers.

PDF text is read with Ghostscript when Moodle has a path to it (Site administration > Server > System paths), and with the bundled parser otherwise.

After the wizard:

1. If you have not yet, add your terms on the Terms page. Terms appear on transcripts in date order. You can rename a term but not delete it, because posted grades and course settings point to it.
2. Fill in the Transcript section of each course's settings (see "Course fields"). The Course codes check page lists the courses that still lack a code or hours.
3. Edit the Transcript Key and the footer text under Transcript settings. The defaults are templates written for a US school. Replace everything in [brackets], and keep only statements that are true for your school.
4. Optional: upload a document-signing certificate (.p12 or .pfx) under Transcript settings. Without one, the plugin signs Official transcripts with a self-signed certificate that it creates on the server. PDF readers show the signer of that certificate as not trusted, but any change to the file still breaks the signature.
5. Optional: open a student's transcript to enter the student details (student ID, date of birth, program, admit term, status, qualification and award date) and any transfer credit. The student's program decides whether the transcript shows credit hours or clock hours.

## Where things are

- Students: the profile page, under Reports, has "Academic transcript" (`/local/academictranscript/index.php`). Staff who may see a student's transcript find the same link on the student's profile.
- Teachers: in a course, the More menu has "Transcript codes", "Post final grades" and "Students' transcripts".
- Admins: Site administration > Plugins > Local plugins > Academic Transcript. It has the Setup wizard, Transcript settings, Grading scale, Marks not used in GPA, Programs, Course catalog, Drafts to review, Terms, Students' transcripts, Transcript requests, Grade change approvals, Issued Official transcripts, Activity log and Course codes check pages. A student's transcript page gives admins three more buttons: Edit student details, Transfer credit and Course terms. Course terms overrides the term of a course for that student.
- Anyone with a verification code: `/local/academictranscript/verify.php`. It needs no login.
- Registrars: Transcript requests (`/local/academictranscript/requests.php`). It is on the Academic Transcript admin menu, and in the Student Records menu when that plugin is installed.

Moodle 4.5 creates the Local plugins category only for site administrators. On Moodle 4.5, staff who aren't site administrators, such as managers, find the same pages under Site administration > Plugins > Academic Transcript.

## A term, step by step

1. An admin adds the term on the Terms page and sets each course's Course code, Hours and Term.
2. Teachers grade in the Moodle gradebook as usual. For a student who gets P, NP, I, NC or W, the teacher opens More > Transcript codes and enters the code and a reason. The reason is kept for the record and is not printed.
3. When the term is over, a teacher opens More > Post final grades, selects the students and selects "Post selected". Each posted grade is locked. Nobody can post their own final grade: their row says why and has no box.
4. To change a posted grade, a teacher selects "Request change" and gives a reason. An admin approves or rejects the change on the Grade change approvals page. Nobody can approve or reject a change they asked for, or a change to their own grade. Grade change approvals shows why in place of the buttons.
5. To issue an Official transcript, a teacher or manager opens the student's transcript, types who it goes to in "Issued To" and selects "Issue Official transcript (PDF)". Moodle shows the transcript number and the verification code. The PDF can be downloaded from the student's page and from the Issued Official transcripts page.
6. Whoever receives the transcript can enter its code on the verify page.

An Official transcript can't be issued while a course the student is taking now has no course code, no hours, no term, or an hour type that doesn't match the student's program. The student's page lists what to fix.

## Official transcript requests

A student opens My transcript and fills in the request form: how to send it, who it goes to, and with Student Records as the source, when. The registrar then opens Transcript requests, selects Issue, and sends the PDF the way the student asked. The plugin doesn't send it. Reissue makes a new copy with a new verification code. Nobody can issue their own Official transcript: another person must do it, and their own page and queue row say so.

Set the fees under Transcript settings > Official transcript fees. They default to $5 for email and $10 for mail or pick up, and 0 means free. With Student Records as the source, its billing on and a file there, the fee goes on the student's account. Otherwise the fee is only recorded on the request, and the school collects it another way. The student sees which of the two applies.

To cancel a request that is pending, waiting or on hold, open "Cancel request" on the queue. A reason is optional, and it goes in the activity log. A cancelled request keeps its fee on the student's account, and the queue says so before you confirm.

A double click can't charge or issue twice. Two quick submissions of the same request make one request and one fee, and the student is told if a repeat was left out. The same request a minute later is a new one, and so is a different request at any time. On the queue, two clicks on Issue, or two registrars at once, make one Official transcript. Reissue works again a minute after the last copy, and that includes the first Issue. On the issue form of a student's page, the same recipient within a minute gets the first copy back, with its number and code, and another recipient gets a new transcript. Cancel and Issue can't both happen to one request: whichever comes second changes nothing and says why.

Registrars and admins open their own transcript in a staff view with all their tools, and a My transcript tab beside it. The My Transcript links in Student Records open My transcript. Students and course teachers see no change.

## With Student Records

Install [Student Records](../studentrecords/README.md), then set Transcript settings > Source of the transcript to Student Records. The default is this plugin's own data, and nothing changes until you pick Student Records.

For each student with a Student Records file:

- The transcript reads the legal name, student ID, date of birth, program, admit term, status, terms, courses, final grades, withdrawals (W), courses in progress (IP), transfer credit and the conferred award from there, read only.
- "Post final grades" also saves the grade on the student's registration. The letter comes from the grading scale in Student Records. Posting is blocked after the term's grade freeze date, and when the Registrar already set the grade.
- "Request change" on a posted grade sends a grade change request to the Registrar in Student Records.
- A hold in Student Records stops every way of issuing an Official transcript until it is released: Issue and Reissue on the queue, and the form on the student's transcript page.
- A request can wait for this term's grades or for the degree.

A student without a Student Records file keeps the transcript from this plugin's own data. Official transcripts already issued keep their frozen copy.

Grades posted before you pick Student Records stay in this plugin and aren't read from there. A student's transcript lists each one with no attempt of the same course code there as a problem, and it stops Official transcripts for that student until the Registrar enters the grade in Student Records. So enter those grades first, or pick Student Records before grades are posted.

A course that runs again needs a new registration for each student. Without one, posting stops with a message rather than replacing a grade a teacher posted or the Registrar set.

GPAs, hours earned and repeats are worked out here, on the grading scale in Student Records, so the transcript and the student's record show the same figures. The transcript key prints that scale. Teachers see the marks of that scale for a student with a file there. The Grading scale and Marks pages in this plugin cover only students without a file, and both pages say so. The one exception is the key's wording for W, IP and TR, which comes from this plugin when Student Records has none.

A course earns its hours when Student Records says it passed. A grade below a program's minimum passing grade stays in the GPA and earns no hours. If it would otherwise pass, it has a note on the transcript. Transfer credit marked "in GPA" prints its letter and quality points and counts in the overall GPA, but not in any term's GPA. Other transfer credit prints as TR and only adds to the hours earned. A school that counts transfer credit in the GPA on a site from before 1.4.3 should check the GPA text of the key under Transcript settings, because a saved text stays as it is.

A grade that isn't on the scale in Student Records has no grade points, so it is listed as a problem, and it stops Official transcripts for that student until the grade or the scale is fixed.

## Transcripts from older builds

Builds from before the first public release stored issued transcripts in older layouts. The upgrade keeps them, and their verification codes still work. A copy that has terms downloads in today's layout. The oldest copies have no terms, so they can't be drawn again: Download says so, and you can issue a new Official transcript instead.

Those builds also have two more columns on the student table, `program` and `admissiondate`. The upgrade drops each one only when it holds nothing else: a program name with no linked program, or an admission date, keeps its column.

## Roles and capabilities

Students can open their own transcript. Teachers can issue Official transcripts only for users who have a student role in one of the teacher's own courses. Managers can do everything, including the setup, approvals, terms, student records, issued transcripts and logs.

| Capability | Lets the holder | Level | Default roles |
|---|---|---|---|
| `local/academictranscript:viewown` | View own transcript and download an Unofficial copy | System | Authenticated user |
| `local/academictranscript:issueofficial` | Issue Official transcripts | Course | Teacher, Manager |
| `local/academictranscript:entercodes` | Enter transcript codes (P, NP, I, NC, W) and notes | Course | Teacher, Manager |
| `local/academictranscript:postgrades` | Post final grades and request changes | Course | Teacher, Manager |
| `local/academictranscript:configure` | Configure the transcript: setup wizard, grading scale, marks, programs and catalog | System | Manager |
| `local/academictranscript:approvechanges` | Approve or reject changes to posted grades | System | Manager |
| `local/academictranscript:managerecords` | Edit student details, terms, transfer credit and the terms of a student's courses | System | Manager |
| `local/academictranscript:viewissued` | See the log of issued Official transcripts, the activity log and the course codes check | System | Manager |

To change who can do what, edit the roles under Site administration > Users > Permissions > Define roles.

## Course fields

The install creates a course custom field category named Transcript with four fields. Fill them in on each course's settings page, in the Transcript section.

| Field | Short name | Type | Notes |
|---|---|---|---|
| Course code | `transcript_coursecode` | Text | Required and unique, up to 50 characters. Printed on the transcript. |
| Hours | `transcript_creditunits` | Number | From 0 to 5000, with one decimal. Credit hours or clock hours, printed on the transcript and used for the GPA. |
| Hour type | `transcript_hourtype` | Menu | Credit hours or Clock hours. |
| Term | `transcript_term` | Menu | The term the course runs in. The options are the terms on the Terms page. |

The fields are locked, so only users who hold the Moodle capability `moodle/course:changelockedcustomfields` (managers and site administrators by default) can edit them. If a course has no Hours or Hour type, the plugin uses the course catalog entry with the same course code. The short names never change, because the values on courses are stored against them.

## Command line

`cli/set_course_fields.php` sets the Course code and the Hours of many courses at once.

```bash
sudo -u www-data php public/local/academictranscript/cli/set_course_fields.php \
    --set="2|ACC 502|3" --set="3|ACC 503|" --execute
```

- Run it in the Moodle folder as the web server user. On Moodle 5.0 and earlier, the path starts with `local/` instead of `public/local/`.
- Each `--set` is `courseid|code|hours`. An empty part leaves that value as it is.
- Without `--execute` it is a dry run. It prints what would change and saves nothing.
- It stops when a code would belong to two courses, because course codes are unique.
- It sets the Course code and the Hours only. Set the Hour type and the Term in the course settings.

## Uninstall

Uninstalling deletes the plugin's data: the grading scale, marks, programs, catalog, terms, student details, transcript codes and notes, transfer credit, posted grades, change requests, issued Official transcripts, transcript requests, the activity log, drafts, the settings and the uploaded files. Verification codes printed on Official transcripts stop working after that. Back up what you need first.

1. Go to Site administration > Plugins > Plugins overview. Find Academic Transcript under Local plugins and select Uninstall.
2. Confirm. Moodle deletes the plugin's data.
3. Delete the plugin folder from your Moodle code folder: `public/local/academictranscript` on Moodle 5.1 and later, `local/academictranscript` before that. If Moodle can delete the folder itself, it offers to. Otherwise the last page tells you to remove it by hand. Don't leave it in place, or Moodle installs the plugin again.

What the uninstall does to the rest of the site:

- The Course code, Hours and Hour type fields stay, with the values saved on courses. They are no longer required, unique or locked, so courses stay editable.
- The Term field is deleted with its values. They only pointed into the plugin's term list.
- The folder `local_academictranscript` in the Moodle data folder is deleted. It holds the self-signed key that signed Official transcripts.

If Moodle can't delete a file in that folder, it shows no message, and the folder stays with what is left in it. Look for it in the Moodle data folder (the `dataroot` setting in `config.php`) and delete it by hand. Run the command as a user who may delete those files, such as root. For example:

```bash
rm -r /var/moodledata/local_academictranscript
```

The Transcript category stays too. To remove it, go to Site administration > Courses > Default settings > Course custom fields and delete the category. That deletes the fields in it and their values on courses. Move the fields you want to keep to another category first.

If you install the plugin again later, it finds the three kept fields by their short names and sets them up as before: the Course code is required and unique, and all three are locked. It creates the Term field again, locked, with an empty term list.

## Privacy

The plugin supports Moodle's privacy tools (Site administration > Users > Privacy and policies > Data requests). The student is the data subject. An export or a deletion covers the student's details, codes, transfer credit, posted grades and their change requests, issued Official transcripts, requests for Official transcripts, and the log entries about the student. A request can hold the name, email or address of whoever it goes to, and those go with it. What a student has in Student Records is that plugin's to export or delete. Drafts belong to the admin who uploaded the document.

Staff ids on student records, such as who posted or approved a grade, are declared in the privacy metadata, but they are not exported or deleted for the staff member. Exported log entries hide other people's IP addresses, so a user gets an IP address only for what that user did.

A failed verification is a log entry about no student. When the visitor was logged in, the entry holds that user's id and IP address, so it belongs to that user. An export includes it, with the IP address, and a deletion removes it. When the visitor wasn't logged in, the entry holds an IP address and no user, so no request reaches it.

The plugin makes no network calls of its own. In the setup wizard, Moodle sends the text of the documents that an admin uploads to the site's AI provider. Older Word, OpenDocument and RTF files first go through a document converter, if the site has one.

## Third-party code

- smalot/pdfparser 2.13.1 (LGPL-3.0, upstream https://github.com/smalot/pdfparser) reads text from PDF files in the setup wizard. It is in `lib/pdfparser/`: the upstream `src/` folder and `LICENSE.txt`, unchanged, and `GPL-3.0.txt`, the GNU GPL text that the LGPL asks for. `classes/local/import/document_text.php` loads it with its own autoloader, so there is no Composer step.
- Clouve Sans and Clouve Serif, under the SIL Open Font License 1.1. These are modified versions of Adobe's Source Sans 3 (3.052) and Source Serif 4 (4.004) (upstream https://github.com/adobe-fonts), renamed because the license reserves the name. The PDF transcripts embed these fonts. They are in `fonts/` as TCPDF font files, with the license texts `fonts/OFL-SourceSans3.txt` and `fonts/OFL-SourceSerif4.txt`.
- TCPDF, which writes the PDF files and the QR code on Official transcripts, comes with Moodle.

Both bundled items are declared in `thirdpartylibs.xml`. Each has a `readme_moodle.txt` that says where it came from and how to update it: `lib/pdfparser/readme_moodle.txt` and `fonts/readme_moodle.txt`.

## Help

Report bugs and ask questions on the [issue tracker](https://github.com/Clouve/moodle-plugins/issues). To report a security problem in private, follow [SECURITY.md](../../SECURITY.md). The release notes are in [CHANGES.md](CHANGES.md).

## License

Academic Transcript is under the MIT License. Anyone can use, change and share it. The full text is in the `LICENSE` file in the plugin folder. Copyright 2026 Clouve Inc.

The bundled libraries and fonts keep their own licenses (see "Third-party code").
