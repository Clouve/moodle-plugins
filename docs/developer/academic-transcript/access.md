# Access

This page covers who may do what in Academic Transcript: the 8 capabilities and where each is checked, the guard of each page, and the rules that aren't capabilities. The capabilities are in `local/academictranscript/db/access.php`, and the helpers in `access` at `local/academictranscript/classes/local/access.php`.

## Contents

- [Words the code uses](#words-the-code-uses)
- [The capabilities](#the-capabilities)
- [Where each capability is checked](#where-each-capability-is-checked)
- [The access class](#the-access-class)
- [The guard of each page](#the-guard-of-each-page)
- [Rules that aren't capabilities](#rules-that-arent-capabilities)
- [What staff may do on their own record](#what-staff-may-do-on-their-own-record)
- [Traps](#traps)
- [Related pages](#related-pages)

## Words the code uses

| Word | Means in this plugin |
|---|---|
| Admin | A holder of `local/academictranscript:managerecords` at system level, as in `access::is_admin()`. In strings and comments it can loosely mean any holder of a system-level capability of this plugin. It doesn't mean site administrator: only the Transcript settings page needs one |
| Teacher | The `editingteacher` archetype. A non-editing teacher gets none of these capabilities |
| Registrar | Not a role. Whoever holds `local/academictranscript:issueofficial` at system level, for the queue. For a grade change on the Student Records path, whoever approves it in that plugin |
| Student | A user with a role of the `student` archetype assigned in a course context. A person with a Student ID and no such role is listed nowhere. A suspended enrolment still counts, because the check reads `role_assignments` and not enrolments |

## The capabilities

No entry has `clonepermissionsfrom`. The titles are the strings `academictranscript:<name>`.

| Capability | Context | Type | Risk | Archetypes |
|---|---|---|---|---|
| `local/academictranscript:viewown` | System | read | none | `user` |
| `local/academictranscript:issueofficial` | Course | write | `RISK_PERSONAL` | `editingteacher`, `manager` |
| `local/academictranscript:entercodes` | Course | write | `RISK_PERSONAL` | `editingteacher`, `manager` |
| `local/academictranscript:postgrades` | Course | write | `RISK_PERSONAL` | `editingteacher`, `manager` |
| `local/academictranscript:configure` | System | write | `RISK_CONFIG`, `RISK_PERSONAL` | `manager` |
| `local/academictranscript:approvechanges` | System | write | `RISK_PERSONAL` | `manager` |
| `local/academictranscript:managerecords` | System | write | `RISK_PERSONAL` | `manager` |
| `local/academictranscript:viewissued` | System | read | `RISK_PERSONAL` | `manager` |

Some titles say less than the capability does:

- `local/academictranscript:issueofficial` is also the right to see those students' transcripts and the Students' transcripts list.
- `local/academictranscript:managerecords` also covers terms, transfer credit, term overrides, and the signature and seal images in `local_academictranscript_pluginfile()`.
- `local/academictranscript:viewissued` also covers the activity log, the course codes check, and the download of any Official PDF.

The fields on the course form are guarded by a core capability, `moodle/course:changelockedcustomfields`, because all four are locked. See [course custom fields](data-model.md#course-custom-fields).

## Where each capability is checked

### `local/academictranscript:viewown`

- `access::can_view()`, at system level, on the person's own id only. That covers `local/academictranscript/index.php` and the profile link in `local_academictranscript_myprofile_navigation()`.
- Student Records checks it before it shows its My Transcript links in `shell`, `cards::transcript_link()` and `homes`. The link on a student's own `local/studentrecords/student.php` checks only that this plugin is installed.

### `local/academictranscript:issueofficial`

- `access::can_issue()`: at system level, or in any course where the target holds a student role. `access::can_view()` and `local/academictranscript/issued.php` use it.
- `local/academictranscript/requests.php`, at system level only.
- `local/academictranscript/students.php`: in the course, at system level, and in each of the teacher's courses.
- The admin tree entries `local_academictranscript_requests` and `local_academictranscript_students`.
- `local_academictranscript_extend_navigation_course()`, for the More menu.
- Student Records: `shell`, `cards::transcript_link()`, and `home::STAFFCAPS`.

### `local/academictranscript:entercodes`

- `local/academictranscript/codes.php`.
- `local_academictranscript_extend_navigation_course()`.

### `local/academictranscript:postgrades`

- `local/academictranscript/post.php`.
- `local/academictranscript/change.php`.
- `local_academictranscript_extend_navigation_course()`.

### `local/academictranscript:configure`

- The admin tree entries of `local/academictranscript/setup.php`, `local/academictranscript/grading.php`, `local/academictranscript/marks.php`, `local/academictranscript/programs.php`, `local/academictranscript/catalog.php` and `local/academictranscript/drafts.php`, and `require_capability()` again on each of those pages.
- `local/academictranscript/templates.php`.
- `local_academictranscript_pluginfile()`, for `sourcedocs`.
- `access::require_setup()`, and the setup notice in `local/academictranscript/index.php`.
- Student Records: `home::STAFFCAPS`.

### `local/academictranscript:approvechanges`

- The admin tree entry of `local/academictranscript/approvals.php`, and nothing else. `posting::decide()` doesn't check it, so a new caller of that method must.

### `local/academictranscript:managerecords`

- `access::is_admin()`, which `local/academictranscript/index.php` uses for the three buttons and for two views, and `local/academictranscript/students.php` for the link Edit student details.
- The admin tree entries `local_academictranscript_terms` and `local_academictranscript_students`.
- `local/academictranscript/record.php`, `local/academictranscript/transfer.php` and `local/academictranscript/studentterms.php`.
- The choice of branch in `local/academictranscript/students.php`.
- `local_academictranscript_pluginfile()`, for `signatureimage` and `sealimage`.

### `local/academictranscript:viewissued`

- The admin tree entries of `local/academictranscript/issued.php`, `local/academictranscript/log.php` and `local/academictranscript/coursecheck.php`.
- The download in `local/academictranscript/issued.php`.

## The access class

| Method | What it answers |
|---|---|
| `access::student_courseids()` | Course ids where the user holds a `student`-archetype role, from `role_assignments` at course level, leaving out the site course |
| `access::can_issue()` | Whether the current user holds `local/academictranscript:issueofficial` at system level, or in one of those courses |
| `access::can_view()` | Whether it is the user's own id and they hold `local/academictranscript:viewown`, or `access::can_issue()` |
| `access::require_setup()` | Redirects to the wizard while `setupcomplete` is empty, for holders of `local/academictranscript:configure` only |
| `access::is_admin()` | Whether the current user holds `local/academictranscript:managerecords` at system level |

`access::can_issue()` is what ties a teacher to their own students. It walks the student's courses and asks `has_capability()` in each, so it costs one capability check per course of the student.

## The guard of each page

`admin_externalpage_setup()` means `require_login(null, false)`, then the capability of the admin tree entry. "Setup" says whether the page calls `access::require_setup()`.

| Page | Who may open it | A write needs | Setup |
|---|---|---|---|
| `local/academictranscript/approvals.php` | Admin tree: `local/academictranscript:approvechanges` | `require_sesskey()` | yes |
| `local/academictranscript/catalog.php` | Admin tree and `require_capability()`: `local/academictranscript:configure` | Moodle's form sesskey | no |
| `local/academictranscript/change.php` | `require_login($course)`, `local/academictranscript:postgrades` in the course | Moodle's form sesskey | no |
| `local/academictranscript/codes.php` | `require_login($course)`, `local/academictranscript:entercodes` in the course | `confirm_sesskey()` | no |
| `local/academictranscript/coursecheck.php` | Admin tree: `local/academictranscript:viewissued` | No writes | yes |
| `local/academictranscript/drafts.php` | Admin tree and `require_capability()`: `local/academictranscript:configure` | `confirm_sesskey()` | no |
| `local/academictranscript/grading.php` | Admin tree and `require_capability()`: `local/academictranscript:configure` | `confirm_sesskey()` | no |
| `local/academictranscript/index.php` | `require_login(null, false)`, no guests, `access::can_view()` | `require_sesskey()`. Issue also needs `access::can_issue()`, and a request needs one's own page | no, only a notice |
| `local/academictranscript/issued.php`, the list | Admin tree: `local/academictranscript:viewissued` | No writes | yes |
| `local/academictranscript/issued.php`, with `id` | `require_login(null, false)`, then `access::can_issue()` or `local/academictranscript:viewissued` | No writes but the log | no |
| `local/academictranscript/log.php` | Admin tree: `local/academictranscript:viewissued` | No writes | yes |
| `local/academictranscript/marks.php` | Admin tree and `require_capability()`: `local/academictranscript:configure` | `confirm_sesskey()` | no |
| `local/academictranscript/post.php` | `require_login($course)`, `local/academictranscript:postgrades` in the course | `confirm_sesskey()` | no |
| `local/academictranscript/programs.php` | Admin tree and `require_capability()`: `local/academictranscript:configure` | Moodle's form sesskey | no |
| `local/academictranscript/record.php` | `require_login(null, false)`, `local/academictranscript:managerecords` at system level | Moodle's form sesskey | no |
| `local/academictranscript/requests.php` | `require_login(null, false)`, `local/academictranscript:issueofficial` at system level | `require_sesskey()` | no |
| `local/academictranscript/setup.php` | Admin tree and `require_capability()`: `local/academictranscript:configure` | Moodle's form sesskey, and `confirm_sesskey()` for one-click actions | it is the target |
| `local/academictranscript/students.php` | Three branches, below | No writes | admin branch only |
| `local/academictranscript/studentterms.php` | `require_login(null, false)`, `local/academictranscript:managerecords` at system level | `confirm_sesskey()` | no |
| `local/academictranscript/templates.php` | `require_login(null, false)`, `local/academictranscript:configure` at system level | No writes | no |
| `local/academictranscript/terms.php` | Admin tree: `local/academictranscript:managerecords` | Moodle's form sesskey | no, on purpose |
| `local/academictranscript/transfer.php` | `require_login(null, false)`, `local/academictranscript:managerecords` at system level | Moodle's form sesskey | no |
| `local/academictranscript/verify.php` | Anyone, with no login | No writes but the log | no |

`local/academictranscript/terms.php` skips the setup check on purpose, because the last step of the wizard asks for a term. Tests: `pages_test::test_terms_page_opens_before_the_setup_is_finished()`, `pages_test::test_other_admin_pages_wait_for_the_setup()`.

`local/academictranscript/students.php` has three branches:

1. With `courseid`: `require_login($course)` and `local/academictranscript:issueofficial` in the course. It lists that course.
2. Without it, for a holder of `local/academictranscript:managerecords` or `local/academictranscript:issueofficial` at system level: `admin_externalpage_setup()` and `access::require_setup()`. It lists all courses.
3. Otherwise: `require_login(null, false)`, then the courses from `enrol_get_my_courses()` where the user holds `local/academictranscript:issueofficial`. With none, `required_capability_exception`.

A wrong sesskey is an error on `require_sesskey()` pages and silent on `confirm_sesskey()` pages. See [how the guards behave](execution-flows.md#how-the-guards-behave). Tests: `pages_test::test_request_form_refuses_a_wrong_sesskey()`, `pages_test::test_queue_refuses_a_wrong_sesskey()`, `pages_test::test_approvals_page_refuses_a_wrong_sesskey()`.

## Rules that aren't capabilities

| Rule | Where it lives | The person sees | Tests |
|---|---|---|---|
| Nobody issues their own Official transcript | `transcript::issue()`, which every way of issuing passes through. `local/academictranscript/index.php` and `local/academictranscript/requests.php` also leave out the form and the button | `issueown` | `transcript_test::test_nobody_issues_their_own_transcript()`, `pages_test::test_staff_cannot_issue_their_own_transcript()`, `pages_test::test_the_queue_offers_no_issue_on_ones_own_request()` |
| Nobody posts their own final grade | `posting::preview()`, as its last check, over any other error. Student Records has its own check, the state `own` | `ownposting` | `posting_test::test_nobody_posts_their_own_grade()`, `srpost_test::test_nobody_posts_their_own_grade_on_their_registration()`, `pages_test::test_post_page_refuses_your_own_row()` |
| Nobody decides a change to their own grade | `posting::decide_problem()`, checked first | `changeownrecord` | `posting_test::test_nobody_decides_their_own_change()`, `pages_test::test_approvals_page_refuses_a_decision_on_your_own()` |
| Nobody decides a change they asked for | `posting::decide_problem()`, and again in `posting::decide()` | `changeownrequest` | `posting_test::test_decide_problem()` |
| Guests have no transcript | `local/academictranscript/index.php`, `local_academictranscript_myprofile_navigation()` | core `noguest` | `pages_test::test_transcript_page_refuses_guests()` |
| A teacher acts only for a student of their own course | `access::can_issue()` | The capability refusal | No test of its own |
| An Official transcript names a recipient | `transcript::issue()`, `local/academictranscript/index.php` | `recipientrequired` | `transcript_test::test_issue_requires_recipient()` |
| A hold stops every way of issuing | `transcript::issue()`, `requests::issue_request()`, `local/academictranscript/index.php` | `issueonhold`, `req_err_notready` | `requests_test::test_a_hold_blocks_issue_and_reissue()`, `pages_test::test_a_hold_stops_issuing_from_the_student_page()` |
| Open problems stop every way of issuing | `transcript::issue()` | `cannotissue`, `cannotissueyet` | `transcript_test::test_issue_refuses_when_problems_exist()` |
| The same action twice within 60 seconds is one action | `requests::REPEATWINDOW`, used in `requests::same_request()`, `requests::same_issue()` and `requests::issue_request()` | `req_saved_repeat`, `issuedrepeat`, `req_err_justissued` | `requests_test::test_the_same_request_twice_in_a_moment_is_one_request()`, `requests_test::test_the_same_direct_issue_twice_makes_one_copy()`, `requests_test::test_a_double_click_on_reissue_makes_one_copy()` |
| One action at a time on a request, with a wait of up to 10 seconds | `requests::one_at_a_time()`, `requests::LOCKWAIT` | `req_err_busy` | `requests_test::test_issue_makes_nothing_without_the_lock()`, `requests_test::test_create_saves_nothing_without_the_lock()` |
| Cancel and Issue exclude each other | Both take the lock `issue<id>` | `req_err_cancelled`, `req_err_notcancelled` | `requests_test::test_cancel_takes_the_lock_of_the_request()`, `pages_test::test_queue_changes_nothing_on_the_second_of_cancel_and_issue()` |
| Only an unissued request can be cancelled | `requests::cancel_request()` | `req_err_notcancelled`, `req_err_notpending` | `requests_test::test_a_cancel_that_waited_for_the_lock_finds_it_issued()` |
| A change needs a reason | `form\change_request`, `posting::request_change()` | `reasonmissing` | `posting_test::test_change_request_and_approval()` |
| A new grade must be on the menu | `posting::request_change()` | `invalidgrade` | `posting_test::test_change_request_and_approval()` |
| One change request waits per posted grade | `posting::request_change()`, `local/academictranscript/change.php` | `changepending` | `posting_test::test_change_request_and_approval()` |
| One posted grade per student, course and term | `posting::save_posted()`, the unique index `attempt` | `alreadyposted` | `posting_test::test_post_twice_in_same_term_fails()` |
| A code change needs a reason | `local/academictranscript/codes.php` | `reasonrequired` | No test of its own |

Keep each rule where it is. The rules in `transcript::issue()` and in `posting::preview()` sit in the method that every path goes through, and that is what makes them hold. A check on a page is only there to leave out a button.

## What staff may do on their own record

The rule across the plugins is that nobody acts on their own record, and refunds in Student Records can't be self-approved. In this plugin that covers posting, deciding a change, and issuing.

The maintainers left these allowed: a holder of `local/academictranscript:managerecords` can keep their own transfer credit in this plugin, on `local/academictranscript/transfer.php`. `local/academictranscript/record.php`, `local/academictranscript/studentterms.php` and `local/academictranscript/codes.php` have no own-record check either. Nothing stops a teacher asking for a change to their own grade, because the rule is at decision time.

A person with staff tools keeps them on their own page, with a tab for My transcript. Tests: `pages_test::test_staff_keep_their_tools_on_their_own_page()`, `pages_test::test_staff_can_request_their_own_transcript()`, `pages_test::test_records_staff_have_the_record_tools_on_their_own_page()`.

## Traps

- `local/academictranscript:issueofficial` is the right to see a transcript. A holder of only `local/academictranscript:managerecords` or `local/academictranscript:viewissued` can open `local/academictranscript/record.php` or the list of issued copies, and is refused on `local/academictranscript/index.php`, including the redirect after saving details.
- A teacher can issue from a student's page and can't open `local/academictranscript/requests.php`, which needs the capability at system level.
- Losing `local/academictranscript:viewown` gives an error that names `local/academictranscript:issueofficial`.
- A student can't download their own Official PDF, because `local/academictranscript/issued.php` needs `access::can_issue()` or `local/academictranscript:viewissued`.
- `access::require_setup()` redirects holders of `local/academictranscript:configure` only. Everyone else uses the pages with the setup unfinished.
- The wizard needs `local/academictranscript:configure`, and the Terms page it links to needs `local/academictranscript:managerecords`. Someone with only the first gets Access denied on that link.
- The Transcript settings page needs `moodle/site:config`, which the manager role doesn't have. A manager can only reach the settings in the wizard. See [how settings work here](settings.md#how-settings-work-here).
- A logged-in user can download the logo through `local_academictranscript_pluginfile()`. The signature and seal images need `local/academictranscript:managerecords`, and `signingcert` is never served.

## Related pages

- [Code map](README.md)
- [Execution flows](execution-flows.md)
- [Settings](settings.md)
- [Extending](extending.md#add-a-capability)
- [Privacy](privacy.md)
