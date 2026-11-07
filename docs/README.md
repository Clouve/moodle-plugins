# Documentation

This folder holds the user manual and the technical reference of the three plugins in this repo. Each set stands on its own, so you can read the one for your job and leave the other alone.

| You are | Start here |
|---|---|
| An administrator, registrar, admissions, finance or financial aid officer, teacher or student | [User manual](user/README.md) |
| Someone who received a transcript and wants to check it | [The verify page](user/academic-transcript/verify.md) |
| A developer who maintains or extends the plugins | [Developer docs](developer/README.md) |
| An AI coding agent working in this repo | [AI agents](developer/ai-agents.md), which `AGENTS.md` points to |
| Someone who changes the code or these docs | [Maintaining the docs](MAINTAINING.md) |

## Versions covered

| Plugin | Component | Release | Moodle | PHP |
|---|---|---|---|---|
| [Academic Transcript](user/academic-transcript/README.md) | `local_academictranscript` | 1.4.3 | 4.5 to 5.3 | 8.1 to 8.4 |
| [Student Records](user/student-records/README.md) | `local_studentrecords` | 1.4.6 | 4.5 to 5.3 | 8.1 to 8.4 |
| [Student Records theme](user/theme/README.md) | `theme_studentrecords` | 1.0.3 | 4.5 to 5.3 | 8.1 to 8.4 |

The docs describe these releases. When a page and the code disagree, the code is right, and the page needs a fix. [Maintaining the docs](MAINTAINING.md) says how.

## The screenshots

Every screenshot is a real capture of these plugins on Moodle 5.3+ (Build: 20261030), with PHP 8.3 and PostgreSQL 17, in Chrome. Riverside College and everyone at it are made up, and so are their addresses, numbers and keys. A page on Moodle 4.5 can look a little different, but the words on it are the same.

The site, the made-up school and the scripts that took the pictures are in [tools](tools/README.md), so anyone can retake them.

## What's here

- [user/](user/README.md): the user manual, by plugin, then by role and by area.
- [developer/](developer/README.md): the code maps, flows, data models, conventions, testing, decisions and pitfalls.
- [tools/](tools/README.md): the test site, the seed and the capture scripts.
- [MAINTAINING.md](MAINTAINING.md): what to update when something changes.

The root [README](../README.md) says how to install a plugin. To report a bug, use the [issue tracker](https://github.com/Clouve/moodle-plugins/issues). For a security problem, follow [SECURITY.md](../SECURITY.md).
