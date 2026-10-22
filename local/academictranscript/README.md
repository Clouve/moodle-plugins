# Academic Transcript

Academic Transcript (`local_academictranscript`) is a Moodle plugin for Moodle 4.5 to 5.3. It makes academic transcripts from the final grades in your courses. Students can download an Unofficial copy at any time. Staff issue Official transcripts as PDF files with a verification code and a digital signature, and anyone who holds a code can check an Official transcript on a public page.

[Clouve Inc.](https://clouve.com) maintains the plugin. It is licensed under the GNU General Public License, version 3 or later.

## What it does

- Students see their own transcript and download an Unofficial copy, a PDF with an unofficial watermark.
- Teachers and managers issue Official transcripts for a named recipient (the "Issued To" field is required). Each Official transcript gets a transcript number and a 24-character verification code, is frozen as issued, and is digitally signed.
- Anyone can check a code on the public verify page. It shows the transcript as issued, without the date of birth.
- Teachers post final grades from a course. Posting copies the grade to the transcript and locks the student's course total in the gradebook. A later change needs a reason and an admin's approval. Until a grade is posted, the course shows as IP (In Progress).
- Teachers can enter a transcript code (P, NP, I, NC, W or a mark of your own) and a note for a student in a course. The code replaces the letter grade on the transcript. The gradebook doesn't change.
- The school sets its own grading scale, programs (credit hours or clock hours) and course catalog. It also decides which marks don't count in the GPA and which attempts of a repeated course count. All attempts stay on the transcript.
- A setup wizard takes an admin through the setup. It can read grading policies and course catalogs with the site's AI provider, or take CSV files. Nothing is saved until an admin approves it.
- An activity log records views, downloads, issues, verifications, postings and changes.

An Official transcript shows the academic record grouped by term, with the GPA of each term, the transfer credit and a summary. It has a signature, seal and verify block and a privacy footer. The last page is the Transcript Key. The paper size is US Letter or A4.

## Requirements

- Moodle 4.5 to 5.3. The CI of the repo runs on Moodle 4.5, 5.1, 5.2 and 5.3.
- PHP 8.1 to 8.4. Moodle 4.5 needs PHP 8.1 or later, Moodle 5.0 and 5.1 need 8.2 or later, and Moodle 5.2 and 5.3 need 8.3 or later. PHP 8.4 is tested with Moodle 5.3.
- PostgreSQL, MySQL or MariaDB.
- The PHP OpenSSL extension, which Moodle needs anyway. The plugin uses it to sign Official transcripts. Without it, Official transcripts are issued unsigned and say so.
- Optional: an AI provider with the "Generate text" action (Site administration > AI > AI providers), to read documents in the setup wizard.

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
- Admins: Site administration > Plugins > Local plugins > Academic Transcript. It has the Setup wizard, Transcript settings, Grading scale, Marks not used in GPA, Programs, Course catalog, Drafts to review, Terms, Students' transcripts, Grade change approvals, Issued Official transcripts, Activity log and Course codes check pages. A student's transcript page gives admins three more buttons: Edit student details, Transfer credit and Course terms. Course terms overrides the term of a course for that student.
- Anyone with a verification code: `/local/academictranscript/verify.php`. It needs no login.

Moodle 4.5 creates the Local plugins category only for site administrators. On Moodle 4.5, staff who aren't site administrators, such as managers, find the same pages under Site administration > Plugins > Academic Transcript.

## A term, step by step

1. An admin adds the term on the Terms page and sets each course's Course code, Hours and Term.
2. Teachers grade in the Moodle gradebook as usual. For a student who gets P, NP, I, NC or W, the teacher opens More > Transcript codes and enters the code and a reason. The reason is kept for the record and is not printed.
3. When the term is over, a teacher opens More > Post final grades, selects the students and selects "Post selected". Each posted grade is locked.
4. To change a posted grade, a teacher selects "Request change" and gives a reason. An admin approves or rejects the change on the Grade change approvals page.
5. To issue an Official transcript, a teacher or manager opens the student's transcript, types who it goes to in "Issued To" and selects "Issue Official transcript (PDF)". Moodle shows the transcript number and the verification code. The PDF can be downloaded from the student's page and from the Issued Official transcripts page.
6. Whoever receives the transcript can enter its code on the verify page.

An Official transcript can't be issued while a course the student is taking now has no course code, no hours, no term, or an hour type that doesn't match the student's program. The student's page lists what to fix.

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

Uninstalling deletes the plugin's data: the grading scale, marks, programs, catalog, terms, student details, transcript codes and notes, transfer credit, posted grades, change requests, issued Official transcripts, the activity log, drafts, the settings and the uploaded files. Verification codes printed on Official transcripts stop working after that. Back up what you need first.

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

The plugin supports Moodle's privacy tools (Site administration > Users > Privacy and policies > Data requests). The student is the data subject. An export or a deletion covers the student's details, codes, transfer credit, posted grades and their change requests, issued Official transcripts, and the log entries about the student. Drafts belong to the admin who uploaded the document.

Staff ids on student records, such as who posted or approved a grade, are declared in the privacy metadata, but they are not exported or deleted for the staff member. Exported log entries hide other people's IP addresses, so a user gets an IP address only for what that user did.

A failed verification is a log entry about no student. When the visitor was logged in, the entry holds that user's id and IP address, so it belongs to that user. An export includes it, with the IP address, and a deletion removes it. When the visitor wasn't logged in, the entry holds an IP address and no user, so no request reaches it.

The plugin makes no network calls of its own. In the setup wizard, Moodle sends the text of the documents that an admin uploads to the site's AI provider. Older Word, OpenDocument and RTF files first go through a document converter, if the site has one.

## Third-party code

- smalot/pdfparser 2.13.1 (LGPL-3.0, upstream https://github.com/smalot/pdfparser) reads text from PDF files in the setup wizard. It is in `lib/pdfparser/`: the upstream `src/` folder and `LICENSE.txt`, unchanged. `classes/local/import/document_text.php` loads it with its own autoloader, so there is no Composer step.
- Source Sans 3 (3.052) and Source Serif 4 (4.005), under the SIL Open Font License 1.1 (upstream https://github.com/adobe-fonts). The PDF transcripts embed these fonts. They are in `fonts/` as TCPDF font files, with the license texts `fonts/OFL-SourceSans3.txt` and `fonts/OFL-SourceSerif4.txt`.
- TCPDF, which writes the PDF files, comes with Moodle.

Both bundled items are declared in `thirdpartylibs.xml`. Each has a `readme_moodle.txt` that says where it came from and how to update it: `lib/pdfparser/readme_moodle.txt` and `fonts/readme_moodle.txt`.

## Help

Report bugs and ask questions on the [issue tracker](https://github.com/Clouve/moodle-plugins/issues). To report a security problem in private, follow [SECURITY.md](../../SECURITY.md). The release notes are in [CHANGES.md](CHANGES.md).

## License

Academic Transcript is free software. You can redistribute it and change it under the terms of the GNU General Public License, version 3 or later. The full text is in the `LICENSE` file in the plugin folder, and at https://www.gnu.org/licenses/gpl-3.0.html. Copyright 2026 Clouve Inc.

The bundled libraries and fonts keep their own licenses (see "Third-party code").
