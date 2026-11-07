#!/usr/bin/env python3
"""Crawl the test site as each role and report every page that is broken or empty.

Run it on a site that holds the snapshot "seed":

    docs/tools/site.sh restore seed
    docs/tools/smoke.py                  # every role, on the site named by SITE (default 1)
    docs/tools/smoke.py rosa carmen      # only these people ("guest" is nobody signed in)

For each page it prints a PASS or FAIL line. A page fails when it shows a Moodle error, a
PHP error or a stack trace, refuses the person, or doesn't show the text the seed should
have filled it with. Files (PDF, CSV) are fetched with the session of the person. It
exits 1 when any page failed. It opens pages and changes nothing, apart from the view
entries that a normal visit leaves in the logs.
"""
import concurrent.futures
import json
import os
import re
import subprocess
import sys
import tempfile
import threading
import urllib.error
import urllib.request

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from wd import HOST, SITE, WD  # noqa: E402

TOOLS = os.path.dirname(os.path.abspath(__file__))
SR = '/local/studentrecords/'
AT = '/local/academictranscript/'

# What a broken page says. Text from the plugins that merely mentions an error stays out.
BAD_TEXT = [
    'Stack trace', 'Coding error detected', 'Debug info:', 'Fatal error', 'Parse error', 'Warning: ', 'Notice: ',
    'Deprecated: ', 'Call to undefined', 'must be fixed by a programmer', 'Error reading from database',
    'error/moodle', 'Exception -', 'Undefined variable', 'Undefined array key', 'Undefined property',
]
RAW_STRING = re.compile(r'\[\[[a-z0-9_:/]+(,[a-z_]+)?\]\]')
REFUSED = 'Sorry, but you do not currently have permissions'

IDS_PHP = r'''<?php
define('CLI_SCRIPT', true);
require('/work/moodle/config.php');
$out = ['u' => [], 's' => [], 'term' => [], 'o' => [], 'c' => []];
foreach ($DB->get_records_select('user', 'deleted = 0 AND id > 1') as $u) {
    $out['u'][$u->username] = (int)$u->id;
}
foreach ($DB->get_records('local_studentrecords_student') as $s) {
    $username = array_search((int)$s->userid, $out['u'], true);
    $out['s'][$username] = (int)$s->id;
}
$now = time();
$terms = array_values($DB->get_records('local_studentrecords_term', null, 'startdate'));
foreach ($terms as $i => $t) {
    if ($t->startdate <= $now && $t->enddate >= $now) {
        $current = $i;
    }
}
foreach ($terms as $i => $t) {
    $out['term'][$i - $current] = (int)$t->id;
    $out['termname'][$i - $current] = $t->name;
}
$sql = "SELECT o.id, o.termid, o.section, o.moodlecourseid, c.code
          FROM {local_studentrecords_offer} o JOIN {local_studentrecords_course} c ON c.id = o.courseid";
foreach ($DB->get_records_sql($sql) as $o) {
    $key = array_search((int)$o->termid, $out['term'], true) . '|' . $o->code . '/' . $o->section;
    $out['o'][$key] = (int)$o->id;
    $out['c'][$key] = (int)$o->moodlecourseid;
}
$first = fn(string $table, array $where = [], string $sort = 'id') => (int)array_key_first($DB->get_records($table, $where, $sort, 'id', 0, 1));
$out['refund'] = $first('local_studentrecords_refund', ['status' => 'proposed', 'reason' => 'drop']);
$out['aidreturn'] = $first('local_studentrecords_refund', ['reason' => 'aidreturn']);
$out['receipt'] = (int)$DB->get_field_select('local_studentrecords_txn', 'MIN(id)', "receipt IS NOT NULL AND studentid = ?", [$out['s']['carmen']]);
$out['letter'] = $first('local_studentrecords_letter');
$issue = $DB->get_records('local_academictranscript_issue', null, 'id', '*', 0, 1);
$issue = reset($issue);
$out['issue'] = (int)$issue->id;
$out['verifycode'] = $issue->verifycode;
$out['posted'] = (int)$DB->get_field('local_academictranscript_posted', 'id', ['userid' => $out['u']['ben'], 'coursecode' => 'COM150 SU26'], IGNORE_MULTIPLE);
if (!$out['posted']) {
    $out['posted'] = (int)$DB->get_field_select('local_academictranscript_posted', 'MIN(id)', 'userid = ?', [$out['u']['ben']]);
}
foreach (['applied', 'approved', 'conferred'] as $state) {
    $out['app' . $state] = $first('local_studentrecords_gradapp', ['state' => $state]);
}
$out['meeting'] = (int)$DB->get_field_sql("SELECT MAX(m.id) FROM {local_studentrecords_meeting} m
    JOIN {local_studentrecords_attend} a ON a.meetingid = m.id WHERE m.offerid = ?", [$out['o']['0|ACC201/01']]);
foreach (['irene', 'tomas', 'doris'] as $name) {
    $out['f' . $name] = (int)$DB->get_field('local_studentrecords_faculty', 'id', ['userid' => $out['u'][$name]]);
}
$out['fee'] = $first('local_studentrecords_fee');
$out['plan'] = $first('local_studentrecords_plan');
$out['rule'] = $first('local_studentrecords_refrule');
$out['export'] = $first('local_studentrecords_export');
$out['reqdoc'] = $first('local_studentrecords_reqdoc');
$out['saprule'] = $first('local_studentrecords_saprule');
$out['program'] = (int)$DB->get_field('local_studentrecords_program', 'id', ['code' => 'AS-BUS']);
$out['course'] = (int)$DB->get_field('local_studentrecords_course', 'id', ['code' => 'ACC201']);
$out['year'] = \local_studentrecords\local\aidhelp::award_year($now);
$out['award'] = $first('local_studentrecords_aid', ['studentid' => $out['s']['carmen']]);
$out['hold'] = $first('local_studentrecords_hold', ['studentid' => $out['s']['ivy']]);
$out['reg'] = (int)$DB->get_field('local_studentrecords_reg', 'id', ['studentid' => $out['s']['carmen'], 'offerid' => $out['o']['0|ACC201/01']]);
$out['oldreg'] = (int)$DB->get_field('local_studentrecords_reg', 'id', ['studentid' => $out['s']['carmen'], 'offerid' => $out['o']['-2|BUS101/01']]);
echo json_encode($out);
'''


def lookup():
    """Ask the site for the ids that the page addresses need."""
    with tempfile.TemporaryDirectory() as folder:
        path = os.path.join(folder, 'smoke-ids.php')
        with open(path, 'w', encoding='utf-8') as out:
            out.write(IDS_PHP)
        reply = subprocess.run([os.path.join(TOOLS, 'site.sh'), 'php', path], check=True, capture_output=True, text=True,
                               env={**os.environ, 'SITE': str(SITE)})
    return json.loads(reply.stdout)


def student_pages(i, who):
    """What a student can open, each on their own record."""
    sid = i['s'][who]
    return [
        (SR + 'index.php?view=student', 'My classes'),
        (SR + f'student.php?id={sid}', 'My details'),
        (SR + f'account.php?id={sid}', 'Balance'),
        (SR + 'myregistration.php', None),
        (SR + 'mydocuments.php', None),
        (SR + 'ferpa.php', 'Annual Notice'),
        (SR + f'degreeaudit.php?id={sid}', None),
        (AT + 'index.php?view=mine', 'My transcript'),
        (f'file:{SR}statement.php?id={sid}', '%PDF'),
        (f'file:{AT}index.php?action=unofficial', '%PDF'),
        ('/my/', None),
        ('/my/courses.php', None),
        ('/user/profile.php', None),
    ]


def file_tabs(sid):
    """Every tab of a student's file."""
    tabs = ['profile', 'admissions', 'registration', 'attendance', 'holds', 'graduation', 'privacy', 'documents', 'history']
    return [(SR + f'student.php?id={sid}&tab={tab}', None) for tab in tabs]


def pages(i):
    """The page list of each person: (address, text it must show). A file: address is fetched, not opened."""
    sid = i['s']
    uid = i['u']
    term0 = i['term']['0']
    acc = i['c']['0|ACC201/01']
    com = i['c']['-1|COM150/01']
    eng = i['c']['0|ENG102/01']
    acc_offer = i['o']['0|ACC201/01']
    bio_offer = i['o']['0|BIO105/01']
    steps = ['school', 'approvals', 'calendar', 'grading', 'programs', 'courses', 'admissions', 'billing', 'staff', 'import', 'summary']

    records_staff = [
        (SR + 'index.php?view=staff', 'New applicants'),
        (SR + 'staff.php', None),
        (SR + 'students.php', 'Carmen'),
        (SR + 'students.php?status=active', 'Carmen'),
        (SR + 'admissions.php', 'Lucy'),
        (SR + 'admissions.php?show=missing', 'Ada'),
        (SR + 'admissions.php?show=waitlist', 'Tara'),
        (SR + 'admissions.php?report=pipeline', 'AS-BUS'),
        (SR + 'admissions.php?report=missing', 'Ada'),
        (SR + 'admissions.php?report=decisions', 'Denied'),
        (SR + f'standing.php?termid={i["term"]["-1"]}', 'No standing for this term yet'),
        (SR + f'standing.php?termid={i["term"]["-2"]}', 'Benedict'),
        (SR + 'gradechange.php', 'Theodore'),
        (SR + 'gradechange.php?state=approved', 'Gina'),
        (SR + 'graduation.php', 'Marco'),
        (SR + 'graduation.php?state=approved', 'Priya'),
        (SR + 'graduation.php?state=conferred', 'Henry'),
        (SR + f'graduation.php?app={i["appapplied"]}&action=review', None),
        (SR + f'graduation.php?app={i["appapproved"]}&action=confer', None),
        (SR + 'reports.php', None),
    ] + [(SR + f'reports.php?report={r}&termid={term0}', need) for r, need in
         (('enrollment', 'Business Administration'), ('hours', 'Business Administration'),
          ('completion', 'Business Administration'), ('graduation', 'Business Administration'), ('attendance', 'ACC201'),
          ('attendunder', 'Sean'))] + [
        (SR + 'outsidereports.php', 'starter'),
        (SR + 'outsidereports.php?tab=counts', 'Headcount'),
        (SR + f'outsidereports.php?tab=nsc&termid={term0}', 'Carmen'),
        (SR + f'degreeaudit.php?id={sid["gina"]}', 'All requirements met'),
        (SR + f'degreeaudit.php?id={sid["marco"]}', None),
        (SR + f'degreeaudit.php?id={sid["gina"]}&print=1', None),
        (SR + f'viewas.php?id={sid["carmen"]}', None),
        (SR + f'register.php?id={sid["ben"]}&action=add', None),
        (SR + f'register.php?id={sid["carmen"]}&action=withdraw&reg={i["reg"]}', None),
        (SR + f'register.php?id={sid["carmen"]}&action=grade&reg={i["reg"]}', None),
        (SR + f'gradechange.php?reg={i["oldreg"]}', None),
        (SR + f'letter.php?id={sid["carmen"]}', None),
        (f'file:{SR}letter.php?letter={i["letter"]}', '%PDF'),
        (SR + f'student.php?edit=1', None),
        (SR + f'student.php?id={sid["carmen"]}&edit=1', 'Carmen'),
    ] + file_tabs(sid['carmen']) + file_tabs(sid['yuki']) + file_tabs(sid['ivy']) + [
        (SR + f'student.php?id={sid["hank"]}&tab=graduation', 'Graduation'),
        (SR + f'student.php?id={sid["dan"]}&tab=registration', None),
        (SR + f'studentpart.php?id={sid["ada"]}&part=checklist', None),
        (SR + f'studentpart.php?id={sid["lucy"]}&part=decision', None),
        (SR + f'studentpart.php?id={sid["carmen"]}&part=hold', None),
        (SR + f'studentpart.php?id={sid["carmen"]}&part=xfer', None),
        (SR + f'studentpart.php?id={sid["gina"]}&part=gradapp', None),
        (SR + f'studentpart.php?id={sid["carmen"]}&part=relauth', None),
        (SR + f'studentpart.php?id={sid["carmen"]}&part=educ', None),
        (SR + f'studentpart.php?id={sid["carmen"]}&part=emerg', None),
        (SR + f'studentpart.php?id={sid["carmen"]}&part=demog', None),
        (SR + f'studentpart.php?id={sid["carmen"]}&part=ferpapaper', None),
        (SR + f'studentpart.php?id={sid["ivy"]}&part=release&item={i["hold"]}', None),
    ]

    setup_pages = [
        (SR + f'setup.php?step={step}', None) for step in steps
    ] + [
        (SR + f'offerings.php?termid={t}', 'ENG') for t in (i['term']['0'], i['term']['1'])
    ] + [
        (SR + f'offerings.php?id={acc_offer}', None),
        (SR + f'offerings.php?edit={acc_offer}', None),
        (SR + 'offerings.php?edit=0', None),
        (SR + 'terms.php', i['termname']['0']),
        (SR + f'terms.php?edit={term0}', None),
        (SR + 'programs.php', 'Business Administration'),
        (SR + f'programs.php?id={i["program"]}', 'Accounting Track'),
        (SR + f'programs.php?id={i["program"]}&edit=1', None),
        (SR + 'courses.php', 'ACC201'),
        (SR + f'courses.php?edit={i["course"]}', None),
        (SR + 'grading.php', 'A-'),
        (SR + 'grading.php?what=grade&id=0', None),
        (SR + 'grading.php?what=level&id=0', None),
        (SR + 'grading.php?what=minpass&id=0', None),
        (SR + 'checklist.php', 'Government photo ID'),
        (SR + f'checklist.php?edit={i["reqdoc"]}', None),
        (SR + 'saprules.php', None),
        (SR + f'saprules.php?edit={i["saprule"]}', None),
        (SR + 'faculty.php', 'Novak'),
        (SR + 'faculty.php?type=staff', 'Delgado'),
        (SR + f'facultyrecord.php?id={i["firene"]}', 'Ph.D.'),
        (SR + 'roster.php', 'Novak'),
        (SR + 'import.php', None),
        (SR + 'messages.php', None),
        (SR + 'messages.php?tab=log', 'Riverside College:'),
        (SR + 'messages.php?key=regadded', None),
        (SR + 'log.php', 'Rosa'),
        (SR + f'outsidereports.php?export={i["export"]}', None),
        (f'file:{SR}outsidereports.php?run={i["export"]}&download=csv', None),
    ]

    finance_pages = [
        (SR + 'index.php?view=finance', 'Refunds waiting'),
        (SR + 'billing.php', 'Carmen'),
        (SR + 'billing.php?filter=owing', 'Dara'),
        (SR + 'billing.php?filter=credit', 'Marco'),
        (SR + 'billingreports.php?report=owed', 'Dara'),
        (SR + 'billingreports.php?report=payments', None),
        (SR + 'billingreports.php?report=refunds', 'Eli'),
        (SR + 'billingreports.php?report=late', 'Dara'),
        (SR + 'billingreports.php?report=tax1098', 'RVC-'),
        (SR + 'billingsetup.php', 'Undergraduate tuition'),
        (SR + f'billingsetup.php?fee={i["fee"]}', None),
        (SR + 'billingsetup.php?fee=0', None),
        (SR + f'billingsetup.php?rule={i["rule"]}', None),
        (SR + 'billingsetup.php?rule=0', None),
        (SR + f'billingsetup.php?plan={i["plan"]}', None),
        (SR + 'billingsetup.php?plan=0', None),
        (SR + 'refunds.php', 'Eli'),
        (SR + 'refunds.php?status=approved', 'Wesley'),
        (SR + 'refunds.php?status=rejected', 'Leah'),
        (SR + 'refunds.php?status=none', 'Eli'),
        (SR + f'refunds.php?id={i["refund"]}', None),
        (SR + f'refunds.php?id={i["aidreturn"]}', None),
        (SR + f'account.php?id={sid["carmen"]}', 'Balance'),
        (SR + f'account.php?id={sid["dara"]}', 'Balance'),
        (SR + f'account.php?id={sid["marco"]}&action=payout', None),
        (SR + f'account.php?id={sid["carmen"]}&action=charge', None),
        (SR + f'account.php?id={sid["carmen"]}&action=credit', None),
        (SR + f'account.php?id={sid["carmen"]}&action=payment', None),
        (SR + f'account.php?id={sid["ben"]}&action=plan', None),
        (f'file:{SR}statement.php?id={sid["carmen"]}', '%PDF'),
        (f'file:{SR}receipt.php?id={i["receipt"]}', '%PDF'),
    ]

    aid_pages = [
        (SR + 'index.php?view=aid', None),
        (SR + 'aid.php', None),
        (SR + 'aid.php?report=students', 'Carmen'),
        (SR + f'aid.php?report=students&id={sid["carmen"]}', 'Pell'),
        (SR + f'aid.php?report=students&id={sid["sam"]}', 'Direct Subsidized Loan'),
        (SR + f'aid.php?report=run&termid={term0}', 'Samuel'),
        (SR + f'aid.php?report=returns&termid={term0}', 'Daniel'),
        (SR + f'aid.php?report=returns&id={sid["dan"]}&termid={term0}', None),
        (SR + 'aid.php?report=verification', 'Theodore'),
        (SR + 'aid.php?report=recipients', 'Carmen'),
        (SR + 'aid.php?report=disbursements', None),
        (SR + f'aid.php?report=enrollment&termid={term0}', 'Carmen'),
        (SR + f'aid.php?report=withdrawals&termid={term0}', 'Daniel'),
        (SR + 'aid.php?report=ninetyten', 'Federal aid'),
        (SR + f'aidletter.php?id={sid["carmen"]}&year={i["year"]}', None),
        (SR + f'aidletter.php?id={sid["carmen"]}&year={i["year"]}&print=1', None),
    ]

    transcript_admin = [
        (AT + f'setup.php?step={step}', None) for step in ('school', 'grading', 'marks', 'programs', 'look', 'done')
    ] + [
        (AT + 'grading.php', None),
        (AT + 'marks.php', None),
        (AT + 'programs.php', 'Business Administration'),
        (AT + 'catalog.php', 'ACC201'),
        (AT + 'drafts.php', None),
        (AT + 'terms.php', i['termname']['0']),
        (AT + 'students.php', None),
        (AT + 'requests.php', 'Gina'),
        (AT + 'approvals.php', 'Hugo'),
        (AT + 'issued.php', 'Henry'),
        (AT + 'log.php', 'Official transcript issued'),
        (AT + 'coursecheck.php', 'ACC201'),
        (AT + f'index.php?userid={uid["gina"]}', 'Academic transcript'),
        (AT + f'index.php?userid={uid["ruth"]}', 'ACC201'),
        (AT + f'record.php?userid={uid["ruth"]}', None),
        (AT + f'studentterms.php?userid={uid["hugo"]}', None),
        (AT + f'transfer.php?userid={uid["ruth"]}', None),
        (f'file:{AT}issued.php?id={i["issue"]}', '%PDF'),
        (f'file:{AT}templates.php?type=grade', None),
    ]

    teacher_pages = [
        (SR + 'index.php?view=teacher', 'ACC201'),
        (SR + 'index.php?view=atrisk', 'Sean'),
        (SR + f'attendance.php?offer={acc_offer}', 'Take attendance'),
        (SR + f'attendance.php?offer={acc_offer}&meeting={i["meeting"]}', None),
        (SR + f'attendance.php?course={acc}', None),
        (SR + f'students.php?courseid={acc}', 'Carmen'),
        (SR + f'student.php?id={sid["carmen"]}&courseid={acc}', None),
        (AT + f'codes.php?courseid={acc}', 'Carmen'),
        (AT + f'post.php?courseid={acc}', 'Not posted'),
        (AT + f'students.php?courseid={acc}', None),
        (f'/course/view.php?id={acc}', 'ACC201'),
        (f'/grade/report/grader/index.php?id={acc}', None),
        ('/my/courses.php', None),
    ]

    plan = {
        'guest': [('/login/index.php', 'Welcome to Riverside College'), (AT + 'verify.php', None),
                  (AT + f'verify.php?code={i["verifycode"]}', 'Henry')],
        'admin': records_staff + setup_pages + finance_pages + aid_pages + transcript_admin + [
            ('/admin/settings.php?section=local_academictranscript', None),
            ('/admin/settings.php?section=themesettingstudentrecords', None),
            ('/payment/accounts.php', 'Riverside College student payments'),
        ],
        'rosa': records_staff + setup_pages + transcript_admin,
        'mona': records_staff[:12] + finance_pages[:6] + aid_pages[:3],
        'omar': [(SR + 'gradechange.php', 'Theodore'), (SR + 'index.php', None)],
        'nina': [(SR + 'index.php?view=staff', 'New applicants'), (SR + 'admissions.php', 'Lucy'),
                 (SR + 'admissions.php?report=pipeline', 'AS-BUS'), (SR + f'student.php?id={sid["lucy"]}&tab=admissions', None),
                 (SR + f'studentpart.php?id={sid["lucy"]}&part=checklist', None)],
        'doris': [(SR + 'index.php?view=staff', None), (SR + 'admissions.php', 'Lucy')],
        # Staff who open their own record get the staff file, not the student's page.
        'kira': [(path, None if 'student.php' in path else need) for path, need in student_pages(i, 'kira')] + [
            (SR + 'index.php?view=staff', None),
            (SR + f'student.php?id={sid["kira"]}&edit=1', None),
            (AT + f'post.php?courseid={i["c"]["0|CIS120/01"]}', None),
            (AT + 'requests.php', 'Kira'),
            (SR + 'gradechange.php', 'Your own grade'),
            (SR + 'refunds.php', 'Kira'),
        ],
        'felix': finance_pages,
        'gwen': aid_pages,
        'tomas': teacher_pages,
        'irene': [(SR + 'index.php?view=teacher', 'ENG102'), (AT + f'post.php?courseid={eng}', None),
                  (AT + f'post.php?courseid={com}', 'Posted'), (AT + f'change.php?id={i["posted"]}', None),
                  (SR + f'attendance.php?offer={i["o"]["0|ENG102/01"]}', None)],
        'pia': [(SR + 'index.php?view=teacher', 'CIS120'), (SR + 'index.php?view=atrisk', None),
                (SR + f'attendance.php?offer={i["o"]["0|CIS120/01"]}', None),
                (SR + f'attendance.php?offer={bio_offer}', None), (SR + f'students.php?courseid={i["c"]["0|CIS120/01"]}', None)],
        'carl': [(SR + 'index.php?view=teacher', 'MAT110'), (SR + 'index.php?view=atrisk', 'Sean')],
        'ruth': [(AT + 'index.php?view=mine', 'My transcript'), (f'file:{AT}index.php?action=unofficial', '%PDF')],
        'hugo': [(AT + 'index.php?view=mine', 'My transcript'), ('/my/courses.php', None)],
    }
    for who in ('carmen', 'dara', 'ben', 'nadia', 'yuki', 'ivy', 'hank', 'dan', 'sam', 'priya', 'cleo'):
        plan[who] = student_pages(i, who)
    plan['carmen'] = plan['carmen'] + [(SR + f'pay.php?id={sid["carmen"]}', None), (f'file:{SR}receipt.php?id={i["receipt"]}', '%PDF'),
                                       (SR + f'aidletter.php?id={sid["carmen"]}&year={i["year"]}', None),
                                       (f'/course/view.php?id={acc}', 'ACC201')]
    for who in ('ian', 'ada', 'zoe', 'jonah'):
        plan[who] = [(SR + 'index.php?view=student', None), (SR + f'student.php?id={sid[who]}', None), (SR + 'myregistration.php', None)]
    return plan


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """A file that sends the person elsewhere is a failure, and the site's name works only in the browser."""

    def redirect_request(self, *args):
        return None


def fetch(browser, path):
    """Get a file with the session of the person, and return its status and its first bytes."""
    cookies = '; '.join(f"{c['name']}={c['value']}" for c in browser.s('GET', '/cookie'))
    request = urllib.request.Request(f'http://127.0.0.1:{18000 + SITE}{path}', headers={'Host': HOST, 'Cookie': cookies})
    try:
        with urllib.request.build_opener(NoRedirect).open(request, timeout=120) as reply:
            return reply.status, reply.read(2000)
    except urllib.error.HTTPError as error:
        return error.code, error.read(2000)


def check_file(browser, path, need):
    """What's wrong with a file, or ''."""
    status, body = fetch(browser, path)
    if status != 200:
        return f'status {status}'
    if need and not body.startswith(need.encode()):
        return f'not a {need} file: {body[:80]!r}'
    return ''


def check_page(browser, path, need):
    """What's wrong with a page, or ''."""
    browser.go(path)
    url = browser.url()
    if '/login/' in url and '/login/' not in path:
        return 'sent to the login page'
    text = browser.text('body') if browser.has('body') else ''
    if not text.strip():
        return 'blank page'
    for bad in BAD_TEXT:
        if bad in text:
            match = text.find(bad)
            return 'shows ' + re.sub(r'\s+', ' ', text[match:match + 160])
    raw = RAW_STRING.search(text)
    if raw:
        return f'shows the raw string {raw.group(0)}'
    if browser.has('.errorbox'):
        return 'error: ' + browser.text('.errorbox')[:160].replace('\n', ' ')
    if REFUSED in text:
        return 'refused'
    if need and need not in text:
        return f'does not show {need!r}'
    return ''


def crawl(who, addresses, lock, totals):
    """Sign in as one person and walk their pages."""
    browser = WD()
    try:
        if who == 'admin':
            browser.login('admin', os.environ.get('ADMINPASS', 'Admin@123'))
        elif who != 'guest':
            browser.login(who)
        for path, need in addresses:
            try:
                if path.startswith('file:'):
                    path = path[5:]
                    problem = check_file(browser, path, need)
                else:
                    problem = check_page(browser, path, need)
            except RuntimeError as error:
                problem = f'the browser said: {str(error)[:160]}'
            with lock:
                totals['pages'] += 1
                if problem:
                    totals['failed'] += 1
                    print(f'FAIL {who} {path}: {problem}', flush=True)
                else:
                    print(f'PASS {who} {path}', flush=True)
    finally:
        browser.close()


def main():
    """Run the crawl."""
    ids = lookup()
    plan = pages(ids)
    people = sys.argv[1:] or list(plan)
    lock = threading.Lock()
    totals = {'pages': 0, 'failed': 0}
    jobs = int(os.environ.get('JOBS', '3'))
    with concurrent.futures.ThreadPoolExecutor(max_workers=jobs) as pool:
        running = [pool.submit(crawl, who, plan[who], lock, totals) for who in people]
        for job in running:
            job.result()
    print(f"{totals['pages']} pages, {totals['failed']} failed")
    return 1 if totals['failed'] else 0


if __name__ == '__main__':
    sys.exit(main())
