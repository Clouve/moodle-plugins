<?php

namespace local_studentrecords\local;

#[\PHPUnit\Framework\Attributes\CoversClass(importer::class)]
/**
 * Tests for the importer, with a made-up school: sheet recognition, header matching, checks and gaps.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @covers     \local_studentrecords\local\importer
 */
final class importer_test extends \basic_testcase {
    /**
     * A small workbook of a made-up school.
     *
     * @return array
     */
    protected static function workbook(): array {
        $grading = [['Letter', 'Min %', 'Max %', 'Quality points', 'Counts in GPA', 'Undergraduate', 'Graduate (Master)']];
        foreach ([['A', 90, 100, 4], ['B', 80, 89, 3], ['C', 70, 79, 2], ['D', 60, 69, 1], ['F', 0, 59, 0]] as [$l, $a, $b, $p]) {
            $grading[] = [$l, $a, $b, $p, 'Yes', $l === 'F' ? 'Fail' : 'Pass', in_array($l, ['D', 'F']) ? 'Fail' : 'Pass'];
        }
        return [
            'About' => [['Item', 'Notes'], ['Source', 'Made up for tests']],
            'School' => [['Field', 'Value'], ['Institution name', 'Example College'],
                ['Principal place of business', '1 Main Street, Springfield, IL 62701'], ['Accreditation', 'Not accredited'],
                ['Title IV federal aid', 'Does not participate'], ['Calendar type', 'Quarter system'], ['Mascot', 'Owl']],
            'Calendar' => [['Term', 'Start', 'End', 'Add/Drop start', 'Add/Drop end', 'Final exams'],
                ['Fall 2030', '2030-09-02', '2030-11-22', '2030-09-02', '2030-09-09', '2030-11-18 to 2030-11-22'],
                ['Break', '2030-11-25', '2030-12-31', '', '', ''],
                ['Bad', '2030-12-01', '2030-11-01', '', '', '']],
            'Grades' => $grading,
            'Rules' => [['Rule', 'Undergraduate', 'Graduate (Master)', 'Certificate'],
                ['Minimum passing course grade', 'D', 'C or better', '75%'], ['Good standing GPA', '2.0', '3.0', 'same']],
            'Programs' => [['Program code', 'Program name', 'Level', 'Credential', 'Total hours', 'Hour type',
                'Track credits (choose one)', 'Grade rules', 'Tuition per credit'],
                ['BA-ART', 'Bachelor of Arts in Art', 'Bachelor', 'BA', '9', 'Credit hours', '3', 'Core min C', '200'],
                ['MA-ART', 'Master of Arts in Art', 'Master', 'MA', '6', 'Credit hours', '0', 'Recommended B', '400'],
                ['CERT', 'Drawing Certificate', 'Non-degree', 'Certificate', '120', 'Clock hours', '0', '', '10']],
            'Outlines' => [['Program code', 'Group / track', 'Course code', 'Course title', 'Hours', 'Requirement'],
                ['BA-ART', 'Art Core (6 credits)', 'ART 101', 'Drawing I', '3', 'Required'],
                ['BA-ART', 'Art Core (6 credits)', 'ART102', 'Drawing II', '3', 'Required'],
                ['BA-ART', 'Track A', 'ART201', 'Painting', '3', 'Track option'],
                ['MA-ART', 'Core', 'ART501', 'Studio Seminar', '3', 'Required'],
                ['MA-ART', 'Core', 'ART502', 'Thesis', '6', 'Required'],
                ['CERT', 'Levels', 'DRW1', 'Drawing Level 1', '120', 'Required']],
            'Courses' => [['Course code', 'Title', 'Hours', 'Hour type', 'Level', 'Prerequisites'],
                ['ART101', 'Drawing I', '3', 'Credit hours', 'Undergraduate', ''],
                ['ART102', 'Drawing II', '3', 'Credit hours', 'Undergraduate', 'ART101'],
                ['ART201', 'Painting', '3', 'Credit hours', 'Undergraduate', 'ART102, ART999 and instructor consent'],
                ['ART501', 'Studio Seminar', '3', 'Credit hours', 'Graduate', ''],
                ['ART502', 'Thesis', '3', 'Credit hours', 'Graduate', 'All core courses'],
                ['DRW1', 'Drawing Level 1', '120', 'Clock hours', 'Certificate', ''],
                ['ART101', 'Drawing One', '3', 'Credit hours', 'Undergraduate', '']],
            'Fees' => [['Item', 'Amount'], ['Tuition', '200']],
            'Staff' => [['Name', 'Role / title', 'Type', 'Highest credential'],
                ['Dr. Ada Example', 'Faculty', 'Faculty', 'Ph.D., Art History, Example University, 2001'],
                ['Bob (Robert) Sample', 'Registrar', 'Staff', ''], ['Madonna', 'Faculty', 'Faculty', '']],
        ];
    }

    /**
     * Items of one type, by key.
     *
     * @param array $result
     * @param string $type
     * @return array key => [data, problem keys]
     */
    protected static function items(array $result, string $type): array {
        $out = [];
        foreach ($result['items'] as [$t, $key, $data, , $problems]) {
            if ($t === $type) {
                $out[$key] = [$data, array_map(fn($p) => $p[1], $problems)];
            }
        }
        return $out;
    }

    /**
     * Sheets are recognised by their headers; fee sheets are left for a later phase.
     */
    public function test_recognise(): void {
        $r = importer::build(self::workbook());
        $types = array_map(fn($s) => $s['type'], $r['sheets']);
        $this->assertSame(['About' => '', 'School' => 'school', 'Calendar' => 'term', 'Grades' => 'grade', 'Rules' => 'policy',
            'Programs' => 'program', 'Outlines' => 'progcrs', 'Courses' => 'course', 'Fees' => 'later',
            'Staff' => 'faculty'], $types);
    }

    /**
     * School details: names mapped, "Not accredited" emptied, yes/no read, state taken from the address.
     */
    public function test_school(): void {
        $school = self::items(importer::build(self::workbook()), 'school');
        $this->assertSame('Example College', $school['schoolname'][0]['value']);
        $this->assertSame('', $school['accreditor'][0]['value']);
        $this->assertSame('0', $school['fedaid'][0]['value']);
        $this->assertSame('quarter', $school['calendartype'][0]['value']);
        $this->assertSame('IL', $school['state'][0]['value']);
    }

    /**
     * Terms: dates read, exams split, breaks and impossible terms flagged.
     */
    public function test_terms(): void {
        $terms = self::items(importer::build(self::workbook()), 'term');
        $this->assertSame('2030-09-09', $terms['fall 2030'][0]['adddropend']);
        $this->assertSame('2030-11-18', $terms['fall 2030'][0]['examstart']);
        $this->assertSame(['problem_withdrawcalc'], $terms['fall 2030'][1]);
        $this->assertSame(['problem_noadddrop', 'problem_withdrawcalc'], $terms['break'][1]);
        $this->assertContains('problem_termorder', $terms['bad'][1]);
    }

    /**
     * Last day to withdraw: read when given; else 60% of the term, or the catalog's rule; marked as calculated.
     */
    public function test_withdraw(): void {
        $terms = self::items(importer::build(self::workbook()), 'term');
        $this->assertSame(['2030-10-20', 1], [$terms['fall 2030'][0]['withdrawby'], $terms['fall 2030'][0]['withdrawcalc']]);
        $this->assertNull($terms['bad'][0]['withdrawby']);

        $book = self::workbook();
        $book['Rules'][] = ['Withdrawal deadline', 'Until 50% of the term', '', ''];
        $result = importer::build($book);
        $terms = self::items($result, 'term');
        $this->assertSame('2030-10-12', $terms['fall 2030'][0]['withdrawby']);
        $this->assertSame(['problem_withdrawrule'], $terms['fall 2030'][1]);
        $this->assertSame('50', self::items($result, 'school')['withdrawpct'][0]['value']);

        $book = self::workbook();
        $book['Calendar'][0][] = 'Last day to withdraw';
        $book['Calendar'][1][] = '2030-10-31';
        $terms = self::items(importer::build($book), 'term');
        $this->assertSame(['2030-10-31', 0], [$terms['fall 2030'][0]['withdrawby'], $terms['fall 2030'][0]['withdrawcalc']]);
        $this->assertSame([], $terms['fall 2030'][1]);
    }

    /**
     * Delivery mode from the catalog's words; hybrid programs need a location.
     */
    public function test_delivery(): void {
        $this->assertSame('online', importer::delivery_mode('100% Online'));
        $this->assertSame('inperson', importer::delivery_mode('On campus'));
        $this->assertSame('hybrid', importer::delivery_mode('100% Online (table) vs in-person Riverside (facilities text)'));
        $this->assertSame('hybrid', importer::delivery_mode('Blended'));
        $this->assertNull(importer::delivery_mode('Evenings'));
        $this->assertNull(importer::delivery_mode(''));

        $book = self::workbook();
        $book['Programs'][0][] = 'Delivery';
        $book['Programs'][0][] = 'Location';
        $book['Programs'][1][] = 'Online';
        $book['Programs'][1][] = '';
        $book['Programs'][2][] = 'Online and in person';
        $book['Programs'][2][] = '';
        $book['Programs'][3][] = 'Hybrid';
        $book['Programs'][3][] = 'Springfield';
        $programs = self::items(importer::build($book), 'program');
        $this->assertSame('online', $programs['BA-ART'][0]['deliverymode']);
        $this->assertSame('hybrid', $programs['MA-ART'][0]['deliverymode']);
        $this->assertContains('problem_nolocation', $programs['MA-ART'][1]);
        $this->assertSame(['hybrid', 'Springfield'], [$programs['CERT'][0]['deliverymode'], $programs['CERT'][0]['location']]);
        $this->assertNotContains('problem_nolocation', $programs['CERT'][1]);
    }

    /**
     * Qualified courses: from a column on the staff sheet or a sheet of their own; unknown courses flagged.
     */
    public function test_qualifications(): void {
        $book = self::workbook();
        $book['Staff'][0][] = 'Qualified courses';
        $book['Staff'][1][] = 'ART101, ART 102';
        $book['Staff'][2][] = '';
        $book['Staff'][3][] = '';
        $book['Teaching'] = [['Faculty name', 'Course code'], ['Dr. Ada Example', 'ART501'], ['Cy Twombly', 'ART999']];
        $result = importer::build($book);
        $this->assertSame('facqual', $result['sheets']['Teaching']['type']);
        $quals = self::items($result, 'facqual');
        $this->assertSame(
            ['example ada|ART101', 'example ada|ART102', 'example ada|ART501', 'twombly cy|ART999'],
            array_keys($quals)
        );
        $this->assertSame(['Ada', 'Example', 'ART102'], [$quals['example ada|ART102'][0]['firstname'],
            $quals['example ada|ART102'][0]['lastname'], $quals['example ada|ART102'][0]['coursecode']]);
        $this->assertSame([], $quals['example ada|ART501'][1]);
        $this->assertSame(['problem_qualcourse'], $quals['twombly cy|ART999'][1]);
        $this->assertSame(['ART101', 'MGT5301'], importer::course_codes('art101; MGT 5301, ART101'));
        $this->assertSame(['Dr.', 'Ana Maria', 'Ruiz'], importer::split_name('Dr. Ana Maria Ruiz (Dean)'));
    }

    /**
     * Admissions table: documents per level or credential; optional ones marked; fees and rules kept as notes.
     */
    public function test_admissions_checklist(): void {
        $book = self::workbook();
        $book['Admissions'] = [['Level', 'Prior degree', 'Min GPA', 'TOEFL iBT', 'IELTS', 'Other'],
            ['Bachelor', 'High school diploma or GED', '2.5', '61', '6.0', 'Personal statement; letters optional; application fee'],
            ['Graduate (Master)', 'Bachelor', '3.0', '79', '6.5', 'Transcripts; GMAT/GRE optional'],
            ['Certificate', 'None stated', 'n/a', 'n/a', 'n/a',
                'Placement assessment ($50). Level 6 = conditional admission to degree programs'],
            ['All levels', 'Foreign credentials evaluated by NACES member', '', '', '',
                'Denied applicants may appeal within 30 days']];
        $result = importer::build($book);
        $this->assertSame('reqdoc', $result['sheets']['Admissions']['type']);
        $docs = self::items($result, 'reqdoc');
        $by = [];
        foreach ($docs as [$d]) {
            $by[$d['scopetext'] . ': ' . $d['name']] = [$d['required'], $d['levelname'], $d['credentialtype']];
        }
        $this->assertSame([1, '', 'bachelor'], $by['Bachelor: Prior education: High school diploma or GED']);
        $this->assertSame([1, '', 'bachelor'], $by['Bachelor: Personal statement']);
        $this->assertSame([0, '', 'bachelor'], $by['Bachelor: Letters']);
        $this->assertSame([0, '', 'bachelor'], $by['Bachelor: English proficiency test']);
        $this->assertSame([0, 'Graduate', 'master'], $by['Graduate (Master): GMAT/GRE']);
        $this->assertSame([1, 'Certificate', 'certificate'], $by['Certificate: Placement assessment']);
        $this->assertSame([0, '', ''], $by['All levels: Foreign credentials evaluated by NACES member']);
        $this->assertArrayNotHasKey('Bachelor: Application fee', $by);
        $notes = array_map(fn($g) => $g[1] . ' ' . $g[2], $result['gaps']);
        $this->assertContains('gap_admissionfee Bachelor: application fee', $notes);
        $this->assertContains('gap_admissionrule All levels: Denied applicants may appeal within 30 days', $notes);
        $this->assertContains('gap_admissionrule Certificate: Level 6 = conditional admission to degree programs', $notes);
        $this->assertContains('gap_admissionfee Certificate: Placement assessment ($50)', $notes);
    }

    /**
     * Calendar types: named types are found before the plain word "term".
     */
    public function test_calendar_types(): void {
        foreach (
            ['Semester terms' => 'semester', 'Quarter system' => 'quarter', 'Term-based' => 'term',
            'Continuous enrollment' => 'continuous', 'Trimesters' => 'trimester'] as $text => $expected
        ) {
            $book = self::workbook();
            $book['School'][5] = ['Calendar type', $text];
            $this->assertSame($expected, self::items(importer::build($book), 'school')['calendartype'][0]['value'], $text);
        }
    }

    /**
     * Pass rules per level from the grading sheet and the rules sheet; missing ones are gaps.
     */
    public function test_pass_rules(): void {
        $r = importer::build(self::workbook());
        $rules = self::items($r, 'minpass');
        $this->assertSame('D', $rules['level:undergraduate'][0]['minletter']);
        $this->assertSame('C', $rules['level:graduate'][0]['minletter']);
        $this->assertSame(75.0, $rules['level:certificate'][0]['minpct']);
        $this->assertSame('C', $rules['program:BA-ART|Art Core (6 credits)|'][0]['minletter']);
        $levels = self::items($r, 'level');
        $this->assertSame('Master', $levels['graduate'][0]['description']);
        $gaps = array_map(fn($g) => $g[1], $r['gaps']);
        $this->assertContains('gap_nomarks', $gaps);
        $this->assertContains('gap_columnsnotused', $gaps);
        $this->assertContains('gap_schoolunused', $gaps);
        $policies = self::items($r, 'policy');
        $this->assertSame('3.0', $policies['good_standing_gpa|certificate'][0]['value']);
    }

    /**
     * Courses and programs: codes normalised, duplicates and words flagged, totals checked, levels found.
     */
    public function test_courses_and_programs(): void {
        $r = importer::build(self::workbook());
        $courses = self::items($r, 'course');
        $this->assertContains('problem_duplicatecode', $courses['ART101'][1]);
        $this->assertSame(['ART102', 'ART999'], $courses['ART201'][0]['prereqcodes']);
        $this->assertContains('problem_prereqwords', $courses['ART201'][1]);
        $this->assertContains('problem_unknownprereq', $courses['ART201'][1]);
        $this->assertSame('clock', $courses['DRW1'][0]['unittype']);
        $outlines = self::items($r, 'progcrs');
        $this->assertContains('problem_hoursdiffer', $outlines['MA-ART|ART502|Core'][1]);
        $this->assertSame('track', $outlines['BA-ART|ART201|Track A'][0]['requirement']);
        $programs = self::items($r, 'program');
        $this->assertSame('Undergraduate', $programs['BA-ART'][0]['levelname']);
        $this->assertSame([], $programs['BA-ART'][1]);
        $this->assertSame('Graduate', $programs['MA-ART'][0]['levelname']);
        $this->assertContains('problem_totalmismatch', $programs['MA-ART'][1]);
        $this->assertContains('problem_rulenotconverted', $programs['MA-ART'][1]);
        $this->assertSame(['clock', 0, 'certificate'], [$programs['CERT'][0]['unittype'], $programs['CERT'][0]['isdegree'],
            $programs['CERT'][0]['credentialtype']]);
    }

    /**
     * Staff: honorifics and nicknames split off, single names flagged, staff and faculty told apart.
     */
    public function test_faculty(): void {
        $staff = self::items(importer::build(self::workbook()), 'faculty');
        $this->assertSame(['Dr.', 'Ada', 'Example', 'faculty'], [$staff['example ada'][0]['honorific'],
            $staff['example ada'][0]['firstname'], $staff['example ada'][0]['lastname'], $staff['example ada'][0]['stafftype']]);
        $this->assertSame(['Ph.D., Art History, Example University, 2001'], $staff['example ada'][0]['credentials']);
        $this->assertSame(['staff', ['problem_namebracket']], [$staff['sample bob'][0]['stafftype'], $staff['sample bob'][1]]);
        $this->assertContains('problem_onename', $staff['madonna'][1]);
    }

    /**
     * Items are applied after everything they refer to: pass rules after levels, programs, courses and outlines.
     */
    public function test_apply_order(): void {
        $order = array_flip(importer::TYPES);
        foreach (['level', 'program', 'course', 'progcrs'] as $needed) {
            $this->assertLessThan($order['minpass'], $order[$needed], $needed);
        }
        $this->assertLessThan($order['progcrs'], $order['program']);
        $this->assertLessThan($order['progcrs'], $order['course']);
        $this->assertLessThan($order['policy'], $order['level']);
        $this->assertLessThan($order['facqual'], $order['faculty']);
        $this->assertLessThan($order['facqual'], $order['course']);
        $this->assertLessThan($order['reqdoc'], $order['level']);
        $this->assertLessThan($order['reqdoc'], $order['program']);
    }

    /**
     * Small helpers: numbers, dates, unit types, credential types.
     */
    public function test_helpers(): void {
        $this->assertSame(1920.0, importer::number('1,920'));
        $this->assertNull(importer::number('$1,500 per level'));
        $this->assertSame('2027-01-11', importer::date('2027-01-11'));
        $this->assertSame('2027-01-11', importer::date('1/11/2027'));
        $this->assertSame('2027-01-11', importer::date('46398'));
        $this->assertNull(importer::date('soon'));
        $this->assertSame('clock', importer::unittype('Clock hours'));
        $this->assertNull(importer::unittype(''));
        $this->assertSame('doctorate', importer::credential_type('DBA'));
        $this->assertSame(['Graduate', 'Master/Certificate'], importer::level_from_header('Graduate (Master/Certificate)'));
    }
}
