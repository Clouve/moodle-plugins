# Changes

Release notes for Academic Transcript, newest first.

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
