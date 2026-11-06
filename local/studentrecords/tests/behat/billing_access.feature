@local @local_studentrecords
Feature: Billing is for Finance staff and the student only
  In order to keep student accounts private
  As a school
  I need billing pages open to Finance staff and to the student only

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
      | finance1 | Fran      | Finance  | finance1@example.com |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "roles" exist:
      | shortname | name    | archetype |
      | srfinance | Finance |           |
    And the following "role capability" exists:
      | role                                | srfinance |
      | local/studentrecords:viewbilling    | allow     |
      | local/studentrecords:managebilling  | allow     |
      | local/studentrecords:approverefund  | allow     |
    And the following "role assigns" exist:
      | user     | role      | contextlevel | reference |
      | finance1 | srfinance | System       |           |
    And the following "local_studentrecords > settings" exist:
      | name    | value |
      | billing | 1     |
    And the following "local_studentrecords > students" exist:
      | user     | studentid |
      | student1 | TST-0001  |

  Scenario: Finance staff see the billing list and a student account
    Given I log in as "finance1"
    When I visit "/local/studentrecords/billing.php"
    Then I should see "TST-0001"
    And I click on "Sam Student" "link"
    And I should see "Student account"
    And I should see "Record a payment"

  Scenario: A teacher has no billing pages
    Given I log in as "teacher1"
    When I visit "/local/studentrecords/index.php"
    Then I should not see "Student accounts"
    And I should not see "Finance home"
    And I should not see "Billing setup"

  Scenario: A student sees only their own account, not the billing list
    Given I log in as "student1"
    When I visit "/local/studentrecords/index.php"
    Then I should see "My account"
    And I should not see "Student accounts"
    And I should not see "Finance home"
