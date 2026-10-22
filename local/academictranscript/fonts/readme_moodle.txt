Description of Source Sans 3 and Source Serif 4 font import into Academic Transcript

The PDF transcripts embed these fonts. The files here are in the font format of TCPDF, the PDF
library that comes with Moodle. They are not the files that upstream publishes.

Fonts
-----
Source Sans 3:   version 3.052, release 3.052R, https://github.com/adobe-fonts/source-sans
Source Serif 4:  version 4.005, release 4.005R, https://github.com/adobe-fonts/source-serif
License:         SIL Open Font License 1.1. The texts are OFL-SourceSans3.txt and
                 OFL-SourceSerif4.txt in this folder.
Copyright:       Adobe (Source Sans 3), The Source Serif 4 Project Authors (Source Serif 4)
Used by:         classes/local/pdf/document.php registers the fonts, and
                 classes/local/pdf/builder.php sets them.

What is here
------------
There are seven fonts. Each one is three files:

  <name>.php     the TCPDF font definition: the type, the font name, the metrics and the
                 glyph widths
  <name>.z       the TrueType file, compressed with zlib
  <name>.ctg.z   the map from character codes to glyph numbers, compressed with zlib

  TCPDF name        $name in the .php file    Family and style in the code
  sourcesans3       SourceSans3-Regular       sourcesans3, regular
  sourcesans3b      SourceSans3-Bold          sourcesans3, bold
  sourcesans3i      SourceSans3-Italic        sourcesans3, italic
  sourcesans3semib  SourceSans3-SemiBold      sourcesans3semib, regular
  sourceserif4      SourceSerif4-Regular      sourceserif4, regular
  sourceserif4b     SourceSerif4-Bold         sourceserif4, bold
  sourceserif4i     SourceSerif4-Italic       sourceserif4, italic

document.php registers three families with these files: sourcesans3, sourcesans3semib and
sourceserif4. Bold italic text uses the bold file. The family sourcesans3semib borrows the bold
and italic files of sourcesans3.

How the names come about
------------------------
TCPDF_FONTS::addTTFfont() names its output after the TrueType file it reads. It takes the file
name without .ttf, makes it lower case, removes everything except a-z, 0-9 and _, turns "bold"
into "b", turns "italic" and "oblique" into "i", and drops "regular". SourceSans3-SemiBold.ttf
becomes sourcesans3semib, and SourceSerif4-Italic.ttf becomes sourceserif4i. This is why the
TrueType files must have the names in the table above.

The $name line inside a .php file is another thing. TCPDF reads it from the PostScript name
stored in the font (name ID 6), and PDF readers show it as the name of the embedded font.

How the files were made
-----------------------
Each TrueType file went through TCPDF_FONTS::addTTFfont() with the type TrueTypeUnicode, no
encoding table and the flags 32 (a font that is not symbolic). The headers of the .php files
show it: $type='TrueTypeUnicode' and $enc=''. TCPDF adds the italic flag by itself, which is
why the italic definitions say Flags 96.

How the files relate to the upstream release
--------------------------------------------
The TrueType files that were converted are not in this repo. The $originalsize line of each
.php file is the size of the file that was converted, for example 234708 bytes for
SourceSans3-Regular.

The glyph widths ($cw) in these files match the widths of static fonts cut from the variable
fonts of the upstream releases:

  Source Sans 3, release 3.052R: weight 400 (Regular and Italic), 600 (SemiBold), 700 (Bold)
  Source Serif 4, release 4.005R: optical size 14 and weight 400 (Regular and Italic), 700 (Bold)

They match for every glyph, except U+0140 in the Bold and Italic files of Source Serif 4. The
check ran on 2026-10-21, with fontTools 4.67.0 and the TCPDF of Moodle 5.3.

Don't use the static TTF files of the Source Serif 4 release. Most of their widths differ from
these files, so text would wrap differently.

A fresh conversion of Source Sans 3 gives Ascent 1000 and Descent -326 in $desc. The files here
have 1024 and -400. Source Serif 4 gives the same Ascent and Descent as the files here (1036 and
-335).

Instructions
------------
You need Python with fontTools (pip install fonttools), PHP, and the TCPDF that comes with
Moodle (lib/tcpdf in the Moodle code folder, public/lib/tcpdf on Moodle 5.1 and later).

1. Download the variable fonts from the upstream release. For the versions here, the archives
   are VF-source-sans-3.052R.zip (Source Sans 3) and source-serif-4.005_Desktop.zip (Source
   Serif 4). They hold:
   - VF/SourceSans3VF-Upright.ttf and VF/SourceSans3VF-Italic.ttf in the first,
   - VAR/SourceSerif4Variable-Roman.ttf and VAR/SourceSerif4Variable-Italic.ttf in the second.
   Take the OFL texts from the same release if they changed.
2. Cut seven static fonts. The names of the output files matter (see "How the names come
   about"):

       fonttools varLib.instancer SourceSans3VF-Upright.ttf wght=400 -o SourceSans3-Regular.ttf
       fonttools varLib.instancer SourceSans3VF-Upright.ttf wght=600 -o SourceSans3-SemiBold.ttf
       fonttools varLib.instancer SourceSans3VF-Upright.ttf wght=700 -o SourceSans3-Bold.ttf
       fonttools varLib.instancer SourceSans3VF-Italic.ttf wght=400 -o SourceSans3-Italic.ttf
       fonttools varLib.instancer SourceSerif4Variable-Roman.ttf wght=400 opsz=14 -o SourceSerif4-Regular.ttf
       fonttools varLib.instancer SourceSerif4Variable-Roman.ttf wght=700 opsz=14 -o SourceSerif4-Bold.ttf
       fonttools varLib.instancer SourceSerif4Variable-Italic.ttf wght=400 opsz=14 -o SourceSerif4-Italic.ttf

   Leave out --update-name-table. It fails for opsz=14.
3. Convert the seven files into an empty folder. The path of the output folder must end with
   a slash. TCPDF skips a font whose .php file is already in the output folder.

       <?php
       require '/path/to/moodle/lib/tcpdf/tcpdf.php';
       foreach (glob('/path/to/ttf/*.ttf') as $ttf) {
           echo TCPDF_FONTS::addTTFfont($ttf, 'TrueTypeUnicode', '', 32, '/path/to/output/'), "\n";
       }

   It prints the seven TCPDF names from the table above.
4. In each new .php file, set $name to the name in the table above. In the four Source Sans 3
   files, set Ascent to 1024 and Descent to -400 in $desc. The new files still differ from the
   old ones in $originalsize and by a few units in FontBBox and AvgWidth. That is expected.
5. Copy the 21 files into this folder over the old ones, with the same names. Replace the OFL
   texts too if upstream changed them.
6. Update the versions in ../thirdpartylibs.xml and in this file.
7. Run the tests, from the root of the repo:

       bin/check --plugin local/academictranscript phpunit

   Then open an Official and an Unofficial transcript PDF and look at them. The glyph widths
   decide where lines wrap.
