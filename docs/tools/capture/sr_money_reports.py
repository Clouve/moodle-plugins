#!/usr/bin/env python3
"""Student Records: the money reports and the 1098-T spreadsheet, as the Finance officer."""
# Snapshot: seed
import csv
import io
import os
import sys
import tempfile
from datetime import date, timedelta

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import SR, close_menu, crop, mark_next, shows, start, term_dates, term_names  # noqa: E402

TERM = term_names()[0]


def download(b, query):
    """Save a report as a CSV file in a scratch folder and read it."""
    with tempfile.TemporaryDirectory() as folder:
        name = os.path.join(folder, 'report.csv')
        b.fetch(SR + '/billingreports.php?' + query + '&download=csv', name)
        with open(name, encoding='utf-8-sig') as file:
            return list(csv.reader(io.StringIO(file.read())))


def set_day(b, css, day):
    """Set a date box."""
    b.js("document.querySelector(arguments[0]).value = arguments[1];", css, day)


def home(b):
    """Finance home sums up the reports."""
    b.go(SR + '/index.php?view=finance')
    crop(b, 'money-finance-home', '#page-header', '.sr-columns', pad=4)


def owed(b):
    """Who owes money."""
    b.go(SR + '/billingreports.php?report=owed')
    shows(b, 'Students who owe money, by how old the unpaid charges are.')
    crop(b, 'money-owed', '.nav-tabs', 'tbody tr:nth-child(7)', pad=4)
    rows = download(b, 'report=owed')
    assert rows[0][:2] == ['Student', 'Student ID'], rows[0]


def payments(b):
    """Payments by day, from just before the term started."""
    b.go(SR + '/billingreports.php?report=payments')
    first = date.fromisoformat(term_dates()[TERM][0]) - timedelta(days=10)
    set_day(b, '#id_from', first.isoformat())
    set_day(b, '#id_to', (first + timedelta(days=30)).isoformat())
    b.click('input[type=submit][value=Show]')
    shows(b, 'Payments received each day, by way of paying.')
    crop(b, 'money-payments', '.nav-tabs', mark_next(b, 'z', 'form.row', 'table'), pad=4)


def refunds(b):
    """Refunds between two dates."""
    b.go(SR + '/billingreports.php?report=refunds')
    set_day(b, '#id_from', term_dates()[TERM][0])
    b.click('input[type=submit][value=Show]')
    crop(b, 'money-refunds', '.nav-tabs', 'tbody tr:nth-child(5)', pad=4)


def late(b):
    """Late plan payments."""
    b.go(SR + '/billingreports.php?report=late')
    shows(b, 'Plan payments not paid after their due date and grace days.')
    crop(b, 'money-late', '.nav-tabs', mark_next(b, 'z', '.nav-tabs', 'table'), pad=4)


def tax(b):
    """The 1098-T data of a calendar year."""
    b.go(SR + '/billingreports.php?report=tax1098')
    shows(b, 'Not tax advice.')
    crop(b, 'money-1098', '.nav-tabs', '.sr-tiles', pad=4)
    table = mark_next(b, 'y', '.sr-tiles', 'table')
    # Up to Box 6: the table is wider than its scroll box, and a crop to its full width cuts Box 7 in half.
    crop(b, 'money-1098-table', f'{table} thead th:nth-child(1)', f'{table} tbody tr:nth-child(4) td:nth-child(11)', pad=2)
    print(b.js("return document.querySelector('select[name=year]').value"))
    rows = download(b, 'report=tax1098&year=' + b.js("return document.querySelector('select[name=year]').value"))
    print(rows[0])
    print(rows[1])
    assert rows[0][4] == 'Taxpayer ID (not stored)' and rows[1][4] == '', rows[:2]


def main():
    b = start()
    try:
        b.login('felix')
        home(b)
        close_menu(b)
        owed(b)
        payments(b)
        refunds(b)
        late(b)
        tax(b)
    finally:
        b.close()


main()
