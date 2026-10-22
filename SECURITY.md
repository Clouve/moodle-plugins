# Security policy

## Report a vulnerability

Report security problems in private. Don't open a public issue or pull request for them.

1. Go to the [Security tab](https://github.com/Clouve/moodle-plugins/security) of this repo and select "Report a vulnerability". The direct link is https://github.com/Clouve/moodle-plugins/security/advisories/new.
2. Say which plugin and release you tested, and give your Moodle version, PHP version and database.
3. Describe the problem, the steps to reproduce it, and what an attacker could do with it.

Only you and the maintainers can see the report. The maintainers reply on the report, agree on a fix with you there, and publish a GitHub security advisory when the fix is released.

## What is covered

- The plugins in this repo.
- The scripts in `bin/` and the workflows in `.github/workflows/`.

A problem in Moodle itself belongs to the Moodle project. A problem in code that a plugin bundles belongs to that code's upstream: smalot/pdfparser, Source Sans 3 and Source Serif 4. If it affects a plugin in this repo, tell the maintainers as well.

## Supported versions

Security fixes go into the latest release of each plugin.

## Working as designed

- The verify page (`/local/academictranscript/verify.php`) is public on purpose. Anyone who holds a verification code can see the Official transcript issued under it, without the date of birth.
- When no signing certificate is uploaded, Official transcripts are signed with a self-signed certificate. PDF readers show its signer as not trusted, and any change to the file still breaks the signature. To change that, upload a document-signing certificate in the plugin settings.
