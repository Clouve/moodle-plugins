# Agent guide

This repo holds three Moodle plugins: `local/academictranscript`, `local/studentrecords` and `theme/studentrecords`. Each is a separate plugin with its own version and release, and each works without the others. They run on Moodle 4.5 to 5.3 and PHP 8.1 to 8.4.

Start with [AI agents](docs/developer/ai-agents.md). The technical reference is in [docs/developer](docs/developer/README.md).

## Read before you change code

- [Architecture](docs/developer/architecture.md): what each plugin owns, and which calls which.
- [Pitfalls](docs/developer/pitfalls.md): what breaks without an error.
- [Decisions](docs/developer/decisions.md): why odd-looking code is that way.
- [Conventions](docs/developer/conventions.md): how the code is written.

## Never

- Replace an exact rounding helper with `round()`.
- Commit a transaction inside its `try` block, or let a lock end before the commit.
- Send mail in a transaction or under a lock.
- Lower `$plugin->version`, or rename a component, page, table, capability or setting.
- Call a sibling plugin with no check in front of it.
- Run `phpcbf` on the local plugins, or remove the GPL header or Boost's authors from the theme.
- Change how grades, GPA or repeats are worked out without an issue.

## Check your work

```bash
bin/check-docs                                                # the docs against the code
tests/run.sh                                                  # the scripts in bin/
bin/check --plugin local/academictranscript                   # the CI steps, in Docker
bin/check --plugin local/academictranscript --moodle 4.5 --php 8.1 --db mysqli
bin/check --plugin local/academictranscript --together        # with the other plugins
docs/tools/site.sh phpunit --filter test_gpa_rounds_a_half_up # one test on the test site, after docs/tools/site.sh sync
```

Say what you ran and what you didn't. [Testing](docs/developer/testing.md) has the details, and [AI agents](docs/developer/ai-agents.md#what-a-change-must-update) lists what a change must update.
