# Glossary

This page explains the words you meet in Student Records, Academic Transcript and the Student Records theme, as these plugins use them. A word can mean something else in another system, or under a school's own rules.

## Contents

- [People and access](#people-and-access)
- [Terms and courses](#terms-and-courses)
- [Admissions and the student file](#admissions-and-the-student-file)
- [Registration](#registration)
- [Grades and GPA](#grades-and-gpa)
- [Transcripts](#transcripts)
- [Graduation and standing](#graduation-and-standing)
- [Money](#money)
- [Financial aid](#financial-aid)
- [Messages, reports and privacy](#messages-reports-and-privacy)
- [Moodle and the theme](#moodle-and-the-theme)
- [Related pages](#related-pages)

## People and access

| Word | What it means here |
|---|---|
| Admin | In Academic Transcript's messages, someone with a system capability, usually `local/academictranscript:managerecords`. It doesn't mean a site administrator |
| Advisor | A staff member named on a student's record. It's a field, and it gives no access |
| Capability | One permission in Moodle, such as `local/studentrecords:viewlog`. See [Roles and permissions](roles-and-permissions.md) |
| Finance role | "Finance (Student Records)", a role the plugin makes when you ask. See [The roles the plugins make](roles-and-permissions.md#the-roles-the-plugins-make) |
| Financial Aid role | "Financial Aid (Student Records)", made the same way, when the module is on |
| Manager | Moodle's Manager role at system level. It holds the capabilities of both plugins, and it isn't a site administrator |
| Menu group | One part of the Student Records menu: student, records staff, finance, financial aid, teaching or setup |
| No LMS access | A tick on a staff record for someone who never uses courses. They work in Student Records only |
| Privacy officer | The person who handles Moodle's data requests. A site administrator can too |
| Records staff | Anyone with `local/studentrecords:managestudents` or `local/studentrecords:viewstudents` at system level. They get the "Staff home" |
| Registrar | The person who runs the records office. It isn't a built-in role: you [make one](roles-and-permissions.md#registrar). In Academic Transcript it means whoever works through Transcript requests |
| Role | A named set of capabilities that you give to a person, at system level or in a course |
| Site administrator | A person with every right in Moodle. Only they open Transcript settings |
| Staff record | A faculty or staff record in Student Records. It's separate from a Moodle account |
| Student | In Student Records, a person with a student record. In Academic Transcript on own data, a person with a student role in a course |
| System level | Across the whole site. Most capabilities of these plugins work only from a role given here |
| Teacher | Moodle's Teacher role in a course. A Non-editing teacher takes attendance and has no transcript capability |

## Terms and courses

| Word | What it means here |
|---|---|
| Active term | A term with "Active" ticked. Only these take registrations |
| Add/drop | The first part of a term. A student can be added until "Add/drop ends", and a course left in this time is a drop, with no W |
| Catalog | The school's list of courses, each with a code, title and hours. Student Records and Academic Transcript each have their own |
| Clock hours | A unit for programs counted in hours of class time, as against credit hours |
| Course | In Student Records, an entry in the course catalog. It isn't a Moodle course. See Section |
| Course code | A course's code, such as ACC201. In Academic Transcript it's a course field that must be unique. On own data repeats are matched on it, so a course that runs again stays in the same Moodle course |
| Course field | Course code, Hours, Hour type and Term, on a Moodle course. Academic Transcript adds them |
| Course offering | See Section |
| Credit hours | The usual unit of a course or a program, as against clock hours |
| Grade freeze | The date after which grades of a term are closed. After it, only an approved grade change writes a grade |
| Last day to withdraw | The date after which a course can't be withdrawn from without an override |
| Level | A group of programs, such as Undergraduate or Graduate. Pass rules and standing rules go by level |
| Linked Moodle course | The Moodle course joined to a section. Registering enrols the student in it |
| Meeting | One class on one day, for attendance |
| Moodle course | The teaching space in Moodle, with its activities and gradebook |
| Prerequisite | A course a student must pass first. Only links made by the import are checked |
| Program | What a student studies, with a level, a unit, hours and a course list |
| Roster | In Course offerings, the students of a section. The Faculty roster is a list of staff |
| Section | One run of a catalog course in one term, with its teacher, days, times, room and seats. The pages call it a course offering. Each has a number, such as ENG101 01 |
| Term | A period of teaching, with dates for add/drop, withdrawing and the grade freeze |

## Admissions and the student file

| Word | What it means here |
|---|---|
| Admit term | The term a student was admitted for |
| Applicant | A status: someone who has applied |
| Application checklist | The documents an applicant must send. It records which came in, and it doesn't hold the files |
| Catalog year | The year of the catalog that applies to a student |
| Condition | A note on an admission, saying what the student must still do |
| Decision | The outcome of an application: admitted, denied, waitlisted or deferred |
| Deferred | A status: the student's start moved to a later term |
| Demographics | Sex, Hispanic or Latino, and race, for IPEDS reporting. They need `local/studentrecords:viewdemographics` |
| Directory information | Facts a school may give out, such as contact details. A student can withhold them, and then the file shows "Withheld (FERPA)" |
| Enrolled | A status: admitted and starting. Staff set it by hand |
| F-1 | A student on that visa. The optional module adds visa fields |
| File | Everything about one student in Student Records, with its tabs |
| Inquiry | A status: someone who has asked, and not applied |
| Program waitlist | A list of applicants for a program. It's only a list, and nothing offers a place |
| Status | Where a student stands: Inquiry, Applicant, Waitlisted, Admitted, Denied, Deferred, Enrolled, Active, Graduated, Withdrawn or On leave. See [Student status](student-records/student-file.md#student-status) |
| Student ID | The school's number for a student, in the format the school sets |
| Student record | The main row of the file. It needs a Moodle account |
| View as student | A staff button that shows what the student sees |

## Registration

| Word | What it means here |
|---|---|
| Cart | The classes a student picked online and hasn't confirmed. A cart holds no seat |
| Course access | Whether a registered student gets into the Moodle course yet. A rule on Billing setup decides |
| Drop | Leaving a course during add/drop |
| Hold | A block or a flag on a student's record, with a reason. Only a hold with "Blocks registration" stops registration. With Student Records as the transcript's source, any hold stops an Official transcript |
| Override | Staff going past a registration rule, with a reason. A blocking hold can't be overridden |
| Registration | A student's place in a section |
| Seat offer | A free seat held for the next student on a section's waitlist, for 2 days |
| Self-registration | Students registering themselves online. It's off until the school turns it on |
| Time clash | Two classes that meet at the same time. Only self-registration checks it |
| Waiting for payment | A registration whose Moodle course hasn't opened, because of the course access rule |
| Waitlist | A section's line of students who want a seat |
| Withdraw from a course | Leaving a course after add/drop, up to the last day to withdraw. The record shows W |
| Withdrawn | A status: the student left the school. Staff set it by hand |

## Grades and GPA

| Word | What it means here |
|---|---|
| Attempt | One time a student took a course |
| Course total | The student's total in the Moodle gradebook. It's where a final grade starts |
| Credits earned | Hours of courses passed |
| Final grade | The grade on the record, as against the gradebook. It comes from staff, from the gradebook, or from a teacher who posts it |
| GPA | Quality points divided by GPA hours |
| GPA hours | Hours that count in the GPA |
| Grade | A row of the grading scale with grade points, such as A or C+ |
| Grade change | A change to a final grade after it's set, which a second person approves |
| Grade points | The value of a letter, such as 4.0 for an A |
| Grading scale | The school's letters, percentages and points. With Student Records as the source, a student with a Student Records file is counted on the scale in Student Records, and a student without one on this plugin's own scale |
| IP | In progress: a course with no final grade yet. It's a state, not a letter |
| Mark | A row with no grade points, such as P, I, NC or W. It doesn't count in the GPA, and some earn hours |
| Pass rule | A rule that says what's the lowest passing grade for a level, program, group or course |
| Post | To send a final grade from a course to the record. In Academic Transcript it also locks the course total in the gradebook |
| Quality points | Hours multiplied by grade points |
| Repeat rule | Which attempts of a course count: only the latest, only the highest, or all |
| Transcript code | A mark a teacher enters for one student in a course, such as P, NP, I, NC or W, with a note and a reason. It replaces the letter once posted |
| W | A course left by withdrawal. In Student Records it's a state, not a letter from the scale. On Academic Transcript's own data it's a mark that a teacher enters as a transcript code |

## Transcripts

| Word | What it means here |
|---|---|
| Fee | What a transcript request costs, set per way of sending. It's recorded on the request, or goes on the account in Student Records |
| Frozen | An issued transcript keeps the data it had when issued. A later change to the record doesn't touch it |
| Issue | To make an Official transcript for a named recipient. The plugin doesn't send it: the registrar does |
| Issued To | The recipient printed on an Official transcript |
| My transcript | A student's own page in Academic Transcript |
| Official transcript | A PDF with a transcript number, a verification code and a digital signature, issued by staff |
| Own data | Academic Transcript's own posted grades, terms and details, as against reading from Student Records |
| Reissue | To make a new copy of a request, with a new number and code. The old code keeps working |
| Request queue | The Transcript requests page, where staff work through what students asked for |
| Source of the transcript | The setting that says if the transcript reads its own data or Student Records |
| Transcript Key | The last page of a transcript, which explains its codes and its grading |
| Unofficial copy | A PDF with a watermark, which a student downloads for themselves |
| Verification code | The 24-character code on an Official transcript. Anyone can check it on the verify page |

## Graduation and standing

| Word | What it means here |
|---|---|
| Academic standing | A student's place by the school's rules for a term: Good standing, Academic warning, Academic probation or Dismissal |
| Conditional approval | An approval of a graduation application while courses are still to finish. It can be conferred once the audit is complete |
| Confer | To award the credential. It sets the status to Graduated and fills in the award |
| Credential | What a program awards, such as a degree or a certificate |
| Degree audit | A check of a student's record against the program's requirements |
| Graduation application | The request to graduate, made by staff on the student's file |
| Honors | A wording added at conferral, from the school's rules |
| SAP | Satisfactory academic progress. The Standing rules (SAP) set the standards a run of standing applies |
| Standing rules | The school's rules for each level: lowest GPA, completion rate, and maximum time |
| Substitution | A course accepted in the place of a required one, on one student's audit |
| Transfer credit | Credit from another school. It can count toward the program, in the GPA, or both |

## Money

| Word | What it means here |
|---|---|
| Account line | One entry on a student account: a charge, credit, payment, refund or payout |
| Balance | What a student owes. It's the sum of the lines on the account. Below zero, the school owes the student |
| Billing | The money part of Student Records. It stays off until you turn it on |
| Charge | A line that adds to what the student owes |
| Credit | A line that takes off what the student owes, such as a waiver or a scholarship. It isn't money that came in |
| Custom plan | A payment plan made for one student, with a note saying why |
| Down payment | The first part of a plan, due the day it's given |
| Late fee | A fee the daily task adds for a late plan payment |
| Payment account | Moodle's record of where online payments go, with its payment providers |
| Payment plan | What a student owes, split into a down payment and later payments. A student has one at a time |
| Payment provider | PayPal, Stripe or another add-on that takes an online payment |
| Payout | Money the school pays back to a student, recorded on the account |
| Price list | The school's tuition and fees |
| Receipt | A PDF of one payment |
| Reversal | A new line that cancels an old one. The old line stays |
| Refund | An amount a drop or a withdrawal gives back on the account, by the school's refund rules. Someone approves it, and the money is a separate step |
| Refund rule | How much comes back, by when the student left |
| Statement | A PDF of an account for a term |
| Tuition | The price of teaching, per hour, course, term or program |
| 1098-T | The US tuition statement. The plugin gives a spreadsheet, and sends nothing to the IRS |

## Financial aid

| Word | What it means here |
|---|---|
| Aid year details | A student's dependency, year in school and similar facts for one award year |
| Award | An amount of aid offered to a student for an award year |
| Award letter | A page with a student's awards for a year |
| Award year | The aid year, written like 2026-27 |
| Disbursement | Aid put on the student's account as a credit |
| Disbursement run | One action that disburses the ready awards of a term |
| Federal aid module | The optional part of Student Records for aid. It's off by default, and it has no link to federal systems |
| Loan check | A helper that compares a student's loans with yearly limits. Check its results against the official rules |
| Return of aid worksheet | A helper for working out aid to send back when a student withdraws. Check its results against the official worksheet and the current handbook |
| Verification | A list of students whose aid year details say they are selected for verification |
| Work-study | Aid paid by payroll, which doesn't go on the account |

## Messages, reports and privacy

| Word | What it means here |
|---|---|
| [Audit log](student-records/audit-log.md) | Student Records' list of who did what |
| Activity log | Academic Transcript's list of who did what |
| Clearinghouse file | A file of enrollment for the National Student Clearinghouse. Staff download and send it |
| Counts | Unduplicated counts for surveys such as IPEDS |
| Cron | Moodle's background job. Most messages and daily tasks wait for it |
| Data request | Moodle's way to export or delete a person's data |
| FERPA notice | The school's annual notice, which students acknowledge |
| IPEDS | A US federal survey of schools. The Demographics part and the Counts use its categories |
| Message log | The list of every message, with its subject and status. The text isn't kept |
| Release authorization | A student's permission for the school to share records with a named person |
| Saved export | A list you define once and download again, for a state agency or an accreditor |
| Test inbox | One address that gets all messages while test mode is on |
| Test mode | A setting that keeps messages away from real people |

## Moodle and the theme

| Word | What it means here |
|---|---|
| Accent colour | The school's colour in the theme, for buttons, links and the active tab |
| Boost | Moodle's standard theme, and the parent of the Student Records theme |
| Colour mode | Light, Dark or System, on Moodle 5.3 |
| Compact logo | Moodle's small logo, set at Site administration > Appearance > Logos |
| Course index | The list of a course's sections in the left drawer |
| Dashboard strip | The line above the Dashboard's content, from Student Records on the theme |
| Drawer | A panel at the side of the page, which opens and closes |
| Gradebook | Moodle's grades of a course. Final grades in these plugins are separate from it |
| Side card | A small card beside a course page, from Student Records on the theme |
| Staff area | The page "All staff pages", which lists every staff page you can open |
| Start page | The page a person lands on. "Start page for users" can be Student Records |
| Top bar | The bar across the top of most pages |
| Wizard | A setup page with steps. Student Records has 11 steps, and Academic Transcript 6 |

## Related pages

- [The user manual](README.md)
- [Roles and permissions](roles-and-permissions.md)
- [Troubleshooting](troubleshooting.md)
- [Student Records](student-records/README.md)
- [Academic Transcript](academic-transcript/README.md)
- [Student Records theme](theme/README.md)
