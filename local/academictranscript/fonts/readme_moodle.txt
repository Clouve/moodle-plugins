Description of Clouve Sans and Clouve Serif font import into Academic Transcript

The PDF transcripts embed these fonts. Clouve Sans and Clouve Serif are modified versions of
Adobe's Source Sans 3 and Source Serif 4. They are not the fonts that Adobe publishes. The files
here are in the font format of TCPDF, the PDF library that comes with Moodle.

Fonts
-----
Clouve Sans:   derived from Source Sans 3, version 3.052, release 3.052R,
               https://github.com/adobe-fonts/source-sans
Clouve Serif:  derived from Source Serif 4, version 4.004 (as the font files report it),
               https://github.com/adobe-fonts/source-serif
License:       SIL Open Font License 1.1. The texts are OFL-SourceSans3.txt and
               OFL-SourceSerif4.txt in this folder.
Copyright:     Adobe, with Reserved Font Name 'Source' (both fonts)
Used by:       classes/local/pdf/document.php registers the fonts, and
               classes/local/pdf/builder.php sets them.

What is here
------------
There are seven fonts. Each one is three files:

  <name>.php     the TCPDF font definition: the type, the font name, the metrics and the
                 glyph widths
  <name>.z       the TrueType file, compressed with zlib
  <name>.ctg.z   the map from character codes to glyph numbers, compressed with zlib

  TCPDF name       $name in the .php file  Family and style in the code
  clouvesans       ClouveSans-Regular      clouvesans, regular
  clouvesansb      ClouveSans-Bold         clouvesans, bold
  clouvesansi      ClouveSans-Italic       clouvesans, italic
  clouvesanssemib  ClouveSans-SemiBold     clouvesanssemib, regular
  clouveserif      ClouveSerif-Regular     clouveserif, regular
  clouveserifb     ClouveSerif-Bold        clouveserif, bold
  clouveserifi     ClouveSerif-Italic      clouveserif, italic

document.php registers three families with these files: clouvesans, clouvesanssemib and
clouveserif. Bold italic text uses the bold file. The family clouvesanssemib borrows the bold and
italic files of clouvesans.

Why the fonts have their own names
----------------------------------
Adobe reserves the name 'Source'. Condition 3 of the license says that a modified version can't
use a reserved name without the written permission of the copyright holder. A change of font
format makes a modified version (entries 2.2, 3.1 and 5.9 of the FAQ at
https://openfontlicense.org/ofl-faq/). The fonts here are in the format of TCPDF, so they need
their own names.

The word stays in these places only:

- the copyright and trademark records of each font (name IDs 0 and 7)
- the two license texts and their file names. Keep the texts as they are: they carry Adobe's
  copyright notice, the Reserved Font Name and the trademark notice.
- this file and ../thirdpartylibs.xml, to say what the fonts are derived from, and the upstream
  file names in the instructions below

PDFs made before the rename keep the old font names. The limits on modified fonts don't apply to a
font embedded in a document (entry 1.12 of the FAQ), so those PDFs need no change.

What was changed
----------------
The rename changed the name table of each font. These name records changed:

  ID  Record              Old                           New
  1   family              Source Sans 3                 Clouve Sans
                          Source Sans 3 SemiBold        Clouve Sans SemiBold
                          Source Serif 4                Clouve Serif
  3   unique ID           3.052;ADBO;SourceSans3-Bold   3.052;ADBO;ClouveSans-Bold
                          4.004;ADBO;SourceSerif4-Bold  4.004;ADBO;ClouveSerif-Bold
  4   full name           Source Sans 3 Bold            Clouve Sans Bold
                          Source Serif 4 Bold           Clouve Serif Bold
  6   PostScript name     SourceSans3-Bold              ClouveSans-Bold
                          SourceSerif4-Bold             ClouveSerif-Bold
  16  typographic family  Source Sans 3                 Clouve Sans (the SemiBold file only)

The table shows the Bold files. The other files follow the same pattern.

The rename also restored name ID 7, the trademark notice. Upstream's fonts have it, the files here
had lost it, and entry 3.7 of the FAQ says that such a notice must remain in a derivative font. The
text is the one in the upstream fonts:

    Source is a trademark of Adobe in the United States and/or other countries.

All other name records stayed, including the copyright (0) and the link to the license (14). All
other tables stayed byte for byte, so the glyphs, the metrics and the character map are the same.
The one exception is checkSumAdjustment in the head table, which depends on the whole file.

Each TrueType file is 148 to 152 bytes longer, mostly because of the trademark notice. In the .php
files, $name, $file, $ctg and $originalsize changed. The .ctg.z files got new file names and
nothing else.

To repeat the rename, get the files from before it. The tag local_academictranscript-v1.0.0 has
them. Unpack them outside the repo, so that they don't land on the fonts here. From the root of the
repo:

    mkdir /tmp/oldfonts
    git archive local_academictranscript-v1.0.0 local/academictranscript/fonts | tar -x -C /tmp/oldfonts

Then, in /tmp/oldfonts/local/academictranscript/fonts:

1. Unpack each .z file into a TrueType file with its new name, with this Python script:

       import zlib
       names = {'sourcesans3': 'ClouveSans-Regular', 'sourcesans3b': 'ClouveSans-Bold',
                'sourcesans3i': 'ClouveSans-Italic', 'sourcesans3semib': 'ClouveSans-SemiBold',
                'sourceserif4': 'ClouveSerif-Regular', 'sourceserif4b': 'ClouveSerif-Bold',
                'sourceserif4i': 'ClouveSerif-Italic'}
       for old, new in names.items():
           with open(old + '.z', 'rb') as packed, open(new + '.ttf', 'wb') as ttf:
               ttf.write(zlib.decompress(packed.read()))

2. Follow steps 3 and 4 of the instructions below.

TCPDF then writes .php files that match the files here byte for byte. The .z and .ctg.z files hold
the same data, but their compressed bytes can differ with the version of zlib.

How the names come about
------------------------
TCPDF_FONTS::addTTFfont() names its output after the TrueType file it reads. It takes the file
name without .ttf, makes it lower case, removes everything except a-z, 0-9 and _, turns "bold"
into "b", turns "italic" and "oblique" into "i", and drops "regular". ClouveSans-SemiBold.ttf
becomes clouvesanssemib, and ClouveSerif-Italic.ttf becomes clouveserifi. This is why the TrueType
files must have the names in the instructions below.

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
The $originalsize line of each .php file is the size of the TrueType file inside the .z file, for
example 234860 bytes for clouvesans.

The glyph widths ($cw) in these files match the widths of static fonts cut from the variable
fonts of the upstream releases:

  Source Sans 3, release 3.052R: weight 400 (Regular and Italic), 600 (SemiBold), 700 (Bold)
  Source Serif 4, release 4.005R: optical size 14 and weight 400 (Regular and Italic), 700 (Bold)

They match for every glyph, except U+0140 in the Bold and Italic files of Clouve Serif. The check
ran on 2026-11-06, with fontTools 4.67.0 and the TCPDF of Moodle 5.2.

Don't use the static TTF files of the Source Serif 4 release. Most of their widths differ from
these files, so text would wrap differently.

A fresh conversion of the Source Sans 3 release gives Ascent 1000 and Descent -326 in $desc. The
files here have 1024 and -400. The Source Serif 4 release gives the same Ascent and Descent as the
files here (1036 and -335).

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

       fonttools varLib.instancer SourceSans3VF-Upright.ttf wght=400 -o ClouveSans-Regular.ttf
       fonttools varLib.instancer SourceSans3VF-Upright.ttf wght=600 -o ClouveSans-SemiBold.ttf
       fonttools varLib.instancer SourceSans3VF-Upright.ttf wght=700 -o ClouveSans-Bold.ttf
       fonttools varLib.instancer SourceSans3VF-Italic.ttf wght=400 -o ClouveSans-Italic.ttf
       fonttools varLib.instancer SourceSerif4Variable-Roman.ttf wght=400 opsz=14 -o ClouveSerif-Regular.ttf
       fonttools varLib.instancer SourceSerif4Variable-Roman.ttf wght=700 opsz=14 -o ClouveSerif-Bold.ttf
       fonttools varLib.instancer SourceSerif4Variable-Italic.ttf wght=400 opsz=14 -o ClouveSerif-Italic.ttf

   Leave out --update-name-table. It fails for opsz=14.
3. Rename the fonts. Save this script as rename_font.py and run it on the seven files, with
   python3 rename_font.py Clouve*.ttf

       import sys
       from pathlib import Path
       from fontTools.ttLib import TTFont

       KEEP = (0, 7, 13, 14)  # copyright, trademark, license, license URL
       TRADEMARK = 'Source is a trademark of Adobe in the United States and/or other countries.'

       for arg in sys.argv[1:]:
           path = Path(arg)
           family, style = path.stem.split('-')
           words = family.replace('Clouve', 'Clouve ')
           full = words + ' ' + style
           font = TTFont(path, recalcBBoxes=False, recalcTimestamp=False)
           table = font['name']
           version = table.getDebugName(5).split(';')[0].removeprefix('Version ')
           names = {1: words, 2: style, 3: version + ';ADBO;' + path.stem, 4: full, 6: path.stem}
           if style == 'SemiBold':
               names.update({1: full, 2: 'Regular', 16: words, 17: style})
           if table.getName(7, 3, 1, 0x409) is None:
               names[7] = TRADEMARK
           for nameid, text in names.items():
               table.setName(text, nameid, 3, 1, 0x409)
           table.removeNames(nameID=25)
           for record in table.names:
               assert record.nameID in KEEP or 'source' not in record.toUnicode().lower(), record.nameID
           font.save(path, reorderTables=False)

   The script names each font after its file, and adds the trademark notice to a font that has
   none. It stops if a record outside KEEP still has the reserved word. It leaves the other records
   in KEEP and the other tables as they are.
4. Convert the seven files into an empty folder. The path of the output folder must end with
   a slash. TCPDF skips a font whose .php file is already in the output folder.

       <?php
       require '/path/to/moodle/lib/tcpdf/tcpdf.php';
       foreach (glob('/path/to/ttf/*.ttf') as $ttf) {
           echo TCPDF_FONTS::addTTFfont($ttf, 'TrueTypeUnicode', '', 32, '/path/to/output/'), "\n";
       }

   It prints the seven TCPDF names from the table above.
5. In the four Clouve Sans files, set Ascent to 1024 and Descent to -400 in $desc. The new
   files still differ from the old ones in $originalsize and by a few units in FontBBox and
   AvgWidth. That is expected.
6. Copy the 21 files into this folder over the old ones, with the same names. Replace the OFL
   texts too if upstream changed them.
7. Update the versions in ../thirdpartylibs.xml and in this file.
8. Run the tests, from the root of the repo:

       bin/check --plugin local/academictranscript phpunit

   Then open an Official and an Unofficial transcript PDF and look at them. The glyph widths
   decide where lines wrap.
