# Student Records access

All 16 capabilities and each place the code checks them, the menu groups, the two roles the plugin can make, and every rule that a capability can't express, with the method that enforces it and the test that protects it. Read [How a check works](#how-a-check-works) before you add a page or call a class from a new place.

## Contents

- [How a check works](#how-a-check-works)
- [Capabilities](#capabilities)
- [Capabilities of others](#capabilities-of-others)
- [Staff pages](#staff-pages)
- [Menu groups](#menu-groups)
- [Roles the plugin makes](#roles-the-plugin-makes)
- [Nobody acts on their own record](#nobody-acts-on-their-own-record)
- [What a person may do on their own record](#what-a-person-may-do-on-their-own-record)
- [Who sees a student](#who-sees-a-student)
- [Other rules](#other-rules)
- [Changing access safely](#changing-access-safely)
- [Related pages](#related-pages)

## How a check works

A page is the gate. It calls `require_login()`, then one of these:

| Gate | Used by | What it does |
|---|---|---|
| `staffarea::setup()` | Most staff pages | Tests `staffarea::can_open()`, throws `required_capability_exception` when it says no, and sets the page up. |
| `require_capability()` at system level | `letter.php`, `register.php`, `studentpart.php`, the student pages | The plain Moodle check. |
| `access::require_view_student()` | `student.php` | Own record, a shared course, or staff. |
| `billing::can_view()` | `account.php`, `receipt.php`, `statement.php` | Finance staff, or the student. |
| `attendance::can_take()` | `attendance.php` | Records staff, or `local/studentrecords:takeattendance` in the linked Moodle course. |

Most class methods check no capability. They trust the page that called them, so a new caller must make the same check. Seven methods check for themselves:

- `academic::decide_change()`
- `refunds::decide()`
- `aid::save_award()`
- `aid::disburse()`
- `aidhelp::save_year()`
- `aidhelp::send_return()`
- `billing::reverse()`, for an aid line

A site admin passes every `has_capability()`. The own-record rules test the user id, so they hold for a site admin too. Six places name site admins on purpose: `home::is_nolms()` never counts them, `home::shows_courses()` always gives them the courses link, `home::is_staff_user()` always counts them as staff, `hook_callbacks::after_config()` leaves them alone, `local/studentrecords/index.php` doesn't send them to a single course, and `payments::open_request()` lets them open anyone's request.

Actions that change data need the session key. A `moodleform` checks it itself. Anything else calls `require_sesskey()`: `admissions.php`, `aid.php`, `attendance.php`, `billing.php`, `billingsetup.php`, `degreeaudit.php`, `ferpa.php`, `gradechange.php`, `import.php`, `messages.php`, `myregistration.php`, `offerings.php`, `outsidereports.php`, `saprules.php`, `standing.php` and `studentpart.php` all do. `test_wrong_sesskey_is_refused` in `local/studentrecords/tests/money_pages_test.php`, `test_state_changes_need_the_session_key` in `local/studentrecords/tests/records_pages_test.php`, and the `sesskey` tests of `local/studentrecords/tests/local/registration_pages_test.php` protect this.

## Capabilities

`local/studentrecords/db/access.php` declares them, and each archetype below gets `CAP_ALLOW`. In the lists, "through the wrapper" means the code reaches the capability through `access::sees_all_students()` or `access::can_manage_students()`.

| Capability | Type | Context | Risk | Archetypes |
|---|---|---|---|---|
| `local/studentrecords:configure` | write | system | config | manager |
| `local/studentrecords:import` | write | system | config, personal, data loss | manager |
| `local/studentrecords:managestudents` | write | system | personal, data loss | manager |
| `local/studentrecords:viewstudents` | read | course | personal | editingteacher, teacher, manager |
| `local/studentrecords:viewown` | read | system | none | user |
| `local/studentrecords:managefaculty` | write | system | personal, data loss | manager |
| `local/studentrecords:viewfaculty` | read | system | personal | manager |
| `local/studentrecords:viewdemographics` | read | system | personal | manager |
| `local/studentrecords:approvegradechange` | write | system | personal, data loss | manager |
| `local/studentrecords:viewlog` | read | system | personal | manager |
| `local/studentrecords:viewbilling` | read | system | personal | manager |
| `local/studentrecords:managebilling` | write | system | personal, data loss | manager |
| `local/studentrecords:approverefund` | write | system | personal, data loss | manager |
| `local/studentrecords:takeattendance` | write | course | personal | editingteacher, teacher, manager |
| `local/studentrecords:viewaid` | read | system | personal | manager |
| `local/studentrecords:manageaid` | write | system | personal, data loss | manager |

`home::STAFFCAPS` names the capabilities that make a person staff for the single-course redirect of `local/studentrecords/index.php`: `configure`, `managestudents`, `viewstudents`, `viewfaculty`, `managefaculty`, `viewbilling`, `managebilling`, `viewaid`, `import` and `viewlog`, with two of Academic Transcript and `moodle/site:config`.

### `local/studentrecords:configure`

School setup.

- `require_capability()` in `terms.php`, `courses.php`, `programs.php`, `grading.php` and `setup.php`, and in `outsidereports.php` to edit an export.
- `has_capability()` in `outsidereports.php` (starter exports, settings links) and `standing.php` (the link to the standing rules).
- `staffarea::PAGES` for `offerings`, `terms`, `programs`, `courses`, `grading`, `checklist`, `saprules`, `setup` and `messages`. That is the only check on `offerings.php`, `checklist.php`, `saprules.php` and `messages.php`.

### `local/studentrecords:import`

- `require_capability()` in `import.php`, and `staffarea::PAGES` for `import`.
- `access::can_read_file()` for file area `importfile`.

### `local/studentrecords:managestudents`

Change student files.

- `access::sees_all_students()` and `access::can_manage_students()`.
- `require_capability()` in `student.php` with `edit`, `studentpart.php`, `register.php`, `letter.php`, `gradechange.php` with `reg`, and `offerings.php` for action `moodlegrades`.
- `staffarea::PAGES` for `admissions`, `standing`, `gradechanges`, `graduation`, `reports` and `outsidereports`.
- Through the wrapper: `student.php` (edit links, document names, change history), `students.php` (search on a preferred name, the add link), `offerings.php` (the button that pulls grades), `gradechange.php` (the whole list), `degreeaudit.php` (substitutions and approval), `admissions.php` (decision buttons), `attendance.php`, `attendance::can_take()`, `homes::staff()`, `shell::my_offerings()`, `access::can_read_file()`, `access::hide_directory()`.

### `local/studentrecords:viewstudents`

The one with two levels. At system level it opens every student, and at course level it opens the students of that course.

- At system level in `access::sees_all_students()`.
- At course level in `access::shared_courses()`, `local_studentrecords_extend_navigation_course()` and `students.php` with `courseid`.
- `staffarea::PAGES` for `students`, though `staffarea::can_open()` answers `students` with `access::sees_all_students()`, so `local/studentrecords:managestudents` opens it too.
- Named in the exceptions of `access::require_view_student()`, `students.php`, `staff.php` and `degreeaudit.php`.
- Through the wrapper: `students.php`, `offerings.php` (who is in a section), `facultyrecord.php` (advisees), `gradechange.php` (links to records), `student.php` (F-1 rows and the sections of a file), `degreeaudit.php`, `shell::groups()`.

### `local/studentrecords:viewown`

Given to every logged-in user by the `user` archetype. On its own it opens nothing: the person also needs a student file.

- `access::can_view_student()`, `access::can_read_file()` for `studentdocs`, `billing::can_view()`, `shell::groups()`, `cards::for_page()`, `cards::student_card()`.
- `require_capability()` in `ferpa.php`, `mydocuments.php` and `myregistration.php`.
- `has_capability()` in `pay.php`, `aidletter.php` and `degreeaudit.php`.

### `local/studentrecords:managefaculty`

- `require_capability()` in `facultyrecord.php` to edit a record or a credential.
- `has_capability()` in `facultyrecord.php`, `faculty.php` and `roster.php` for the links and the bulk form.
- Not in `staffarea::PAGES`: these pages open with `local/studentrecords:viewfaculty`.

### `local/studentrecords:viewfaculty`

- `require_capability()` in `faculty.php`, `facultyrecord.php` and `roster.php`, and `staffarea::PAGES` for `faculty` and `roster`.
- `access::can_read_file()` for file area `credential`.

### `local/studentrecords:viewdemographics`

- `admissions::can_view_demographics()`, used by `student_file::profile()` and `student_sections::render()`.
- `require_capability()` in `studentpart.php` for part `demog`, and in `outsidereports.php` to run an export with a field in `outside::DEMOGRAPHICS` or to download counts.
- `has_capability()` in `outsidereports.php` for the Counts tab and the export cards.

### `local/studentrecords:approvegradechange`

- `require_capability()` in `academic::decide_change()`.
- `has_capability()` in `academic::can_decide()`, `staffarea::can_open()` for `gradechanges`, and `gradechange.php`.

### `local/studentrecords:viewlog`

- `require_capability()` in `log.php`, and `staffarea::PAGES` for `log`.

### `local/studentrecords:viewbilling`

- `billing::can_view()`, `shell::groups()`, `student_file::glance()`.
- `has_capability()` in `account.php` and `degreeaudit.php`, and named in the exceptions of `account.php`, `receipt.php` and `statement.php`.
- `staffarea::PAGES` for `billing`, `refunds` and `billingreports`.
- `capability` of message provider `staffalerts` in `local/studentrecords/db/messages.php`, so Moodle sends the staff messages only to holders. See [Tasks and messages](tasks-and-messages.md#message-providers).

### `local/studentrecords:managebilling`

- `require_capability()` in `account.php` for actions `charge`, `credit`, `reverse`, `payment`, `access`, `plan`, `cancelplan` and `payout`.
- `has_capability()` in `account.php`, `billing.php` (charging a term), `refunds.php` (figuring again, the payout link) and `setup.php` (a link).
- `staffarea::PAGES` for `billingsetup`.
- Who gets the message `stafflate` (`messages::STAFF`), among those who also hold `local/studentrecords:viewbilling`.

### `local/studentrecords:approverefund`

- `has_capability()` in `refunds::decide()`, which throws `required_capability_exception`, and in `refunds.php`.
- Who gets the message `staffrefund` (`messages::STAFF`), among those who also hold `local/studentrecords:viewbilling`.

### `local/studentrecords:takeattendance`

Always at course level.

- `attendance::can_take()`, `cards::teacher_card()`, `shell::my_offerings()`, `local_studentrecords_extend_navigation_course()`.
- `require_capability()` in `attendance.php` with `course`, and named in that page's exception.

### `local/studentrecords:viewaid`

- `require_capability()` in `aidletter.php` when the letter isn't the person's own.
- `has_capability()` in `account.php` (the link to the aid page) and `shell::groups()`.
- `staffarea::PAGES` for `aid`. `staffarea::can_open()` says no to `aid` while the module is off, whatever the capability.

### `local/studentrecords:manageaid`

- `require_capability()` in `aid::save_award()`, `aid::disburse()`, `aidhelp::save_year()`, `aidhelp::send_return()`, and in `billing::reverse()` for an aid line.
- In `account.php` and `aid.php` for their aid actions and links.

## Capabilities of others

| Capability | Where |
|---|---|
| `moodle/role:manage` | `aid.php`, `billingsetup.php`: making a role |
| `moodle/role:assign` | `aid.php`, `billingsetup.php`: the link to assign it |
| `moodle/payment:manageaccounts` | `billingsetup.php`: the link to payment accounts |
| `moodle/site:config` | `settings.php`, `home::STAFFCAPS` |
| `moodle/course:viewhiddencourses` | `home::shows_courses()`, `index.php` |
| `moodle/course:update` | `home::is_staff_user()` |
| `local/academictranscript:viewown` | `shell::groups()`, `cards::transcript_link()`, `homes::student()` |
| `local/academictranscript:issueofficial` | `shell::groups()`, `cards::transcript_link()`, `home::STAFFCAPS` |
| `local/academictranscript:configure` | `home::STAFFCAPS` |

`home::is_staff_user()` tests `get_capability_info()` first, so a capability of an uninstalled sibling is skipped, not an error.

## Staff pages

`staffarea::PAGES` maps a name to a file, a string and a capability. `staffarea::can_open()` reads the capability, with three exceptions: `students` answers with `access::sees_all_students()`, `aid` is closed while the module is off, and `gradechanges` also opens with `local/studentrecords:approvegradechange`.

| Name | File | Capability |
|---|---|---|
| `students` | `students.php` | `local/studentrecords:viewstudents` |
| `admissions` | `admissions.php` | `local/studentrecords:managestudents` |
| `standing` | `standing.php` | `local/studentrecords:managestudents` |
| `gradechanges` | `gradechange.php` | `local/studentrecords:managestudents` |
| `graduation` | `graduation.php` | `local/studentrecords:managestudents` |
| `reports` | `reports.php` | `local/studentrecords:managestudents` |
| `outsidereports` | `outsidereports.php` | `local/studentrecords:managestudents` |
| `billing` | `billing.php` | `local/studentrecords:viewbilling` |
| `refunds` | `refunds.php` | `local/studentrecords:viewbilling` |
| `aid` | `aid.php` | `local/studentrecords:viewaid` |
| `billingreports` | `billingreports.php` | `local/studentrecords:viewbilling` |
| `billingsetup` | `billingsetup.php` | `local/studentrecords:managebilling` |
| `offerings` | `offerings.php` | `local/studentrecords:configure` |
| `terms` | `terms.php` | `local/studentrecords:configure` |
| `programs` | `programs.php` | `local/studentrecords:configure` |
| `courses` | `courses.php` | `local/studentrecords:configure` |
| `grading` | `grading.php` | `local/studentrecords:configure` |
| `checklist` | `checklist.php` | `local/studentrecords:configure` |
| `saprules` | `saprules.php` | `local/studentrecords:configure` |
| `faculty` | `faculty.php` | `local/studentrecords:viewfaculty` |
| `roster` | `roster.php` | `local/studentrecords:viewfaculty` |
| `import` | `import.php` | `local/studentrecords:import` |
| `setup` | `setup.php` | `local/studentrecords:configure` |
| `messages` | `messages.php` | `local/studentrecords:configure` |
| `log` | `log.php` | `local/studentrecords:viewlog` |

`offerings.php` opens with `local/studentrecords:configure`, not with a student capability. So someone with only `local/studentrecords:managestudents` can't reach a roster or the button that pulls grades, and someone with only `local/studentrecords:configure` sees a section without its students: the roster and waitlist need `access::sees_all_students()`, and pulling grades needs `local/studentrecords:managestudents`. `test_offerings_page_needs_managestudents_to_pull_grades` holds the second part.

`staffarea::pages()` lists what the current user may open, for `staff.php` and `staffarea::can_enter()`.

## Menu groups

`shell::groups()` builds the Student Records menu. A person gets every group they qualify for, in this order, and the result is cached for the request (`shell::reset()` clears it). With no group, the top menu has no Student Records tab: `hook_callbacks::primary_extend()` adds it only when there is a group.

| Key | Shown when | Items |
|---|---|---|
| `student` | The person has a file and `local/studentrecords:viewown` | Home, account (billing on), academic record, registration, transcript (with Academic Transcript and its `viewown`), documents, messages |
| `records` | `access::sees_all_students()` | Staff home, find a student, then by `staffarea::can_open()` admissions, offerings, standing, grade changes, graduation, reports, outside reports, and transcript requests with `local/academictranscript:issueofficial` |
| `approver` | Not `records`, and `staffarea::can_open()` says yes to `gradechanges` | Grade changes |
| `finance` | Billing on, and `local/studentrecords:viewbilling` | Finance home, accounts, payments, plans, refunds, 1098-T, reports |
| `aid` | `aid::enabled()`, and `local/studentrecords:viewaid` | Aid home, awards, disbursements, return of aid, verification, reports |
| `teacher` | `shell::my_offerings()` isn't empty | My classes, students at risk |
| `setup` | At least one item below opens | By `staffarea::can_open()`: setup wizard, terms, programs, courses, grading, checklist, standing rules, billing setup, messages, faculty, roster, import, audit log, then all staff pages |

`shell::my_offerings()` lists the offerings of active terms linked to a Moodle course where the person has `local/studentrecords:takeattendance` and is actively enrolled, or manages students.

`homes::views()` maps groups to home views through `homes::GROUPVIEW`: `student`, `records`, `finance`, `aid` and `teacher` each have a home, in that order, and the first is the default. So a staff member with a student file lands on the student home. `approver` and `setup` have none. A view in a URL that the person doesn't have falls back to the default (`homes::pick()`).

A menu item is a convenience. The page's own gate decides, so never treat a hidden link as a check. `test_the_menu_shows_an_approver_only_grade_changes` and `test_records_staff_find_grade_changes_in_their_menu` in `local/studentrecords/tests/local/staffarea_test.php` protect the `approver` split, and `local/studentrecords/tests/local/shell_inline_menu_test.php` protects how the menu prints on other themes.

## Roles the plugin makes

The plugin assigns nothing at install. Two pages can make a role, each with `moodle/role:manage` and the session key, and both set the role to the system level only.

| Role | Short name | Made by | From | Capabilities |
|---|---|---|---|---|
| Finance | `billing::FINANCEROLE` (`srfinance`) | `billing::create_finance_role()` | `billingsetup.php` | `billing::FINANCECAPS`: `local/studentrecords:viewbilling`, `local/studentrecords:managebilling`, `local/studentrecords:approverefund` |
| Financial Aid | `aid::ROLE` (`srfinaid`) | `aid::create_role()` | `aid.php` | `aid::ROLECAPS`: `local/studentrecords:viewaid`, `local/studentrecords:manageaid` |

Both methods return the existing role when one has the short name. The two roles are separate on purpose: the Financial Aid role can't open a student account, so `aid.php` makes its own reversals of aid lines. Uninstall removes a role when it holds only this plugin's capabilities (`test_plugin_roles_are_removed` and `test_role_with_other_capabilities_stays` in `local/studentrecords/tests/uninstall_test.php`).

## Nobody acts on their own record

A staff member can have a student file too. The test everywhere is `access::is_own()`: the record's `userid` is `$USER->id`. Each rule below sits in a class unless the table says page only, so a new page can't skip it.

| Act | Enforced in | Error string | What the page does as well | Tests |
|---|---|---|---|---|
| Set a final grade, as `staff` or `teacher` | `registration::write_grade()` | `err_gradeown` | `register.php` refuses action `grade`, and `student_sections::academic()` hides the link | `test_nobody_sets_their_own_grade`, `test_only_the_student_is_stopped`, `test_the_grade_page_refuses_your_own_grade`, `test_your_own_record_offers_no_grade_entry` |
| Post one's own grade as a teacher | `registration::teacher_post_target()`, state `own` | `err_teacherpost_own` | | `test_a_teacher_does_not_post_their_own_grade` |
| Decide a grade change on one's own grade | `academic::apply_decision()` | `err_changeownrecord` | `academic::can_decide()` hides the buttons | `test_only_an_approver_who_is_not_the_student_decides`, `test_nobody_decides_their_own_grade` |
| Decide a grade change one asked for | `academic::apply_decision()` | `err_changeself` | The same | `test_the_person_who_asked_cannot_decide`, `test_an_approver_does_not_decide_their_own_request`, `test_grade_change_page_refuses_the_person_who_asked` |
| Change program, status, award name or award date | `students::check_own_record()`, with `students::DECIDING` | `err_ownrecord` | `local/studentrecords/classes/form/student.php` freezes the fields and `student.php` drops the keys | `test_nobody_changes_what_decides_a_degree_on_their_own_record`, `test_the_edit_form_of_your_own_record` |
| Change status by any other route | `admissions::set_status()` | `err_ownrecord` | | `test_nobody_sets_their_own_status` |
| Make one's own file | `students::check_own_record()` with no row yet | `err_ownrecord` | The form's validation refuses it | `test_nobody_creates_their_own_record`, `test_the_add_form_refuses_yourself` |
| Record an admission decision | `admissions::record_decision()` | `err_ownrecord` | `studentpart.php` refuses part `decision`, and `admissions.php` and `student_sections::decisions()` hide it | `test_nobody_decides_their_own_admission`, `test_the_admissions_page_offers_no_decision_on_your_own_application` |
| Add or edit transfer credit | Page only: `studentpart.php` refuses part `xfer`, because `local_studentrecords_xfer` has no class | `err_ownrecord` | `student_sections::transfers()` hides the links | `test_your_own_record_takes_no_transfer_credit_or_decision`, `test_your_own_record_offers_no_transfer_credit_or_decision` |
| Approve a substitution | `degreeaudit::add_substitution()` | `err_ownrecord` | `degreeaudit.php` hides the form | `test_nobody_approves_their_own_substitution`, `test_the_degree_audit_offers_no_substitution_on_your_own_record` |
| Review a graduation application | `graduation::review()`, through `graduation::require_not_own()` | `err_gradownapp` | `graduation.php` calls it too, and `degreeaudit.php` shows a note | `test_nobody_reviews_or_confers_their_own_application`, `test_the_graduation_page_offers_no_decision_on_your_own_application`, `test_the_degree_audit_offers_no_approval_of_your_own_application` |
| Confer a degree | `graduation::confer()`, through `graduation::require_not_own()` | `err_gradownapp` | The same | The same |
| Decide a refund on one's own account | `refunds::decide()` | `err_refundself` | `refunds.php` shows no form | `test_staff_cannot_decide_their_own_refund` |
| Issue one's own Official transcript | `transcript::issue()` in Academic Transcript | `issueown` | | [Academic Transcript access](../academic-transcript/access.md) |

The gradebook is no person: `registration::write_grade()` tests only the sources `staff` and `teacher`, so `test_a_grade_from_the_gradebook_is_not_stopped` holds that a pull still fills a staff member's own grade.

## What a person may do on their own record

The maintainers left these allowed. A staff member with the capability can:

- keep their own holds (`academic::place_hold()`, `academic::release_hold()`) and their own standing overrides (`standing::confirm()`)
- keep their own transfer credit in Academic Transcript, which has its own table
- change any field outside `students::DECIDING`, which covers legal name, date of birth, admit term, catalog year, load, withdrawal date, last attended date, advisor, FERPA opt-out, F-1 fields, notes and documents (`test_staff_edit_the_rest_of_their_own_record`)
- apply for graduation, or cancel an application: `graduation::apply()` and `graduation::cancel()` have no check, and `graduation.php` skips it for `cancel`
- register, drop or withdraw (`registration::add()`, `registration::end()`)
- save the checklist (`admissions::save_checklist()`)
- add charges, credits, payments, payouts, reversals or plans on their own account (`billing::add_line()`, `payments::record()`, `refunds::payout()`, `billing::reverse()`, `plans::create()`), and figure a refund again (`refunds::refigure()`)
- edit their own aid awards (`aid::save_award()`, `aid::disburse()`)
- issue a letter (`letters::issue()`)
- ask for a grade change on their own grade (`academic::request_change()`): someone else decides it

## Who sees a student

`access::can_view_student()` answers in this order: staff who see all students, then the person themselves with `local/studentrecords:viewown`, then a teacher through `access::shared_courses()`.

A teacher reaches a student only through the student's active enrolment in a course where the teacher holds `local/studentrecords:viewstudents`. A suspended enrolment ends that, so a drop or a withdrawal ends a teacher's access. `access::shared_courses()` asks `enrol_get_users_courses()` for active enrolments only.

The tests below are in `local/studentrecords/tests/local/access_test.php`, `local/studentrecords/tests/records_pages_test.php`, `local/studentrecords/tests/local/registration_pages_test.php` and `local/studentrecords/tests/local/attendance_test.php`.

| Rule | Enforced by | Tests |
|---|---|---|
| A teacher sees the students of their courses and no others | `access::shared_courses()` | `test_teacher_sees_only_own_students`, `test_teacher_loses_a_suspended_student`, `test_teacher_cannot_open_or_edit_other_records` |
| A student sees only their own file | `access::can_view_student()` | `test_student_sees_only_own_record`, `test_student_cannot_open_another_record` |
| On `student.php`, asking by user id shows nothing about who has a file | The page calls `access::require_view_student()` before it reads the row | `test_a_user_id_does_not_reveal_who_has_a_record` |
| A course list holds that course's students only | `students.php`, with `get_enrolled_users()` and active enrolments only | `test_teacher_course_list_shows_only_that_course`, `test_teacher_of_another_course_cannot_list_it` |
| Directory information is hidden when a student opted out | `access::hide_directory()`, for anyone but the student and staff who manage students. `students::DIRECTORY` lists the fields. | `test_hide_directory`, `test_course_list_honours_the_opt_out`, `test_the_sheet_honours_the_ferpa_opt_out` |
| A hidden preferred name can't be found by searching | `students.php` adds `s.ferpaoptout = 0` to that search for anyone who doesn't manage students | `test_search_does_not_reveal_a_hidden_preferred_name`, `test_an_approver_does_not_see_a_hidden_preferred_name` |
| Visa details are for records staff and the student | `student.php` | `test_student_sees_own_visa_details`, `test_teacher_view_of_a_record` |
| The sections of a file are for records staff and the student | `student.php`, around `student_sections::render()` | |
| A student's documents are for the student and staff who manage students | `access::can_read_file()`. `student.php` shows the file names only to those who can open them. | `test_can_read_student_documents`, `test_cannot_read_own_documents_without_viewown` |
| Faculty credentials, imported files and the logo | `access::can_read_file()` | `test_can_read_other_files` |
| "View as student" is read only and logged | `viewas.php`, behind `staffarea::setup()` for `students` | `test_view_as_is_logged_and_read_only`, `test_student_cannot_view_as_another` |

Directory information is a display rule. `access::hide_directory()` doesn't stop the row being read: each place that prints a name or an address must ask it. `degreeaudit.php`, `attendance::sheet_name()` and `student_file::header()` do. `students::display_name()` and `student_file::profile()` don't ask: the caller passes the answer in, as `$hidepreferred` and `$hide`. A release authorization is the same: `student_sections::release_check()` shows staff what is in force, and nothing in the code blocks a release on it.

## Other rules

### Account and payments

| Rule | Enforced by | Tests |
|---|---|---|
| A student sees only their own account | `billing::can_view()` | `test_can_view`, `test_student_cannot_open_another_students_page` |
| Only the student pays their own account online | `pay.php`, `payments::create_request()` (`err_payownonly`) | `test_create_request_is_for_the_payer_and_the_balance`, `test_student_cannot_open_another_students_payment_request` |
| While someone is logged in, a request opens only for its payer or a site admin | `payments::open_request()` | `test_open_request_belongs_to_the_payer` |
| A payment must fit its request: payer, account, currency and amount | `payments::payment_fits()` | `test_deliver_refuses_a_payment_that_does_not_fit` |
| A student can't add to their own account | `require_capability()` in `account.php` for every action | `test_student_cannot_add_a_credit_to_their_own_account` |
| An aid line needs `local/studentrecords:manageaid` to reverse, on any page | `billing::reverse()`, with `billing::is_aid_line()` | `test_reversing_an_aid_line_needs_manageaid`, `test_account_refuses_a_direct_reversal_of_an_aid_line` |
| The aid page reverses only aid lines of its student | `aid.php` (`err_notaidline`) | `test_aid_page_reverses_only_aid_lines_of_its_student` |

### Grades and holds

| Rule | Enforced by | Tests |
|---|---|---|
| A second person decides a grade change | `academic::apply_decision()` | `test_a_second_person_approves` |
| After the grade freeze, only an approved change writes a grade | `registration::write_grade()` (`err_frozen`) | `test_the_freeze_stops_every_source` |
| Staff can correct a grade until the freeze | `registration::set_grade()` with source `staff`. This is a design, not a gap: the change history keeps every value. | `test_staff_correct_a_grade_until_the_freeze` |
| The gradebook and teachers never replace a grade staff set | `registration::KEPTSOURCES`, `registration::write_grade()` (`err_gradekept`) | `test_kept_grades_stay_whoever_writes` |
| Only an approved change uses source `change` | `registration::set_grade()` throws a `coding_exception` | `test_kept_grades_stay_whoever_writes` |
| A hold can't be overridden | `registration::add_seat()` | `test_a_hold_cannot_be_overridden`, `test_a_hold_stops_a_direct_request` |
| A hold stops an Official transcript on every path | `transcript::issue()`, through `requests::holds()` in Academic Transcript, when Student Records is its source | [Academic Transcript access](../academic-transcript/access.md) |

`academic`'s class docblock says a grade change is the only way for staff to change a final grade. The code doesn't work that way, and the test above holds the code: before the freeze, staff replace a grade directly.

### Grade change approvers

A person with `local/studentrecords:approvegradechange` and no student capability opens `gradechange.php`, sees only pending requests, and gets the `approver` menu group. `gradechange.php` forces `$state` to `pending` for them and offers no link into a record. `test_an_approver_opens_only_grade_changes`, `test_an_approver_sees_no_past_decisions`, `test_an_approver_gets_nothing_else` and `test_grade_changes_stay_closed_to_everyone_else` protect this.

### Modules that are off

| Setting | When off |
|---|---|
| `billing` | No `finance` group and no account item. `staffarea::PAGES` still lists `billing`, `refunds` and `billingreports`, so those pages open with `local/studentrecords:viewbilling`. `billing::charge_registration()`, `refunds::propose()` and `plans::daily()` do nothing, and `payments::access_ok()` says yes. |
| `fedaid` | `staffarea::can_open()` closes `aid`, `aid::require_enabled()` stops `aid::save_award()`, `aid::disburse()` and `aidletter.php`, and the `aid` group goes. |
| `f1` | The F-1 fields and filter go. Stored values stay. |
| `selfreg` | `selfreg::require_allowed()` stops the cart, and `waitlist::seat_opened()` makes no offers. The menu item stays and `myregistration.php` says why. |

`test_self_registration_off` and `test_no_offers_without_self_registration` hold the last row.

> Known problem in 1.4.6: registrations that already wait for payment keep waiting when `billing` goes off, or when `billaccess` becomes `immediate`, because saving the billing settings doesn't run `payments::release()`. Staff give them access on the account page.

### Self-registration

A student registers themselves only with a status in `selfreg::STATUSES`: `admitted`, `enrolled` or `active`. Everyone else goes through the registrar's office. `selfreg::require_allowed()` enforces it when a student adds to the cart, joins a waitlist or confirms, and `selfreg::closed_notice()` says why on the page. A student who can't pick classes today can still take classes out of the cart and leave a waitlist: `selfreg::remove()` and `waitlist::leave()` check nothing. `test_only_some_statuses_register_themselves` and `test_a_stopped_student_can_still_empty_the_cart` protect this. `registration::add()` itself tests no status, so staff can register anyone.

### Demographics in reports

Sex, ethnicity and race need `local/studentrecords:viewdemographics` wherever they appear. `outside::has_demographics()` tests an export, `outside::counts_show_demographics()` tests a part of the Counts tab, and only the list of completions by program goes without the capability. `test_demographics_in_an_export_need_their_capability`, `test_headcount_needs_the_demographics_capability`, `test_a_download_of_the_counts_needs_the_demographics_capability` and `test_completions_by_program_stay_without_the_demographics_capability` protect this.

### Attendance by section

`attendance::can_take()` tests the offering's own linked Moodle course. From a course, `attendance.php` lists every offering linked to it. Two sections linked to one Moodle course keep their own meetings and marks, and nothing joins them up for a teacher. The maintainers looked at this and left it. `test_a_teacher_takes_attendance_only_in_their_own_course`, `test_attendance_page_refuses_another_teachers_section` and `test_attendance_page_refuses_another_teachers_meeting` protect the check.

### No LMS staff

A faculty record with `nolms` 1 and a linked account keeps that person out of the LMS. `home::is_nolms()` decides, and site admins never count. `local_studentrecords_after_require_login()` and `hook_callbacks::after_config()` redirect to `home::URL` from any course but the site course and from any script under `home::LMS_PATHS`. `hook_callbacks::primary_extend()` takes away `myhome` and `mycourses`, `home::shows_courses()` hides the courses link, and `home::single_course_target()` never sends them to a course, or they would bounce back. `test_is_lms_page`, `test_single_course_target`, `test_saving_resets_no_lms`, `test_site_admin_is_never_no_lms`, `test_after_config_leaves_scripts_alone` and `test_no_lms_staff_with_one_course_are_not_sent_to_it` protect this.

## Changing access safely

To add a staff page, add a row to `staffarea::PAGES`, call `staffarea::setup()` first, and add the item to `shell::groups()` if it belongs in the menu.

To add a capability, follow [Extending](extending.md#add-a-capability), and add it to this page. Don't rename one: sites keep role assignments under that name.

To add a rule about a person's own record, put it in the class, not on the page. Transfer credit is the one exception, and only because `local_studentrecords_xfer` has no class. Add a test that calls the class directly.

A method that a second page might reach should check for itself, like the seven listed in [How a check works](#how-a-check-works).

## Related pages

- [Code map](README.md)
- [Data model](data-model.md)
- [Settings](settings.md)
- [Flows: registration and grades](flows-registration-and-grades.md)
- [Flows: money](flows-money.md)
- [Academic Transcript access](../academic-transcript/access.md)
- [Pitfalls](../pitfalls.md)
- [Roles and permissions, for users](../../user/roles-and-permissions.md)
