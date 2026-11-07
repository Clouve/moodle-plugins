# Developer docs

These pages are the technical reference of the three plugins in this repo, for developers and AI coding agents who maintain, change and extend them. They describe the code as released: Academic Transcript 1.4.3, Student Records 1.4.6 and the Student Records theme 1.0.3.

## Contents

- [The fastest way into the code](#the-fastest-way-into-the-code)
- [Where to look for a task](#where-to-look-for-a-task)
- [The whole repo](#the-whole-repo)
- [Academic Transcript](#academic-transcript)
- [Student Records](#student-records)
- [Student Records theme](#student-records-theme)
- [Related pages](#related-pages)

## The fastest way into the code

1. Read [Architecture](architecture.md). It says what each plugin owns and how each stays optional.
2. Read [Pitfalls](pitfalls.md). It lists what breaks silently.
3. Open the code map of the plugin you're changing: [Academic Transcript](academic-transcript/README.md), [Student Records](student-records/README.md) or the [theme](theme/README.md).
4. Find the page. Every top-level PHP file of a local plugin, apart from `lib.php`, `settings.php` and `version.php`, is a page that a browser opens, so the address `/local/studentrecords/terms.php` is the file `local/studentrecords/terms.php`.
5. Follow it into `classes/local/`. Pages check who may act, read the form and print. The rules live in the classes, mostly as static methods. Many classes keep the rules that need no database at the top, under the comment `// Rules that need no database.`, so a test can reach them with no site.
6. Find the test. A class in `classes/local/<name>.php` usually has its tests in `tests/local/<name>_test.php`. A test named after a rule says why the rule is there. [Testing](testing.md) says how to run one.

The folders follow Moodle's own layout, so a plugin has the same path here as in a Moodle code folder. Moodle 5.1 and later add `public/` in front of it.

## Where to look for a task

| You want to | Start at |
|---|---|
| Get a place to run the code | [Setup](setup.md) |
| Run the tests and the checks | [Testing](testing.md) |
| Find out why something didn't happen | [Debugging](debugging.md) |
| Write code that fits | [Conventions](conventions.md) |
| Change how a GPA, a letter or hours are worked out | [Academic Transcript calculations](academic-transcript/calculations.md), [Student Records calculations](student-records/calculations.md), and open an issue first |
| Change a table | [Academic Transcript data model](academic-transcript/data-model.md), [Student Records data model](student-records/data-model.md), [Release](release.md#a-database-change) |
| Add a capability or change who may do what | [Academic Transcript access](academic-transcript/access.md), [Student Records access](student-records/access.md) |
| Touch money | [Money flows](student-records/flows-money.md), and read the locks in [Pitfalls](pitfalls.md#locks-that-must-outlast-their-commit) first |
| Touch how the plugins talk to each other | [Architecture](architecture.md#who-calls-whom) |
| Support a new Moodle version | [Compatibility](compatibility.md#add-support-for-a-new-moodle-version) |
| Publish a release | [Release](release.md) |
| Ask why something is the way it is | [Decisions](decisions.md) |
| Work as an AI coding agent | [AI agents](ai-agents.md) |

## The whole repo

- [Architecture](architecture.md): how the three plugins relate, what each owns, which calls which, and how each stays optional.
- [Setup](setup.md): the test site, `bin/check`, an IDE and a local Moodle.
- [Testing](testing.md): PHPUnit, generators, Behat, `bin/check`, the CI matrix, the repo's own tests and `bin/check-docs`.
- [Debugging](debugging.md): a page, a task, cron, a message, a payment, a PDF, a theme, and the logs of each plugin.
- [Conventions](conventions.md): coding rules, stated and unstated, with examples from the repo.
- [Compatibility](compatibility.md): what differs across Moodle 4.5 to 5.3 and PHP 8.1 to 8.4, and how to add a new version.
- [Release](release.md): version rules, tags, ZIPs and workflows.
- [Decisions](decisions.md): why things are the way they are.
- [Pitfalls](pitfalls.md): the traps, and where to take special care.
- [AI agents](ai-agents.md): how an AI coding agent should work in this repo.

## Academic Transcript

`local/academictranscript` (`local_academictranscript`).

- [Code map](academic-transcript/README.md)
- [Execution flows](academic-transcript/execution-flows.md)
- [Data model](academic-transcript/data-model.md)
- [Access](academic-transcript/access.md): capabilities, and the rules that aren't capabilities
- [Settings](academic-transcript/settings.md)
- [Calculations](academic-transcript/calculations.md): GPA, quality points, rounding, repeats, hours, transfer
- [PDF and signing](academic-transcript/pdf-and-signing.md): PDF building, snapshots, versions, signing, QR codes, fonts
- [Integration](academic-transcript/integration.md): the Student Records source, Moodle APIs, AI, events
- [Privacy](academic-transcript/privacy.md)
- [Extending](academic-transcript/extending.md)

## Student Records

`local/studentrecords` (`local_studentrecords`).

- [Code map](student-records/README.md)
- [Data model](student-records/data-model.md)
- [Access](student-records/access.md)
- [Settings](student-records/settings.md)
- [Tasks and messages](student-records/tasks-and-messages.md)
- [Flows: admissions and records](student-records/flows-admissions-and-records.md)
- [Flows: registration and grades](student-records/flows-registration-and-grades.md)
- [Flows: money](student-records/flows-money.md): charges, payments, plans, refunds, recovery
- [Flows: aid](student-records/flows-aid.md)
- [Calculations](student-records/calculations.md)
- [Integration](student-records/integration.md)
- [Privacy](student-records/privacy.md)
- [Extending](student-records/extending.md)

## Student Records theme

`theme/studentrecords` (`theme_studentrecords`).

- [Code map](theme/README.md): layouts, templates, classes, callbacks, what each takes from Boost
- [Settings and SCSS](theme/settings-and-scss.md)
- [Integration](theme/integration.md): the menu and the cards, and what Boost gets instead
- [Extending](theme/extending.md)

## Related pages

- [Docs home](../README.md)
- [User docs](../user/README.md)
- [Maintaining the docs](../MAINTAINING.md)
- [The test site and its tools](../tools/README.md)
