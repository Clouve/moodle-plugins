# Debugging

This page says what to look at when something goes wrong: a page, a task, cron, a message that didn't go, a payment, a PDF or the theme. It starts with the logs of each plugin, because most questions end there.

## Contents

- [The logs](#the-logs)
- [Turn on debugging](#turn-on-debugging)
- [A page](#a-page)
- [Who may do what](#who-may-do-what)
- [A task](#a-task)
- [Cron](#cron)
- [A message that didn't go](#a-message-that-didnt-go)
- [A payment](#a-payment)
- [A PDF](#a-pdf)
- [A number that looks wrong](#a-number-that-looks-wrong)
- [The theme](#the-theme)
- [Look at the data](#look-at-the-data)
- [Related pages](#related-pages)

## The logs

| Log | Table | Written by | Read at | What it holds |
|---|---|---|---|---|
| Academic Transcript activity log | `local_academictranscript_log` | `logger::log()` | `local/academictranscript/log.php` | Views, downloads, issues, verifications, postings, changes and requests. `logger::ACTIONS` lists the actions, and `after_saving` is a failure after the data was saved |
| Student Records audit log | `local_studentrecords_log` | `audit::log()` | `local/studentrecords/log.php`, with `local/studentrecords:viewlog` | Actions: `audit::ACTIONS`. `info` is cut to 255 characters. Failures after saving are `update` of `student`, with `info` starting `after saving: ` |
| Student Records change history | `local_studentrecords_history` | `records::save()`, `records::history()`, `records::set_setting()` | The History tab of `local/studentrecords/student.php` shows the main student record only | For each changed field: who, when, old value, new value. Other tables keep history here that no page shows |
| Student Records message log | `local_studentrecords_msglog` | `messages::deliver()`, `messages::send_queued()` | The Log tab of `local/studentrecords/messages.php` | One row per message, sent or not. The subject is there, the body isn't |
| Moodle's task logs | Moodle's | Moodle | Moodle's task pages | What a task printed with `mtrace()` |

Both plugin logs store the IP address and the user. `logger::log()` writes `0` as the user for someone not logged in, and `local/academictranscript/log.php` shows `notloggedin` for it.

A person's action often has a line in the audit log and a line in the history. Look in the audit log for what happened and in the history for what changed.

## Turn on debugging

`debugging()` messages show only at the right level. `records::call()` in the theme, `signer::credentials()` and `messages::send_now()` report their failures at `DEBUG_DEVELOPER`, so on a normal site you see nothing.

On a local Moodle set these in `config.php`:

```php
$CFG->debug = (E_ALL);
$CFG->debugdisplay = 1;
```

The test site turns debugging off in `/work/site-config.php`. In a script on the site, call `set_debugging(DEBUG_DEVELOPER, true);` after `config.php`. A debugging message also fails a PHPUnit test, unless the test expects it.

## A page

1. Read the error. An error page names a string. An `err_*` string of Student Records or a string of Academic Transcript comes from a class that refused, so search for the string key.
2. Find the check. A page opens with its login and capability checks. `staffarea::setup()` and `staffarea::can_open()` hold the checks of the staff pages of Student Records, and `admin_externalpage_setup()` and `access::require_setup()` of the admin pages of Academic Transcript.
3. Check the sesskey. A wrong sesskey is an error on some pages and silent on others.
4. Look for a page that "does nothing". A page that saves through a form goes back to itself with `redirect()`. If nothing changed, read the form's `validation()` and the class it calls.

`err_busy` of Student Records and `req_err_busy` of Academic Transcript mean a lock wasn't free within `registration::LOCKWAIT` or `requests::LOCKWAIT`, 10 seconds. Someone else was working on the record, or a slow request held the lock.

With `debugdisplay` on, any debugging output turns a redirect into an interstitial page. So a form that redirects can stop on a page that says "continue" on your site, and not on anyone else's. Fix the debugging message.

A page written as a test is often the fastest way in. See [page tests](testing.md#page-tests).

## Who may do what

Both plugins have rules that aren't capabilities, and these refuse people who hold the capability:

- Nobody acts on their own record. `access::is_own()` in Student Records, and `posting::decide_problem()` and the checks in `transcript::issue()` in Academic Transcript. The errors are `err_ownrecord`, `err_gradeown`, `err_gradownapp`, `err_refundself`, `issueown` and `ownposting`.
- `local/studentrecords/offerings.php` opens with `local/studentrecords:configure`, not `local/studentrecords:managestudents`. Rosters on it need `access::sees_all_students()`.
- `access::require_setup()` redirects holders of `local/academictranscript:configure` to the wizard until the setup is finished. Others use the pages as they are.
- "Transcript settings" has no capability of its own, so it needs `moodle/site:config`.

[Academic Transcript access](academic-transcript/access.md) and [Student Records access](student-records/access.md) have the whole list.

## A task

Student Records has five tasks. Academic Transcript has none.

| Task | Runs | Prints | On an error |
|---|---|---|---|
| `billing_daily` | Daily, 06:15 | Counts as JSON | `plans::daily()` stops where it is. Each step and its mark are saved together, so the next run doesn't do a step twice |
| `waitlist_daily` | Daily, 06:30 | Lapsed offers | `waitlist::daily()` goes on with the other sections and throws the first error at the end |
| `recover_payments` | Every 5 minutes | Payments recovered | `payments::recover()` stops at `err_busy`, and the next run tries again. Any other error prints and it goes on |
| `erase_removed_accounts` | Hourly, at a random minute | Requests carried out | See [Student Records privacy](student-records/privacy.md) |
| `send_message` | Ad hoc, one per queued message | Nothing | `messages::send_queued()` writes the result in the message log. The task ends and doesn't retry, so a person isn't told the same thing twice |

Run one by hand, from the Moodle folder of the site:

```bash
php admin/cli/scheduled_task.php --execute='\local_studentrecords\task\billing_daily' --showdebugging
php admin/cli/scheduled_task.php --list
php admin/cli/adhoc_task.php --execute
```

On the test site, `docs/tools/site.sh sh '<command>'` runs the same commands, and `docs/tools/site.sh adhoc` runs the waiting ad-hoc tasks.

`$USER` in a task is the cron user. `academic::place_hold()` stores it as `placedby` when `plans::daily()` calls it. `plans::daily()` does nothing while billing is off.

## Cron

If nothing happens on its own, check cron first:

- Most messages of Student Records are written under a lock or in a transaction, so they wait for cron. A site with no cron sends almost nothing, and the message log shows "Waiting to send" (`msgstatus_queued`).
- A lost online payment waits for `recover_payments`.
- Late fees, reminders and financial holds wait for `billing_daily`.
- Seat offers lapse in `waitlist_daily`.

Run it once with `php admin/cli/cron.php --keep-alive=0`, or `docs/tools/site.sh cron` on the test site.

## A message that didn't go

Read the message log, in the Log tab of `local/studentrecords/messages.php`. `messages::recent()` gives the same rows, and `messages::last_status()` the newest status of a kind for a student.

| Status | String | What happened | What to do |
|---|---|---|---|
| `queued` | `msgstatus_queued` | Written during a transaction or under a lock, waiting for cron | Run the ad-hoc tasks |
| `sent` | `msgstatus_sent` | Moodle took it | Check the person's message settings, and Moodle's own mail settings |
| `failed` | `msgstatus_failed` | `message_send()` or the send threw. `debugging()` has the reason | Turn on debugging |
| `off` | `msgstatus_off` | `msgon_<key>` is `0` | Turn it on in `local/studentrecords/messages.php` |
| `nouser` | `msgstatus_nouser` | No account, or a suspended or deleted one, or no staff with the capability | Check the account |
| `testmode` | `msgstatus_testmode` | Test mode, and the address doesn't end in `@example.com` | Turn test mode off |
| `testinbox` | `msgstatus_testinbox` | Test mode with a test inbox: it went there | Turn test mode off |
| `test` | `msgstatus_test` | "Send me a test" | Nothing |

Things that confuse people:

- `messages::student()` returning false doesn't mean failed. A queued message returns false too. Use `messages::last_status()`.
- The body of a queued message is fixed when it's queued, and it travels in the task's custom data. The switch, test mode and the account are read again when it goes.
- "Send me a test" uses plain email, so it can work while a normal message doesn't. It ignores the switch and the queue.
- Academic Transcript sends no email.
- Moodle's own messages aren't in this log. A course's welcome message goes at the commit, and a failure there shows as `after saving: ` in the audit log.

## A payment

The tables, in the order money moves:

| Table | What it holds | Written by |
|---|---|---|
| `local_studentrecords_payreq` | The request: amount, account, status `open` then `paid` | `payments::create_request()`, `payments::record_online()` |
| `{payments}` | Moodle's row for the money the gateway took | Moodle |
| `local_studentrecords_txn` | The account line, with `paymentid` | `payments::record_online()` |

Follow one payment:

1. Is there a row in `{payments}` with `component` `local_studentrecords`? If not, the money never arrived, and the problem is with the gateway.
2. Is there a line in `local_studentrecords_txn` with that `paymentid` and `reverses` NULL? A reversal line copies `paymentid`, so `paymentid` alone doesn't prove the payment was recorded.
3. If there's no line, read the audit log. `online payment <id> does not fit request <id>: not recorded` means `payments::payment_fits()` said no, and neither delivery nor recovery will add it. `online payment <id> recovered: its delivery did not finish` means `recover_payments` found it.
4. Check `recoverfrom`. `payments::recover()` looks only at payments from that time on, so a site's history is never touched. A site with no start time gets one on the first run, and that run records nothing.

Moodle delivers a payment once and doesn't try again, through `service_provider::deliver_order()`. A delivery that stopped, for example because the accounts were busy, is lost until `recover_payments` finds it, so that payment can take up to 5 minutes to appear.

Course access after a payment:

- `payments::settle()` runs `plans::sync()` and `payments::release()` after the line is saved, and a failure there goes in the audit log as `course access after payment: `, so the payment stands. The course stays on the account page for staff.
- Under the `accounts` lock, `payments::release()` returns 0 and does the work when the lock ends. That 0 doesn't mean nothing is waiting.
- The whole balance decides access, not the term's: `payments::access_ok()`.

A student can hold many open requests, and nothing closes them. `payments::open_request()` throws `err_paidalready` for a request above today's balance too.

## A PDF

Academic Transcript:

- `transcript::build()` makes the data, and `builder::render()` draws it. `$data['problems']` holds what stops an Official transcript, so read it first if issuing fails.
- An issued copy is drawn from `local_academictranscript_issue.snapshot`. `transcript::stored()` brings an older layout up to date, or returns null, and `local/academictranscript/issued.php` then says it can't be drawn again. Its verification code still works.
- Frozen means the data. An old transcript drawn again has today's name and logo, and a new signature.
- A wrong certificate password gives no message on screen. `signer::credentials()` falls back to the self-signed certificate and says so only through `debugging()`. The self-signed files are in `$CFG->dataroot/local_academictranscript/signing`, and they last 3650 days.
- With no `openssl_pkcs12_read()` the PDF isn't signed.
- `document::add_fonts()` reads the fonts from `local/academictranscript/fonts/`.

[PDF and signing](academic-transcript/pdf-and-signing.md) has the details.

Student Records has three PDFs, made with Moodle's `\pdf`: `billing::statement_pdf()`, `payments::receipt_pdf()` and `letters::pdf()`. A letter prints from `local_studentrecords_letter.content`, so a reprint shows what was released.

## A number that looks wrong

1. Find which side printed it. Student Records works out the record in `registration::history()` and `registration::totals()`. Academic Transcript works out the transcript in `transcript::build()` and `calculator::compute()`.
2. Check the source. With `source` on `studentrecords`, a student with a file is counted on the scale of Student Records, and a student without one on the scale of Academic Transcript.
3. Check the repeat rule. `catalog::repeat_rule()` is `all` without Academic Transcript, and that plugin's setting with it. A row with `repeatexcluded` counts as attempted, not as earned.
4. Check the printing. Admin lists print one decimal and transcripts print up to two, through `scale::number()`.
5. For a letter, remember `gradepct` is rounded to four decimals when it's stored, and the letter was chosen from the exact percent before that. Don't work out the letter again from the stored value.

Transcripts are worked out on each view from the active scale. Only issued snapshots stay as they were. [Pitfalls](pitfalls.md#exact-rounding-on-every-php-version) has more.

## The theme

| Symptom | Look at |
|---|---|
| No card, no menu, no note | `records::call()` hides every failure. Turn on `DEBUG_DEVELOPER`. Then check the rules in [the theme's integration page](theme/integration.md) |
| No menu on a plugin page | The page is inside a course, or on a layout with no drawer. The course index wins, and `shell::inline_menu()` prints nothing under this theme |
| The old look after a change | The CSS is compiled. Purge the caches with `php admin/cli/purge_caches.php`. For templates, `$CFG->cachetemplates = false` |
| The theme's styles are all gone | The SCSS failed to compile, and Moodle fell back to Boost's CSS. The error goes to `debugging()` at `DEBUG_DEVELOPER`. `theme/studentrecords/tests/scss_test.php` shows it |
| The colour didn't change | A saved colour that fails `brand::accent()` falls back to the default with no message |
| The logo doesn't show | `brand::logo_url()` asks `$PAGE->theme`, then the compact logo, then the initials |
| A label like `[[key]]` | A string a Moodle version lacks. `theme/studentrecords/tests/pages_test.php` looks for that |

## Look at the data

On the test site, write a small PHP file and run it with `docs/tools/site.sh php`. See [Run PHP on the site](setup.md#run-php-on-the-site).

Useful starting points:

| Question | Call |
|---|---|
| What is on this student's record? | `students::get_by_user()`, then `registration::history()` |
| What would this transcript print? | `transcript::build()` |
| What is the balance? | `billing::balance()`, and `billing::lines()` for the lines |
| Why can't this student register? | `registration::add_problems()` |
| Which rule passes this grade? | `registration::result_passes()` |
| What does this message log say? | `messages::recent()` |

## Related pages

- [Setup](setup.md)
- [Testing](testing.md)
- [Pitfalls](pitfalls.md)
- [Tasks and messages](student-records/tasks-and-messages.md)
- [Money flows](student-records/flows-money.md)
- [PDF and signing](academic-transcript/pdf-and-signing.md)
