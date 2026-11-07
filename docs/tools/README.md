# Test site tools

These tools run a test Moodle in Docker with the three plugins of this repo, and fill it with a made-up school. The user manual's screenshots come from this site. A capture script walks each workflow of the manual in a real browser, apart from the steps the manual marks as not walked through.

Everything on the site is made up: Riverside College, its people, its address, its approvals and its keys. Mail never leaves the site. Never point these tools at a real site. `seed.php` writes to the database of whatever site it runs on, and `smoke.py` and the capture scripts sign in with known passwords.

## Contents

- [What's here](#whats-here)
- [Start a site](#start-a-site)
- [The people](#the-people)
- [The calendar](#the-calendar)
- [Programs and courses](#programs-and-courses)
- [The students](#the-students)
- [Money and aid](#money-and-aid)
- [Academic Transcript](#academic-transcript)
- [What is left undone](#what-is-left-undone)
- [Run a capture script](#run-a-capture-script)
- [Add a capture script](#add-a-capture-script)
- [Check a seed](#check-a-seed)

## What's here

| File | What it does |
|---|---|
| `site.sh` | Starts the containers, keeps the sites and their snapshots, runs PHP and cron on a site. `site.sh --help` lists the commands. |
| `seed.php` | Fills a fresh site with Riverside College. It uses the plugins' own classes. |
| `smoke.py` | Opens the pages of the site as each role and prints a PASS or FAIL line per page. |
| `wd.py` | A small WebDriver client. Capture scripts use it to drive Chrome and take screenshots. |
| `capture/` | One capture script per area of the manual. |
| `capture-all.sh` | Runs the capture scripts, each from the snapshot it names. |
| `optimize-images.sh` | Makes the screenshots smaller. |
| `assets/` | The made-up logo, badge, seal and signature, and the script that draws them. |

## Start a site

You need Docker, and the image `moodle-plugins-ci:8.3`, which `bin/check` builds the first time it runs.

```bash
docs/tools/site.sh up      # start the containers, install Moodle, save the snapshot "fresh"
docs/tools/site.sh seed    # fill site 1 and save the snapshot "seed"
```

There are two snapshots:

- `fresh` has Moodle and the three plugins, and no data. Use it for the setup wizards.
- `seed` has Riverside College. Use it for everything else.

`SITE` picks a site, 1 to 9. Each site has its own database and data folder. To put a snapshot on a site, and to start that site's web server if it isn't running:

```bash
SITE=3 docs/tools/site.sh restore          # the snapshot "seed"
SITE=3 docs/tools/site.sh restore fresh
```

The site answers at `http://riverside.example.com` inside the Chrome of the Selenium container. `wd.py` tells that Chrome where to find it, so that name works only there. From your computer, use `curl -H 'Host: riverside.example.com' http://127.0.0.1:1800<SITE>/`.

Other commands you will need:

```bash
SITE=3 docs/tools/site.sh cron             # run Moodle's cron once
SITE=3 docs/tools/site.sh adhoc            # run the waiting ad-hoc tasks, such as queued messages
SITE=3 docs/tools/site.sh sh 'pwd'         # run a command in the Moodle folder
SITE=3 docs/tools/site.sh php my-file.php  # run a PHP file on the site
```

`seed.php` takes about half a minute. All its dates come from the day it runs, so run it again before you retake the screenshots in a later month or year.

If a page comes up blank, read the PHP log of that site with `site.sh sh 'cat /tmp/php-1800<SITE>.log'`.

## The people

Every password is `Pass@1234`, except the one of `admin`, which is `Admin@123`. Every address is `<username>@example.com`.

### Staff

| Username | Name | Role | Use this person for |
|---|---|---|---|
| `admin` | Admin User | Site administrator | Site settings, payment accounts, themes |
| `mona` | Mona Whitfield | Manager, at system level | Anything a Manager can do |
| `rosa` | Rosa Delgado | Registrar (`srregistrar`) | Records, offerings, terms, grades, transcripts |
| `omar` | Omar Haddad | Grade change approver (`srapprover`) | The second person on grade changes. He holds only `local/studentrecords:approvegradechange` |
| `nina` | Nina Petrov | Admissions (`sradmissions`) | Applicants and decisions |
| `doris` | Doris Hale | Admissions, and marked "No LMS access" on her staff record | Staff who never use courses |
| `felix` | Felix Romero | Finance (`srfinance`) | Accounts, plans, refunds, money reports |
| `gwen` | Gwen Tanaka | Financial Aid (`srfinaid`) | Awards, disbursements, the Return of aid worksheet |
| `kira` | Kira Olsen | Registrar and Finance, and also a student | The rules about a person's own record |
| `irene` | Dr. Irene Novak | Teacher (`editingteacher`) | English, public speaking, psychology. She posts grades |
| `tomas` | Dr. Tomas Silva | Teacher (`editingteacher`) | Business, accounting, marketing |
| `carl` | Dr. Carl Jensen | Teacher (`editingteacher`), and the advisor of most students | Math, economics, leadership. Advisees are on his faculty record |
| `pia` | Pia Lindgren | Non-editing teacher (`teacher`) | Computer lab, biology, phlebotomy. She takes attendance and can't post grades |

The Registrar role follows the plugin's "Roles and staff" text: a system role that holds the Student Records capabilities a registrar needs. It also needs these, which a school may miss:

- `local/studentrecords:configure`, or the role can't open Course offerings and Terms.
- `moodle/user:viewalldetails`, or the "Moodle user" box on the student form stops with a permissions error. The Admissions role has this one too.
- `local/academictranscript:issueofficial`, `approvechanges`, `managerecords`, `viewissued`, `configure`, `postgrades` and `entercodes`, to issue, approve and manage transcripts.

The role has neither `moodle/site:configview` nor `moodle/course:view`, and Rosa is enrolled in no course. So she has no Site administration tab, and opens Academic Transcript's pages by their address. A page inside a course, such as Post final grades, sends her to the course's Enrolment options page.

### Students at a stage of admissions

They start next term.

| Username | Name | Status | Why they are here |
|---|---|---|---|
| `ian`, `bella` | Ian Lowell, Bella Cruz | Inquiry | Not applied yet |
| `ada` | Ada Whitlock | Applicant | Two documents missing |
| `noah` | Noah Kim | Applicant | Immunization record missing, which only certificate programs ask for |
| `lucy` | Lucy Tran | Applicant | Ready for a decision |
| `raj` | Raj Malhotra | Applicant | Ready for a decision, graduate program |
| `tara`, `evan` | Tara Quinn, Evan Shaw | Waitlisted | Places 1 and 2 on the program waitlist |
| `zoe` | Zoe Fischer | Admitted | Two classes in her cart, and a seat held for her in a full section |
| `mina` | Mina Park | Admitted | On a section waitlist, admitted with a condition |
| `kai` | Kai Lund | Admitted | On a section waitlist |
| `rex` | Rex Dolan | Denied | Denied with a reason on his file |
| `faye` | Faye Okafor | Deferred | Deferred to next term |
| `jonah` | Jonah Price | Enrolled | Registered himself for next term and hasn't paid, so his classes wait for payment |

### Students with a record

| Username | Name | Status | Why they are here |
|---|---|---|---|
| `hank` | Henry "Hank" Osei | Graduated | Degree conferred with honors, Official transcript issued, half his credits are transfer credit |
| `gina` | Gina Marsh | Active | Can apply for graduation: her audit is complete and nothing is owed |
| `marco` | Marco Ruiz | Active | Graduation application waiting for review, with a credit balance to pay back |
| `priya` | Priya Devi Nair | Active | Graduation approved on condition. One class left |
| `ben` | Benedict "Ben" Okoye | Active | Retook MAT110, keeps up with a payment plan, has a released hold and a release authorization |
| `carmen` | Carmen Luz Vega | Active | Aid paid off her payment plan, has an award letter and an enrollment letter |
| `dara` | Dara Whitman | Active | Late plan payments, with late fees and a financial hold from the daily task |
| `eli` | Eli Rosen | Active | Dropped a class next term, so a refund waits. Withdrew from a class this week, too late for money back |
| `nadia` | Nadia Farah | Active | Withheld her directory information (FERPA). Work-study, and a loan held for counseling |
| `theo` | Theodore "Theo" Bauer | Active | A grade change waits for approval. Held for verification. A hold that doesn't block registration |
| `uma` | Uma Reddy | Active | Transfer credit: one row with an equivalent course and in the GPA, one toward the program, one that doesn't count |
| `sean` | Sean Doyle | Active | At risk: low attendance and low course totals. On probation, and the term not yet run would take him further |
| `vince` | Vincent "Vince" Marlow | Active | Below half time, so his loan is held. Dropped a class next term, so a refund waits and a seat opened |
| `ivy` | Ivy Stone | Active | A hold that blocks registration, unpaid classes that wait for payment, a transcript request on hold |
| `yuki` | Yuki Mori | Active | F-1 student. Her ENG102 grade is already posted by her teacher |
| `sam` | Samuel "Sam" Ito | Active | Part time with loans, for the loan check |
| `dan` | Daniel "Dan" Reyes | Withdrawn | Left with aid paid out. His Return of aid worksheet hasn't been opened |
| `nell` | Nell Hughes | Withdrawn | Left with aid paid out. Her return is with Finance, waiting for approval |
| `wes` | Wesley "Wes" Bell | Withdrawn | Left the college last term. Refund approved and paid back |
| `leah` | Leah Abara | On leave | Her refund was rejected |
| `kira` | Kira Olsen | Active | Staff member in the master's program, for the own-record rules. Her own refund, grade change, graduation application and transcript request all wait for someone else, and she can't decide them |
| `alma`, `reed` | Alma Dunn, Reed Collins | Active | Graduate students. Alma has a registration with a staff override |
| `cleo`, `dante`, `jada`, `isaac` | Cleo Banks, Dante Hill, Jada Lewis, Isaac Boyd | Active | Clock hour students in the certificate program |
| `aiden`, `brooke`, `kofi`, `caleb`, `daisy`, `emil`, `lila`, `fiona`, `gabe`, `hana` | Various | Active | Ordinary students, who fill the class lists |

### Accounts without a student record

| Username | Name | Use this person for |
|---|---|---|
| `june`, `owen`, `paul` | June Adler, Owen Pratt, Paul Greer | "Add a student" and "Add applicant": a record needs a Moodle account first |
| `ruth`, `hugo`, `lena`, `arlo` | Ruth Keller, Hugo Lindqvist, Lena Ortiz, Arlo Tate | Academic Transcript's own data, below |

## The calendar

The running term began about ten weeks before the seed ran. The term names and dates follow from that day. A run on 6 November 2026 makes these:

| Term | Dates | State |
|---|---|---|
| Fall 2025 | 25 August to 12 December 2025 | Over, grades final, standing confirmed |
| Spring 2026 | 5 January to 24 April 2026 | Over, grades final, standing confirmed |
| Summer 2026 | 18 May to 10 July 2026 | Over, grades final, standing not run yet |
| Fall 2026 | 24 August to 11 December 2026 | Running. Add and drop are over, the last day to withdraw is ahead, grades aren't final |
| Spring 2027 | 4 January to 23 April 2027 | Next. Registration is open |

Each term has a grade freeze two weeks after it ends. The last day to withdraw is worked out at 75% of the term. Only the running and the next term are active.

## Programs and courses

| Code | Program | Level | Hours | Notes |
|---|---|---|---|---|
| `AS-BUS` | Business Administration, Associate of Science | Undergraduate | 60 credit | Full course list: Core Courses (required), an Accounting and a Marketing track, Electives |
| `MS-LDR` | Organizational Leadership, Master of Science | Graduate | 30 credit | No course list, so its audit uses hours and GPA alone |
| `CERT-PHL` | Phlebotomy Technician, Certificate | Certificate | 120 clock | Two courses, one after the other |

The catalog has 17 courses. Some have prerequisites, such as ENG102 after ENG101. Core Courses need a C, undergraduate courses a D, graduate courses a C and certificate courses 70%. Standing rules exist for the undergraduate and graduate levels. The certificate level has none, so you can add one.

Each section has days, times, a room, an instructor and a Moodle course with the teacher enrolled. One Moodle course of next term, BIO105, has no section yet, so a new section has something to link to. The Course code field must be unique, so only section 01 of the running term has the plain code, such as `ACC201`. Other terms' courses have the term in the code, such as `ACC201 FA25`.

Next term's COM150 is full: six seats, five students, one seat held for Zoe, and Mina and Kai on the waitlist. Next term's BUS101 section 02 meets at the same time as ENG101, so a student who picks both sees the clash. The hold on Zoe's seat ends two days after the seed ran, so run the seed again if you need it later.

In the running term:

- The Moodle courses have named sections, a Syllabus page and a Reading list page. They have no graded activity, only the "Final score" item that holds the course total.
- Teachers have taken attendance up to last week. The meetings of the last week aren't marked. BIO105 has no meetings yet, so you can add a weekly schedule. PSY101 is online and has none.
- Most sections have course totals in the gradebook, with one student in each who has none yet. So "Get final grades from Moodle" and "Post final grades" have work to do.

## The students

Past grades came three ways, so that each shows on a page:

- Most were entered by the Registrar.
- Spring's ACC201 came from the gradebook with "Get final grades from Moodle".
- Summer's COM150 was posted by Dr. Novak with "Post final grades".

Hank, Gina, Marco and Priya each have 30 credits of transfer credit, which is how they reach the 60 credits of the program with the courses in the catalog.

The message log goes back to the first term. Moodle's cron removes notifications older than about a month, so a student's home page shows only recent ones.

Most students have a date of birth, addresses, an emergency contact, prior education, demographics and a full checklist. Four have documents on their file. Some have read this year's FERPA notice, online or on paper. Ben, Nadia and Theo have release authorizations, and Theo's is revoked.

## Money and aid

The price list has tuition per credit hour and per clock hour, a registration fee and a technology fee per term, a lab fee per course, a one-time application fee, and fees that staff add by hand. There are three school plans and six refund rules. Course access waits for the down payment or a plan.

The payment account "Riverside College student payments" has PayPal turned on with made-up keys, so "Pay online" shows. A real payment can't go through.

The aid year is the one that holds the seed day. Awards exist in every status: offered, accepted, declined and cancelled. On the disbursement run for the running term, some lines are ready, some are held and one is paid by payroll.

## Academic Transcript

The transcript's source is Student Records. Its settings are filled in, with a seal and a signature image, and "Signature mode" set to images. To see own data, sign in as `admin` and change "Source of the transcript" in the settings, or use the four students below, who have no file in Student Records and so use own data on either setting.

| Username | What they have |
|---|---|
| `ruth` | Graduated, with an award on her details. Clean record |
| `hugo` | In a class now, with a term set by hand. A change to a posted grade waits for approval |
| `lena` | An I, and a hand-entered code in a class now |
| `arlo` | An F and a P |

All four have posted grades in three terms, and transfer credit.

Requests in the queue: Gina and Carmen are pending, Kira's own is pending, Priya waits for grades, Marco waits for the degree, Ivy is on hold, and Theo's is cancelled. Hank has one Official transcript, issued from his page. No request is in the issued state, because of the known problem described in the [registrar guide](../user/academic-transcript/registrar-guide.md).

## What is left undone

The seed holds what must exist before a workflow starts, not its result. These wait for you:

- Applicants to decide: Lucy, Raj.
- Grades to post: most sections of the running term.
- Grade changes to decide: Theo's, Kira's own, and Aiden's, which Kira asked for.
- Refunds to approve: Eli's, Vince's and Kira's own.
- A return of aid to approve: Nell's. A worksheet to open: Dan's.
- Aid to disburse: the ready lines of the run.
- Graduation: Marco and Kira to review, Priya to confer once her class is graded, Gina to apply.
- Standing to run: last term.
- Transcript requests to issue: Gina's, Carmen's.
- Money to pay back: Marco's credit balance.

## Run a capture script

Each script says which snapshot it starts from. It needs that snapshot on the site first:

```bash
SITE=2 docs/tools/site.sh restore seed
SITE=2 python3 -B docs/tools/capture/<name>.py
```

`-B` keeps Python's `__pycache__` out of the repo. `capture-all.sh` does the same for you.

To retake all the screenshots, from the right snapshot each time:

```bash
docs/tools/site.sh seed
SITE=2 docs/tools/capture-all.sh
```

The Selenium container has eight browser slots. A script that opens a browser waits for a free one, which can look like a hang.

## Add a capture script

Put it in `docs/tools/capture/<name>.py`. The first line after the docstring names its snapshot:

```python
"""Student Records: how the Registrar places a hold."""
# Snapshot: seed
import os
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
from wd import WD

b = WD()
try:
    b.login('rosa')
    b.go('/local/studentrecords/students.php')
    b.shot('docs/user/student-records/images/holds-list.png')
finally:
    b.close()
```

- It must run from a fresh restore with no help, and walk each step in the browser.
- Find a student by name, not by id. The ids can change when the seed changes.
- `b.login()` uses `Pass@1234`, so give `admin` its own password: `b.login('admin', 'Admin@123')`. A wrong password doesn't stop the script, so check that the page is the one you meant.
- Save images under `docs/user/<plugin>/images/`, named `<task>-<step>.png`.
- Close the browser at the end, in a `finally` so that a script that stops closes it too, or its slot stays taken.
- Keep test images and downloads out of the repo.

## Check a seed

```bash
docs/tools/site.sh seed
docs/tools/smoke.py
```

`smoke.py` restores nothing. It works on the site named by `SITE`, and that site must hold the snapshot `seed`. It exits 1 when any page fails. Name people to crawl only their pages: `docs/tools/smoke.py rosa carmen`.
