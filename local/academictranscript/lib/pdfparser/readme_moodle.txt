Description of smalot/pdfparser library import into Academic Transcript

smalot/pdfparser reads text from PDF files. The setup wizard uses it to read uploaded
grading policies and course catalogs when Moodle has no path to Ghostscript.

Library
-------
Upstream:   https://github.com/smalot/pdfparser
Version:    2.13.1
License:    LGPL-3.0 (LICENSE.txt in this folder)
Copyright:  Sebastien MALOT
Used by:    classes/local/import/document_text.php

What is here
------------
- src/ is the src/ folder of the upstream release, copied as it is.
- LICENSE.txt is the license file of the upstream release.

Nothing else from upstream is copied (no composer.json, tests or docs), and no file is
changed. src/ and LICENSE.txt are identical to the files of the upstream release 2.13.1.

How it is loaded
----------------
There is no Composer step and no vendor folder. document_text::load_pdfparser() registers an
autoloader for the Smalot\PdfParser namespace. The autoloader loads classes from src/ when
they are needed.

The composer.json of upstream asks for the PHP extensions zlib and iconv, and for the Symfony
mbstring polyfill. Moodle requires the zlib, iconv and mbstring extensions itself, so the
polyfill is not bundled.

Instructions
------------
1. Find the release you want at https://github.com/smalot/pdfparser/releases and download its
   source.
2. Remove src/ and LICENSE.txt from this folder. Keep readme_moodle.txt.
3. Copy src/ and LICENSE.txt from the release into this folder. Copy nothing else.
4. Check that the new code needs nothing outside the Smalot namespace. This command, run in
   this folder, must print nothing:

       grep -rhn '^use ' src | grep -v Smalot

   Also read the "require" part of the new composer.json. Anything beyond the PHP extensions
   that Moodle requires (zlib, iconv, mbstring) needs a plan before you go on.
5. Update the version in ../../thirdpartylibs.xml and in this file.
6. Run the tests, from the root of the repo:

       bin/check --plugin local/academictranscript phpunit

   The tests of document_text and of the PDF builder read PDF files with this library.
