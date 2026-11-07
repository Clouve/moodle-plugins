# Privacy

This page covers what the privacy provider of Academic Transcript declares, exports and deletes, and what it leaves alone. The code is `provider` in `local/academictranscript/classes/privacy/provider.php`, and the tests are in `local/academictranscript/tests/privacy/provider_test.php`.

## Contents

- [Whose data it is](#whose-data-it-is)
- [The two table lists](#the-two-table-lists)
- [What the metadata declares](#what-the-metadata-declares)
- [Finding a user's data](#finding-a-users-data)
- [What an export holds](#what-an-export-holds)
- [What a deletion removes](#what-a-deletion-removes)
- [What the provider leaves alone](#what-the-provider-leaves-alone)
- [When you add a table or a column](#when-you-add-a-table-or-a-column)
- [Related pages](#related-pages)

## Whose data it is

`provider` implements `\core_privacy\local\metadata\provider`, `\core_privacy\local\request\core_userlist_provider` and `\core_privacy\local\request\plugin\provider`.

- The data subject is the student. Everything about a student is stored against the student's user context.
- A row that names a user only as the staff member who acted is part of the student's record. That covers `usermodified`, `postedby`, `issuedby`, `requestedby`, `decidedby`, and the `userid` of a log entry about a student. The metadata declares those fields, and the rows are neither exported nor deleted for that staff member.
- A draft belongs to the user who uploaded the document.
- A log entry about no student is different. A failed verification written while the visitor was logged in holds that user's id and IP address and nothing about a student, so it belongs to that user. One written by a visitor who wasn't logged in names no user, so no request reaches it.
- The school's settings tables are nobody's data. Each row names the admin who last changed it. The metadata declares that, and nothing is exported or deleted for that admin.

## The two table lists

| Constant | Tables | Meaning |
|---|---|---|
| `provider::TABLES` | `local_academictranscript_student`, `local_academictranscript_code`, `local_academictranscript_issue`, `local_academictranscript_transfer`, `local_academictranscript_posted`, `local_academictranscript_draft`, `local_academictranscript_request` | `userid` is the owner of the row |
| `provider::SETTINGS_TABLES` | `local_academictranscript_grade`, `local_academictranscript_mark`, `local_academictranscript_program`, `local_academictranscript_catalog`, `local_academictranscript_term` | Declared and never exported or deleted |

`local_academictranscript_change` and `local_academictranscript_log` have rules of their own, below.

## What the metadata declares

`provider::get_metadata()` adds one `add_database_table()` for each of the 14 tables, with a string `privacy:metadata:<name>` for each field and table.

These columns aren't declared, and `provider_test` lists them as holding no personal data:

- `local_academictranscript_posted`: `unittype`, `srregid`.
- `local_academictranscript_draft`: `itemtype`, `data`, `source`, `sourcefileid`, `quotefound`, `status`.
- The `id` of every table, and every column of the five settings tables except `usermodified` and `timemodified`.

The metadata declares no external location for the AI provider. Moodle's AI subsystem carries the text of uploaded documents, and it has its own privacy provider. The metadata declares no link to a core subsystem.

The string `privacy:metadata:note`, "Note printed on the transcript.", is also used for `note` of `local_academictranscript_request`. That column holds the reason for a cancellation, and it isn't printed.

Tests: `provider_test::test_get_metadata_declares_every_table()`, `provider_test::test_get_metadata_declares_the_fields_of_each_table()`, `provider_test::test_every_table_and_column_is_declared_or_holds_no_personal_data()`, `provider_test::test_every_metadata_string_exists()`.

## Finding a user's data

`provider::has_data()` is true when any table of `provider::TABLES` has a row with that `userid`, or a row of the log matches `provider::log_select()`.

`provider::log_select()` is the rule for log rows:

```php
return ['(studentid = :student OR (studentid = 0 AND userid = :author))', ['student' => $userid, 'author' => $userid]];
```

So a user owns the entries about them as the student, and the entries about no student that they made while logged in. A log entry that only names the user as the one who acted on a student doesn't count.

`provider::get_contexts_for_userid()` returns the user context when `provider::has_data()` is true. `provider::get_users_in_context()` does the same for a user context, and it adds only that one user.

Tests: `provider_test::test_contexts_for_student_with_data()`, `provider_test::test_contexts_for_a_row_in_any_data_table()`, `provider_test::test_contexts_for_user_with_log_rows_only()`, `provider_test::test_contexts_ignore_failed_verifications_of_other_people()`, `provider_test::test_contexts_ignore_users_who_only_acted()`, `provider_test::test_settings_changed_by_a_user_are_not_that_users_data()`, `provider_test::test_users_in_context()`, `provider_test::test_users_in_context_lists_a_user_with_a_failed_verification()`.

## What an export holds

`provider::export_user_data()` runs for the user's own context only. It makes one `export_data()` call, under the subcontext with the plugin's name, with one key per table:

| Key | Rows |
|---|---|
| Each table of `provider::TABLES` | The raw rows of that `userid`, by `id`. That includes the whole `snapshot` of an issue, and the `data` and `excerpt` of a draft |
| `local_academictranscript_change` | The rows of the student's posted grades, from `provider::posted_ids()` |
| `local_academictranscript_log` | The rows of `provider::log_select()`, with `ip` blanked on every row whose `userid` isn't this user |

The IP address belongs to whoever did the thing, so a student gets an IP address only for what the student did. The ids of the other users stay, as they do in core's own exports.

Timestamps and ids are exported as stored. Stored files aren't exported.

Tests: `provider_test::test_export_includes_change_requests()`, `provider_test::test_export_includes_the_rows_of_every_table()`, `provider_test::test_export_gives_the_ip_address_only_for_what_the_student_did()`, `provider_test::test_export_includes_the_users_own_failed_verifications_with_the_ip_address()`, `provider_test::test_export_for_a_user_with_only_a_failed_verification()`, `provider_test::test_export_for_the_uploader_of_drafts()`, `provider_test::test_export_ignores_contexts_of_other_users()`.

## What a deletion removes

`provider::delete_data_for_all_users_in_context()`, `provider::delete_data_for_user()` and `provider::delete_data_for_users()` all reach `provider::delete_user()`, for a user context only. In order:

1. The rows of `local_academictranscript_change` for the student's posted grades.
2. All rows of each table in `provider::TABLES`.
3. The rows of `local_academictranscript_log` from `provider::log_select()`.

Effects to know:

- Deleting the issue rows ends those verification codes. A copy already sent stops verifying.
- The deletion of the requests and the issues goes together, so no request points to a missing issue.

Tests: `provider_test::test_delete_data_for_user_removes_only_that_student()`, `provider_test::test_delete_data_for_user_keeps_what_the_user_only_acted_on()`, `provider_test::test_delete_data_for_user_removes_the_users_own_failed_verifications()`, `provider_test::test_delete_data_for_users_removes_the_students_own_failed_verifications()`, `provider_test::test_delete_data_for_user_ignores_contexts_of_other_users()`, `provider_test::test_delete_data_for_all_users_in_context()`, `provider_test::test_delete_data_for_users()`.

## What the provider leaves alone

- Uploaded documents in the file area `sourcedocs`. They stay after a draft is deleted, until the uninstall.
- A row of `local_academictranscript_change` on another student's grade that this user asked for or decided.
- Log entries about other students that name this user as the one who acted.
- Log entries about no student from a visitor who wasn't logged in. They hold an IP address and no user.
- Course custom field values.
- Moodle's own log, which holds the events `code_updated` and `official_issued`. The core log store has its own provider.
- Whatever the student has in Student Records. That plugin exports and deletes its own data.

The privacy provider is the only code in the plugin that deletes a row. See [the data model](data-model.md#rules-that-hold-for-every-table).

## When you add a table or a column

1. Add the column to `provider::get_metadata()` with a string, or list it in `NOT_PERSONAL` in `tests/privacy/provider_test.php`, with a reason to do so. `provider_test::test_every_table_and_column_is_declared_or_holds_no_personal_data()` fails until you do.
2. A new table with a `userid` owner goes in `provider::TABLES`, so that it is found, exported and deleted. Add it to `data_table_provider()` and to the helper `store_row()` in `tests/privacy/provider_test.php`, so that `provider_test::test_contexts_for_a_row_in_any_data_table()` covers it.
3. A table with another owner needs its own lines in `provider::export_user_data()` and `provider::delete_user()`, as `local_academictranscript_change` has.
4. Add the strings to the language file. `provider_test::test_every_metadata_string_exists()` checks them.

## Related pages

- [Code map](README.md)
- [Data model](data-model.md)
- [Access](access.md)
- [Integration](integration.md)
