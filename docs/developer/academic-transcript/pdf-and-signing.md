# PDF and signing

This page covers how a transcript becomes a PDF: the classes, the page layout, what is frozen and what is drawn fresh, the watermark, the fonts, the QR code, the digital signature and its certificates, and the versions of stored transcripts. The code is in `local/academictranscript/classes/local/pdf/`.

## Contents

- [The three classes](#the-three-classes)
- [What makes an Official copy different](#what-makes-an-official-copy-different)
- [How render builds a PDF](#how-render-builds-a-pdf)
- [Page geometry](#page-geometry)
- [The blocks of a record page](#the-blocks-of-a-record-page)
- [The last record page](#the-last-record-page)
- [The Transcript Key page](#the-transcript-key-page)
- [The watermark and the border](#the-watermark-and-the-border)
- [Fonts](#fonts)
- [The QR code](#the-qr-code)
- [What is frozen and what stays live](#what-is-frozen-and-what-stays-live)
- [Stored transcripts and their versions](#stored-transcripts-and-their-versions)
- [Signing](#signing)
- [Certificates](#certificates)
- [TCPDF traps](#tcpdf-traps)
- [Related pages](#related-pages)

## The three classes

| Class | File | Job |
|---|---|---|
| `builder` | `local/academictranscript/classes/local/pdf/builder.php` | Lays out the pages. `builder::render()` and `builder::filename()` are public, and everything else is protected |
| `document` | `local/academictranscript/classes/local/pdf/document.php` | Extends Moodle's `pdf` class, which wraps TCPDF. Registers the fonts, and draws the border and the watermark |
| `signer` | `local/academictranscript/classes/local/pdf/signer.php` | Finds or makes the certificate, and calls `setSignature()` |

There is no HTML template. `builder` writes small HTML strings and hands them to TCPDF's `writeHTMLCell()`, and it draws the signature block with TCPDF's own calls.

Two pages call it:

| Page | Data | `$issue` |
|---|---|---|
| `local/academictranscript/index.php`, action `unofficial` | `transcript::build()` | null |
| `local/academictranscript/issued.php`, with `id` | `transcript::stored()` | the row of `local_academictranscript_issue` |

Both send the result with `send_file()`, as a forced download with the type `application/pdf`. Nothing stores a PDF. `builder::filename()` makes `transcript-<name>-official-<Ymd>.pdf` or `transcript-<name>-unofficial-<Ymd>.pdf`. The name has its spaces as hyphens and goes through `clean_filename()`. The date is the day of the download, in the server's time zone, so an old Official copy downloaded today has today's date in its file name.

## What makes an Official copy different

`$official` in `builder::render()` is `!empty($issue)`, and nothing else decides it.

| | Official | Unofficial |
|---|---|---|
| `$issue` | the row | null |
| Data | the frozen snapshot | built on the spot |
| Title | `doctitle_official` | `doctitle_unofficial` |
| Watermark | no | the string `watermark`, on every page |
| QR code | yes | no |
| Digital signature | yes, when `signer::apply()` can | never |
| Note in the signature block | `digitallysigned` or `notsigned` | `unofficialnote` |
| Details at the right | date issued, last entry, number, recipient, verify address, code | date printed, last entry, copy type, `notverifiable` |
| Stopped by problems or holds | yes, at issue | no |

Tests: `builder_test::test_render_official_pdf_is_signed()`, `builder_test::test_only_official_pdf_has_the_qr_code()`.

## How render builds a PDF

`builder::render()` runs these steps:

1. `builder::setup_page()` reads the page size and the accent colour into static properties.
2. `builder::new_document()` makes a `document` with the fonts, no automatic page breaks, cell height ratio 1.15, and `clouvesans` at 9 pt.
3. It sets the title, author and creator of the PDF. The author is the school name, and the creator is the plugin name.
4. On an Unofficial copy it sets `$doc->watermark`. On an Official copy it calls `signer::apply()`, before any content.
5. It turns transfer groups and terms into block specs, in that order. With neither, one block says `nocourses`.
6. `builder::split_block()` splits a block taller than 150 mm, and `builder::measure()` measures each piece.
7. A Degree box joins the blocks when `qualification` isn't blank.
8. It measures the column header, the footer and the summary, and sets `$limit`, the lowest point a block may reach.
9. It places the blocks, page by page.
10. It draws the summary and the signature block on the last record page, or on a new page when the columns reach too low.
11. `builder::key_page()` adds the key.
12. `$doc->Output('', 'S')` returns the PDF as a string.

`builder` keeps the page width, the bottom edge, the accent, the unit heading and a scratch document in static properties, and each `builder::render()` sets them again. So the protected drawing methods only make sense inside a render.

## Page geometry

All measures are in millimetres, on a portrait page.

| Measure | Letter | A4 |
|---|---|---|
| Left and top margin, `builder::X0` and `builder::TOP` | 12.5 | 12.5 |
| Content width | 190.9 | 185 |
| Bottom of content | 266.9 | 284.5 |
| Gap between the columns, `builder::GAP` | 6 | 6 |
| Height of the signature block, `builder::SIGH` | 31 | 31 |

The setting `pagesize` decides: `builder::page_format()` returns `A4` when the value is `A4`, and `LETTER` for anything else.

The setting `accentcolor` is read by `builder::setup_page()`. A value that isn't six hex digits, with or without `#`, falls back to `1F3A5F`.

## The blocks of a record page

Each record page has this order. The student box prints on the first page only, and the continued line on the others.

| Part | Method | Holds |
|---|---|---|
| Header | `builder::record_header()` | Logo at 19 x 19 mm when one is uploaded, the school name, a line with the address, the registrar's office, phone, email and website, the document title at the right, the page number, a rule |
| Student box | `builder::student_box()` | Name, Student ID, date of birth, admit term, program, status, and the unit label under Calendar. An empty value prints `-` |
| Continued line | `builder::continued_line()` | `continuedrecord`, or `continuedrecordnoid` for a student with no Student ID |
| Column head | `builder::colheader_html()` | Course, Title, the unit short name, Grd, QP, at 17, 47, 11, 11 and 14 percent of the column |
| Blocks | `builder::block_html()` | A title and date range, the rows, notes in italics under a row, a totals line |
| Degree box | `builder::degree_html()` | `degreeawarded`, the qualification, and `conferred` when there is an award date |
| Footer | `builder::footer_html()` | The setting `footer`, or `footer_default` when it is empty |

Blocks go down the left column, then the right. A block never splits across a page, which is why `builder::split_block()` cuts a tall one first. It halves the rows again and again until each piece fits 150 mm. A piece other than the first is titled `continued`, and only the last piece keeps the totals. Each halving adds the word again, so a block cut into more than two pieces can print titles such as "Fall 2025 (continued) (continued)". A block with one row stays whole.

When all that is left fits on the current page, `$target` balances the two columns. Otherwise the left column fills to `$limit`. When neither column can take the next block, a new page starts with the header and the continued line.

## The last record page

The summary and the signature block sit at the bottom of the last record page. When the columns reach too low, `builder::render()` adds a page for them, with the header and the continued line.

`builder::summary_html()` prints `creditsummary` or `clocksummary`, by `unittype`, with the rows Institutional, Transfer and Overall Total. The Transfer row always prints `-` for the GPA.

`builder::signature_block()` draws, in a grey box:

- Seal: in `images` mode with a file, the seal image at 24 x 24 mm. Otherwise a dashed circle with `sig_seal`.
- QR code: on an Official copy, next to the signature.
- Signature: in `images` mode, the signature image when there is one, a line, then the registrar's name and title, or `sig_official` when both are empty. In `lines` mode, a line, `sig_official` with the registrar's name in brackets, the title, then two short lines labelled `sig_title` and `sig_date`.
- Note: in italics, as the table above says.
- Details: at the right, as the table above says. An empty value prints `-`.

The setting `signaturemode` decides the mode. It is `images` only when the value is exactly that.

## The Transcript Key page

`builder::key_page()` runs after the record pages. Automatic page breaks are on while it draws, so a long key runs to another page, and off again for the contact box.

1. The head: `transcriptkey`, the school, `registraroffice`, and at the right the student and the page number. `keystudentline` holds the name and the ID, and a student without an ID prints the name alone.
2. `key_gradesused`: the rows of the key that aren't marks. The rows come from `$data['key']` when the data has them, as a snapshot does, else from `transcript::key_rows()`. Then the setting `key_scalenote`.
3. `key_marks`: the rows that are marks.
4. Six text sections in two columns: `key_gpa`, `key_units`, `key_coursenumbers`, `key_accreditation`, `key_real`, `key_privacy`. Each is the setting, or its `_default` string when the setting is empty, so emptying a section doesn't remove it.
5. The contact box at the foot of the page, from the setting `key_contact`, on a new page when it doesn't fit.

`{school}` and `{verifyurl}` are replaced in the sections and the contact box.

A section's title is `transcript::setting($key . '_title', ...)` with the language string as the default. Only `key_units_title` is on the settings page, so only that title can change. `key_contact_title` always comes from the language string.

Tests: `builder_test::test_the_key_comes_from_the_transcript()`, `builder_test::test_key_page_shows_student_id()`, `builder_test::test_key_page_shows_name_and_student_id_on_one_line()`, `builder_test::test_key_page_shows_name_alone_without_student_id()`.

## The watermark and the border

`document::Header()` runs at each `AddPage()`, so it draws under the content:

- Two rectangles in the accent colour, 5.3 mm and 6.1 mm from the edge of the page.
- When `$watermark` isn't empty: the text rotated 32 degrees about the middle of the page, in `clouvesans` bold at 46 pt, RGB 176, 32, 32, at opacity 0.14.

`document::Footer()` is empty on purpose, because the footer is part of the page content.

The watermark is on every page of an Unofficial copy, the key included.

## Fonts

`document::add_fonts()` registers three families from `local/academictranscript/fonts`. Every style is mapped to the nearest file, so inherited bold or italic never fails.

| Family | Regular | Bold | Italic | Bold italic |
|---|---|---|---|---|
| `clouvesans` | `clouvesans` | `clouvesansb` | `clouvesansi` | `clouvesansb` |
| `clouvesanssemib` | `clouvesanssemib` | `clouvesansb` | `clouvesansi` | `clouvesansb` |
| `clouveserif` | `clouveserif` | `clouveserifb` | `clouveserifi` | `clouveserifb` |

`clouvesans` is the body font. `clouvesanssemib` sets values, and `clouveserif` sets headings.

The seven fonts are renamed builds of Source Sans 3 and Source Serif 4, in TCPDF's format, under the SIL Open Font License, which reserves the name Source. Each is three files: `<name>.php`, `<name>.z` and `<name>.ctg.z`. `local/academictranscript/fonts/readme_moodle.txt` says how they were made and how to update them. Don't rename a family without changing the HTML styles in `builder`.

Tests: `document_test::test_font_folder_holds_only_the_renamed_fonts()`, `document_test::test_definition_matches_its_files()`, `document_test::test_name_table_has_the_new_names()`, `document_test::test_only_the_notices_have_the_reserved_word()`, `document_test::test_family_is_registered()`, `document_test::test_pdf_embeds_each_font_under_its_new_name()`, `builder_test::test_pages_are_drawn_with_the_renamed_fonts()`, `builder_test::test_html_styles_name_registered_font_families()`.

## The QR code

`builder::signature_block()` draws it on Official copies only, with TCPDF's `write2DBarcode()`. There is no other library.

```php
$doc->write2DBarcode($qrurl, 'QRCODE,M', $mx + $mw + 3, $y + 1, 22, 22, $qrstyle, 'N');
```

`$qrurl` is `local/academictranscript/verify.php` with the formatted code as `code`. The size is 22 x 22 mm at error correction level M, with the caption `qr_scan` under it. The address comes from `$CFG->wwwroot` at the moment of the download. It is printed on every Official copy, so never rename `local/academictranscript/verify.php`.

The signature gets narrower on an Official copy to make room: `$mw` loses 26 mm.

## What is frozen and what stays live

Frozen covers the data and not the look. A later change to the record never changes an issued copy, and a later change to the school's settings changes how it is drawn.

| From the snapshot | Live on each download |
|---|---|
| Name, Student ID, date of birth | School name, logo, address, phone, email, website |
| Program, admit term, status, unit label | Registrar's name and title |
| Terms, rows, notes, totals | Accent colour, page size, footer |
| Summary | Signature mode, signature and seal images |
| Transfer groups | Text sections of the key, `key_scalenote`, `key_contact` |
| Rows of the key (grades and marks) | Labels and other strings, in the viewer's language |
| Qualification, award date, last entry | Dates, in the viewer's time zone and language |
| Recipient, number and code, from the issue row | The verify address, and the digital signature made with whatever certificate is in place |

So an old transcript drawn after a rebrand has the new name and logo. `transcript::date()` and `transcript::shortdate()` call `userdate()` with the zone 99, which means the viewer's. The string `strftimetermdate` is `%m/%d/%y`, US order, unless a language pack changes it.

## Stored transcripts and their versions

`local/academictranscript/issued.php` passes the snapshot through `transcript::stored()`, which brings an older copy to today's layout or returns null. The table of layouts and the rule never to read the layout from the version number are on [the data model page](data-model.md#versions-of-a-snapshot).

`builder::render()` needs the keys of today's layout: `unitshort`, `unittype`, `unitlabel`, `transfer`, `terms`, `summary`, `qualification`, `fullname`, `studentid`, `dob`, `admitterm`, `program`, `status`, `lastentry`, `awarddate`. Rows need `hours`, and `transcript::stored()` fills it from `credits` on older copies.

`$data['key']` is optional. A snapshot without it draws today's key from `transcript::key_rows()`.

## Signing

`signer::apply()` runs before any content, for Official copies only:

```php
$doc->setSignature($cred['cert'], $cred['key'], '', $cred['extracerts'], 1, [
    'Name' => get_string('signername', 'local_academictranscript', self::school_name()),
    'Location' => transcript::setting('address'),
    'Reason' => get_string('signaturereason', 'local_academictranscript'),
    'ContactInfo' => transcript::setting('email'),
]);
```

The type 1 means no changes to the document are allowed, so any change breaks the signature. The password argument is empty, because the key is already decoded. There is no visible signature field, and the printed note says whether the copy is signed.

`signer::apply()` returns whether it will sign. `builder::render()` passes that to the note: `digitallysigned` or `notsigned`. TCPDF does the signing inside `Output()`, as a detached PKCS#7 signature. In the file you can find `/ByteRange`, `/SubFilter /adbe.pkcs7.detached` and `/DocMDP` with `/P 1`.

Each download makes a new signature, with the certificate in place at that moment. The signature says the file wasn't changed since that download. It says nothing about the moment of issue.

Tests: `signer_test::test_apply_signs_with_school_name_and_reason()`, `signer_test::test_apply_signs_with_site_name_without_school_name()`, `signer_test::test_certificate_and_signature_text_come_from_language_strings()`.

## Certificates

`signer::credentials()` decides, in order:

1. No `openssl_pkcs12_read()` in PHP: null, so the copy is unsigned and prints `notsigned`.
2. A file in the area `signingcert`: read with the setting `signingcertpassword`. Extra certificates go to a file in a request directory, and `source` is `uploaded`.
3. An unreadable file or a wrong password: `debugging()` at developer level only, then the self-signed certificate. No page shows a message.
4. `signer::selfsigned()`.

`signer::selfsigned()` reads `selfsigned-cert.pem` and `selfsigned-key.pem` from `<dataroot>/local_academictranscript/signing`. When either is missing, it makes both:

- A 2048-bit RSA key.
- A certificate signed with SHA-256, valid for 3650 days, with a random serial.
- The common name from the string `signername` and the organisation from `transcript::school_name()`, each cut to 64 characters.
- A minimal OpenSSL config from `signer::config_file()`, so no sample values such as a country reach the certificate.
- The key file gets mode 0600.

Any failure returns null, so the copy is unsigned.

The certificate is made once. A later change to the school name changes the signature's `Name` and not the certificate. Nothing renews it after 3650 days, until someone removes the two files. PDF readers show a self-signed signer as not trusted, and a change to the file is still detected.

`local_academictranscript_pluginfile()` never serves `signingcert`, and the key in the data folder isn't web-served. `xmldb_local_academictranscript_uninstall()` deletes the folder.

Tests: `signer_test::test_selfsigned_certificate_uses_site_name_without_school_name()`, `signer_test::test_selfsigned_certificate_uses_school_name()`, `signer_test::test_selfsigned_certificate_does_not_escape_the_site_name()`, `signer_test::test_selfsigned_certificate_cuts_long_names_to_64_characters()`, `signer_test::test_bad_uploaded_certificate_falls_back_to_selfsigned()`, `uninstall_test::test_uninstall_deletes_term_field_and_signing_key()`.

## TCPDF traps

- For an HTML style that names a font TCPDF doesn't know, TCPDF goes on with the current font and says nothing. `builder_test::test_html_styles_name_registered_font_families()` guards this.
- The data is plain text, so `builder` escapes each value with `s()` before it goes into HTML. `MultiCell()` and `Cell()` take plain text and aren't escaped. Tests: `builder_test::test_pdf_prints_names_with_ampersand()`, `builder_test::test_render_handles_accented_names()`.
- `builder::measure()` writes on a scratch document, a page 216 mm wide and 2000 mm high, and starts a new one past 1500 mm. Measure at the width you will draw at, or a block lands over the next one.
- The signature block places every element by offset from its top. A change to `builder::SIGH` moves the bottom note and the summary too.
- `getAliasNumPage()` and `getAliasNbPages()` are page number placeholders that TCPDF replaces at output.

## Related pages

- [Code map](README.md)
- [Data model](data-model.md)
- [Execution flows](execution-flows.md)
- [Settings](settings.md)
- [Extending](extending.md)
