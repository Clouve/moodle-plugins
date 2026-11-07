#!/usr/bin/env python3
"""Student Records: import a spreadsheet and a CSV file on a site with no data, as a site administrator."""
# Snapshot: fresh
import datetime
import os
import sys
import tempfile
import time

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srshots import ADMINPASS, SR, Browser as WD, crop, main_shot, submit, theme, upload, xlsx  # noqa: E402

# A spreadsheet saves a date cell as a number of days since 30 December 1899.
SPRING = (datetime.date(2028, 1, 17) - datetime.date(1899, 12, 30)).days

# Made-up content, with some mistakes on purpose.
SCHOOL = [
    ['Field', 'Value'],
    ['Institution name', 'Riverside College'],
    ['Legal name', 'Riverside College, Inc.'],
    ['Principal place of business', '100 College Way, Elm Hollow, IL 62999'],
    ['Website', 'https://www.example.com'],
    ['Phone', '(618) 555-0100'],
    ['Email', 'registrar@example.com'],
    ['Registrar', 'Rosa Delgado'],
    ['State regulator', 'State Board of Private Colleges (made up)'],
    ['License number', 'PC-2041'],
    ['Accreditation', 'Not accredited'],
    ['Title IV federal aid', 'Maybe next year'],
    ['Calendar type', 'Semester terms'],
    ['Credit unit', 'Semester credit hour'],
    ['Mascot', 'The Otters'],
]
TERMS = [
    ['Term', 'Start date', 'End date', 'Add drop end', 'Last day to withdraw', 'Final exams', 'Holidays'],
    ['Fall 2027', '8/23/2027', '12/17/2027', '9/3/2027', '', '2027-12-13 to 2027-12-17', 'Labor Day; Thanksgiving break'],
    ['Spring 2028', SPRING, '5/12/2028', '1/28/2028', '4/7/2028', '', 'Spring break, March 13 to 17'],
    ['Summer 2028', 'June 5', '7/28/2028', '6/9/2028', '', '', ''],
    ['Winter break', '12/18/2027', '1/16/2028', '', '', '', ''],
]
GRADES = [
    ['Letter', 'Min %', 'Max %', 'Quality points', 'Counts in GPA', 'Earns credit', 'Description',
     'Undergraduate (Associate and bachelor)', 'Graduate (Master)'],
    ['A', 93, 100, 4, 'Yes', '', 'Excellent', 'Pass', 'Pass'],
    ['B', 83, 92, 3, 'Yes', '', 'Good', 'Pass', 'Pass'],
    ['C', 73, 82, 2, 'Yes', '', 'Fair', 'Pass', 'Fail'],
    ['D', 63, 72, 1, 'Yes', '', 'Poor', 'Pass', 'Fail'],
    ['F', 0, 62, 0, 'Yes', '', 'Failing', 'Fail', 'Fail'],
    ['P', '', '', '', 'No', 'Yes', 'Passed, not in GPA', '', ''],
    ['W', '', '', '', 'No', 'No', 'Withdrew', '', ''],
]
RULES = [
    ['Rule', 'Undergraduate (Associate and bachelor)', 'Graduate (Master)'],
    ['Lowest passing grade', 'D or better', 'B or better'],
    ['Last day to withdraw', 'Students may withdraw until 60% of the term', 'same'],
    ['Attendance', 'Students should attend every class', 'same'],
]
COURSES = [
    ['Course code', 'Title', 'Credits', 'Credit type', 'Level', 'Prerequisites'],
    ['ENG 101', 'English Composition I', 3, 'Semester credit', 'Undergraduate', ''],
    ['MAT 110', 'College Algebra', 3, 'Semester credit', 'Undergraduate', ''],
    ['BUS 101', 'Introduction to Business', 3, 'Semester credit', 'Undergraduate', ''],
    ['MKT 210', 'Principles of Marketing', 3, 'Semester credit', 'Undergraduate', 'BUS 101'],
    ['ACC 201', 'Principles of Accounting I', 3, 'Semester credit', 'Undergraduate', 'BUS 101 and ENG 101'],
    ['ECO 201', 'Microeconomics', 3, 'Semester credit', 'Undergraduate', 'MAT 110 or permission of the dean'],
    ['BUS 490', 'Business Capstone', '', 'Semester credit', 'Undergraduate', 'BUS 999'],
    ['PHL 101', 'Phlebotomy Theory', 60, 'Clock hours', 'Certificate', ''],
    ['LDR 501', 'Foundations of Leadership', 3, '', 'Graduate', ''],
    ['LDR 510', '', 3, 'Semester credit', 'Graduate', ''],
]
PROGRAMS = [
    ['Program code', 'Program name', 'School', 'Credential', 'Total credits', 'Hour type', 'Track credits', 'Min GPA',
     'Grade rules', 'Delivery', 'Location', 'CIP code', 'Length', 'Accreditor'],
    ['AS-BUS', 'Business Administration', 'School of Business', 'Associate of Science', 12, 'Semester credit', 3, 2,
     'Core min C; 2.0 GPA in business courses', 'Hybrid', 'Main Campus', '52.0201', '2 years', ''],
    ['CERT-PHL', 'Phlebotomy Technician', 'School of Health', 'Certificate', 120, 'Clock hours', '', '', '',
     'On campus', 'Main Campus', '51.1009', '1 term', ''],
    ['MS-LDR', 'Organizational Leadership', 'School of Business', 'Master of Science', '', 'Semester credit',
     '', 'A 3.0 average', '', 'By arrangement', '', '52.0213', '2 years', ''],
]
OUTLINE = [
    ['Program code', 'Group', 'Course code', 'Course title', 'Credits', 'Requirement'],
    ['AS-BUS', 'General education', 'ENG 101', 'English Composition I', 3, 'Required'],
    ['AS-BUS', 'General education', 'MAT 110', 'College Algebra', 3, 'Required'],
    ['AS-BUS', 'Core', 'BUS 101', 'Introduction to Business', 3, 'Required'],
    ['AS-BUS', 'Marketing track', 'MKT 210', 'Principles of Marketing', 3, 'Track option'],
    ['AS-BUS', 'Accounting track', 'ACC 201', 'Principles of Accounting I', 3, 'Track option'],
    ['AS-BUS', 'Electives', 'ART 105', 'Art Appreciation', 3, 'Elective'],
    ['MS-LDR', 'Core', 'LDR 501', 'Foundations of Leadership', 4, 'Required'],
    ['BS-BUS', 'Core', 'BUS 101', 'Introduction to Business', 3, 'Required'],
]
FACULTY = [
    ['Name', 'Role', 'Type', 'Highest degree', 'Department', 'Email', 'Qualified to teach'],
    ['Dr. Irene Novak (Chair)', 'Professor of English', 'Faculty', 'PhD in English', 'Arts and Sciences', 'irene@example.com',
     'ENG 101'],
    ['Tomas Silva', 'Associate Professor of Business', 'Faculty', 'MBA', 'Business', 'tomas@example.com',
     'BUS 101, MKT 210; ACC 201'],
    ['Nina Petrov', 'Director of Admissions', 'Staff', '', 'Admissions', 'nina@example.com', ''],
    ['Madonna', 'Instructor', 'Faculty', '', 'Arts and Sciences', '', 'MAT 110'],
]
CHECKLIST = [
    ['Level', 'Prior degree', 'TOEFL iBT', 'IELTS', 'Other requirements'],
    ['Undergraduate', 'High school diploma', 61, 5.5, 'Personal statement; Application fee ($50); Minimum age of 18'],
    ['Graduate', 'Bachelor degree', 80, 6.5, 'Resume; Two letters of recommendation; GRE score optional'],
    ['Evening students', 'None', '', '', 'Photo ID'],
]
TUITION = [['Item', 'Amount'], ['Tuition per credit hour', 420], ['Technology fee', 75]]
NOTES = [['Reminder'], ['Check these numbers with the dean before the import.']]
CSV = '''Course code,Title,Credits,Credit type,Level,Prerequisites
COM 150,Public Speaking,3,Semester credit,Undergraduate,
PSY 101,Introduction to Psychology,3,Semester credit,Undergraduate,
ENG 102,English Composition II,3,Semester credit,Undergraduate,ENG 101
'''


def fix_link(b, item):
    """The Fix link of an item on the screen."""
    return b.js('''for (const row of document.querySelectorAll('#region-main table tr')) {
                       if (row.cells[1] && row.cells[1].innerText.trim() === arguments[0]) {
                           return [...row.querySelectorAll('a')].find(a => a.innerText.trim() === 'Fix').href;
                       }
                   }''', item)


def tick_item(b, item):
    """Tick an item on the screen."""
    b.js('''const box = document.querySelector('#region-main input[name="ids[]"][aria-label="Select ' + arguments[0] + '"]');
            box.click();''', item)


def linked(b, code):
    """What a course's page says under Prerequisite courses linked, for the output."""
    b.go(SR + '/courses.php?search=' + code)
    b.click_text(code, '#region-main a')
    return b.text('#region-main')[-90:]


def table_rows(b):
    """Show the numbers of the import in the output, as a check."""
    print(b.text('#region-main table'))


def main():
    theme()
    folder = tempfile.mkdtemp(prefix='sr-import-')
    book = os.path.join(folder, 'riverside-catalog.xlsx')
    xlsx(book, [('School', SCHOOL), ('Terms', TERMS), ('Grades', GRADES), ('Academic rules', RULES), ('Courses', COURSES),
                ('Programs', PROGRAMS), ('Program courses', OUTLINE), ('Faculty', FACULTY), ('Admissions', CHECKLIST),
                ('Tuition', TUITION), ('Notes', NOTES)])
    b = WD()
    b.login('admin', ADMINPASS)
    b.go(SR + '/staff.php')
    b.click_text('Import from spreadsheet', 'a')
    main_shot(b, 'import-upload')

    # Read the file. Nothing is saved yet.
    upload(b, 'input[name=importfilechoose]', book)
    submit(b)
    review = b.url()
    b.js("document.querySelector('#region-main details').open = true;")
    main_shot(b, 'import-review')

    # Look at one kind.
    b.click_text('Terms', '#region-main a')
    crop(b, 'import-items-terms', '#region-main h3', '#region-main h3 + form', pad=0)

    # Fix a date. Dates go in as year-month-day, and 1 in withdrawcalc works out the last day to withdraw.
    b.go(fix_link(b, 'summer 2028'))
    b.fill('#id_f_startdate', '2028-06-05')
    b.fill('#id_f_withdrawcalc', '1')
    main_shot(b, 'import-fix')
    submit(b)

    # A break is no term.
    tick_item(b, 'winter break')
    submit(b, 'button[name=action][value=reject]')

    # An outline before its program and course, and an item with an error.
    b.go(review + '&type=progcrs')
    tick_item(b, 'AS-BUS|ENG101|General education')
    tick_item(b, 'MS-LDR|LDR501|Core')
    submit(b, 'button[name=action][value=approve]')
    crop(b, 'import-approve-parent', '#user-notifications')

    # Everything else, in the right order.
    b.click_text('Approve all pending items without errors', 'button, input[type=submit]')
    time.sleep(2)
    b.settle()
    table_rows(b)
    main_shot(b, 'import-approved')

    # What is left has an error: fix it or reject it.
    b.go(review + '&type=school')
    b.go(fix_link(b, 'fedaid'))
    b.fill('#id_f_value', '0')
    submit(b)
    tick_item(b, 'fedaid')
    submit(b, 'button[name=action][value=approve]')
    b.go(review + '&type=program')
    b.go(fix_link(b, 'MS-LDR'))
    b.fill('#id_f_totalhours', '30')
    submit(b)
    tick_item(b, 'MS-LDR')
    submit(b, 'button[name=action][value=approve]')
    for kind, items in (('course', ['LDR510']), ('progcrs', ['MS-LDR|LDR501|Core', 'BS-BUS|BUS101|Core']),
                        ('faculty', ['madonna']), ('facqual', ['madonna|MAT110'])):
        b.go(f'{review}&type={kind}')
        for item in items:
            tick_item(b, item)
        submit(b, 'button[name=action][value=reject]')
    b.go(review)
    table_rows(b)

    # The same file again: only what differs waits for review.
    b.go(SR + '/import.php')
    upload(b, 'input[name=importfilechoose]', book)
    submit(b)
    table_rows(b)
    crop(b, 'import-again', '#region-main table', pad=0)

    # One CSV file: one kind of data.
    path = os.path.join(folder, 'more-courses.csv')
    with open(path, 'w', encoding='utf-8') as out:
        out.write(CSV)
    b.go(SR + '/import.php')
    crop(b, 'import-earlier', '#region-main h3', '#region-main table', pad=0)
    upload(b, 'input[name=importfilechoose]', path)
    submit(b)
    b.js("document.querySelector('#region-main details').open = true;")
    table_rows(b)
    main_shot(b, 'import-csv')
    b.click_text('Approve all pending items without errors', 'button, input[type=submit]')
    time.sleep(1)
    b.settle()

    # The prerequisite is saved but not in this file: the warning shows, and the link is still made.
    print(linked(b, 'ENG102'))

    # A course approved before its prerequisite gets no link, and approving the prerequisite later adds none.
    path = os.path.join(folder, 'sociology.csv')
    with open(path, 'w', encoding='utf-8') as out:
        out.write('Course code,Title,Credits,Credit type,Level,Prerequisites\n'
                  'SOC 201,Social Problems,3,Semester credit,Undergraduate,SOC 101\n'
                  'SOC 101,Introduction to Sociology,3,Semester credit,Undergraduate,\n')
    b.go(SR + '/import.php')
    upload(b, 'input[name=importfilechoose]', path)
    submit(b)
    review = b.url()
    b.go(review + '&type=course')
    tick_item(b, 'SOC201')
    submit(b, 'button[name=action][value=approve]')
    b.go(review + '&type=course')
    tick_item(b, 'SOC101')
    submit(b, 'button[name=action][value=approve]')
    print(linked(b, 'SOC201'))
    b.close()


if __name__ == '__main__':
    main()
