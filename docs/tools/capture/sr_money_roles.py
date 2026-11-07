#!/usr/bin/env python3
"""Student Records: a site administrator creates the Finance role and the Financial Aid role on a site with no data."""
# Snapshot: fresh
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'helpers'))
from srmoney import ADMINPASS, SR, start, crop, mark, shows, submit  # noqa: E402
from srshots import theme  # noqa: E402


def finance_role(b):
    """Create the Finance role on Billing setup."""
    b.go(SR + '/billingsetup.php')
    crop(b, 'billing-setup-role-create', mark(b, 'a', 'Finance (Student Records)', 'h3'),
         mark(b, 'z', 'Create the Finance role', 'a'), pad=10)
    b.click_text('Create the Finance role')
    shows(b, 'The Finance role was created. Now give it to your finance staff.')
    shows(b, 'The Finance role exists.')
    b.click_text('Give people the Finance role')
    shows(b, "Assign role 'Finance (Student Records)' in System")


def aid_role(b):
    """Turn the aid module on in the setup wizard, then create the Financial Aid role on Federal aid."""
    b.go(SR + '/aid.php')
    shows(b, 'Sorry, but you do not currently have permissions to do that')
    b.go(SR + '/setup.php?step=approvals')
    b.select('#id_fedaid', 'Yes')
    crop(b, 'aid-turn-on', '#fitem_id_fedaid', pad=8)
    submit(b)
    b.go(SR + '/setup.php?step=school')
    assert b.has('#id_opeid'), 'the School step should now ask for the OPEID'
    b.go(SR + '/staff.php')
    b.click_text('Federal aid', 'a')
    crop(b, 'aid-role-create', mark(b, 'a', 'Financial Aid (Student Records)', 'h3'),
         mark(b, 'z', 'Create the Financial Aid role', 'a'), pad=10)
    b.click_text('Create the Financial Aid role')
    shows(b, 'The Financial Aid role was created. Now give it to your financial aid staff.')
    shows(b, 'The Financial Aid role exists.')
    b.click_text('Give people the Financial Aid role')
    shows(b, "Assign role 'Financial Aid (Student Records)' in System")


def main():
    theme()
    b = start()
    try:
        b.login('admin', ADMINPASS)
        finance_role(b)
        aid_role(b)
    finally:
        b.close()


main()
