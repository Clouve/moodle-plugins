# Changes

Release notes for Academic Transcript, newest first.

## 1.4.3

This release adds Official transcript requests and a link to the Student Records plugin. Sites on 1.0.0 upgrade through Moodle's normal upgrade, which adds one table and one field. No setting changes on its own: the transcript keeps using this plugin's own data until an admin picks Student Records.

For students:

- My transcript shows the student's own transcript, the Unofficial download, a form to request an Official transcript, and the student's requests.
- A request says how to send the transcript (email PDF, mail or pick up), who it goes to, and when (right away, after this term's grades, or after the degree).

For registrars:

- Transcript requests lists what is pending, waiting and on hold, and what was issued lately, with Issue and Reissue. The page is on the plugin's admin menu.
- Official transcript fees are new settings: $5 for email, $10 for mail or pick up.

For anyone who checks a transcript:

- Official PDFs have a QR code next to the signature. It opens the verify page with the code filled in.
- The verify page has a new design. It confirms the student, the date, the degree or program and who the transcript was sent to, and no longer shows the transcript itself, so a code never shows grades.

With Student Records 1.4.6 or later:

- The new setting "Source of the transcript" reads the student's details, terms, courses, grades, withdrawals, courses in progress, transfer credit and the conferred award from Student Records. GPAs, repeats and hours earned are worked out here, on the grading scale in Student Records, so the transcript and the record show the same figures. The transcript key prints that scale.
- "Post final grades" also saves the grade on the student's registration, on the school's grading scale there. The grade freeze and a grade set by the Registrar both block it.
- A grade below a program's minimum passing grade earns no hours on the transcript and says why. Transfer credit marked "in GPA" prints its letter and quality points and counts in the overall GPA.
- Teachers see the marks of Student Records for a student with a file there. A saved mark that the scale no longer has is an error, and the course total never replaces it.
- "Request change" sends a grade change request to the Registrar in Student Records. It offers the school's marks (I, P, NP, W, NC and others).
- The post page shows the current Student Records grade when it differs from the posted one.
- The fee of a request goes on the student's account when billing is on. A hold puts a request on hold, and a request can wait for grades or the degree.

Other changes:

- A grade that isn't on the grading scale in Student Records is listed as a problem and stops Official transcripts, because it would print with no points.
- The bundled fonts are now named Clouve Sans and Clouve Serif, because the Open Font License reserves the old name for the original fonts. Transcripts look the same, and only the font names in a PDF's properties change. Sites have nothing to do.
- Hours and grade points print with two decimals when they have them, so 5.25 no longer prints as 5.3.
- A hold stops every way of issuing an Official transcript.
- A double click can't charge or issue twice: on the request form, Issue, Reissue and the issue form on a student's page. Cancel and Issue can't both happen to one request.
- A posted grade and a grade the Registrar saves at the same moment run one after the other, so the change history is right.
- Staff keep their tools on their own page, with a My transcript tab beside them. Nobody can issue their own Official transcript, approve a change to their own grade, or post their own final grade.
- A change request goes to the registration that got the grade, so it reaches the right attempt of a course that ran twice. Each posted grade now keeps a link to its registration.
- Posting stops, with a message, when a course runs again and the student has no new registration. It never replaces a grade a teacher posted or the Registrar set.
- A grade posted here that isn't in Student Records is listed as a problem and stops Official transcripts, so it can't drop off without a word.
- One failed posting no longer affects the other students in the batch.
- Transcript requests has Cancel request on pending, waiting and on-hold rows. A reason is optional and goes in the activity log, and the fee stays on the account. An issued request can't be cancelled.
- A transcript issued by a build from before 1.0.0 downloads in today's layout. The oldest copies, which can't be drawn again, now say so in plain words. Their verification codes keep working.
- The upgrade drops the old `program` and `admissiondate` columns of those builds when they hold nothing else.
- Requests are in the privacy export and deletion.
- The activity log names the new actions.
- The pages keep their look on a site without Student Records.
- The pages follow Moodle 5.3's dark colour mode.
- The delivery choices on the request form show the price on its own line, and an email typed before switching to mail or pick up no longer stops the form.
- "After this term's grades" waits only for classes in a term that had started when the student asked.
- The plugin is now under the MIT License. Earlier releases stay under the GNU GPL. The bundled library and fonts keep their licenses.

## 1.0.0

First public release.

Academic Transcript makes transcripts from the final grades in Moodle courses.

- Official transcripts as PDF files. Each has a transcript number, a 24-character verification code and a digital signature, and is frozen as issued.
- Unofficial student copies, which students can download at any time.
- A public verify page, where anyone who holds a code can check an Official transcript.
- Final grades that teachers post from a course. Later changes need an admin's approval.
- Transcript codes (P, NP, I, NC, W and marks of your own) and notes, entered by hand.
- A setup wizard. It can read grading policies and course catalogs with the site's AI provider or from CSV files, and nothing is saved until an admin approves it.
- A grading scale, marks, programs, a course catalog, terms, transfer credit and a repeat rule, all set by the school.
- An activity log of views, downloads, issues, verifications, postings and changes.
- GPAs for each term and for the whole record that come from exact quality points, so rounding does not skew the overall GPA when courses have fractional hours.
- The same letters, hours, quality points and GPAs on every supported PHP version, PHP 8.4 included. A value exactly halfway rounds up.
- Transcripts and the digital signature use the full name of the site until a school name is set.
- Support for Moodle's privacy tools: a student's data, and the failed verifications that a logged-in user made, can be exported and deleted.

It works with Moodle 4.5 to 5.3, PHP 8.1 to 8.4, and PostgreSQL, MySQL and MariaDB.

Uninstalling the plugin keeps the Course code, Hours and Hour type course fields and their values. The README lists what else an uninstall changes.
