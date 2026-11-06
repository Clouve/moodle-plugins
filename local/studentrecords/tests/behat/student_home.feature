@local @local_studentrecords
Feature: Students see their own Student Records pages
  In order to follow my studies and my account
  As a student
  I need to open my own record and account, and not other students' ones

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
      | student2 | Pat       | Other    | student2@example.com |
    And the following "local_studentrecords > programs" exist:
      | code | name          | level         |
      | BSX  | Test bachelor | Undergraduate |
    And the following "local_studentrecords > students" exist:
      | user     | studentid | program |
      | student1 | TST-0001  | BSX     |
      | student2 | TST-0002  | BSX     |

  Scenario: A student opens their own record from the Student Records home page
    Given I log in as "student1"
    When I visit "/local/studentrecords/index.php"
    And I click on "My student record" "link"
    Then I should see "TST-0001"
    And I should not see "TST-0002"

  Scenario: A student sees their balance when billing is on
    Given the following "local_studentrecords > settings" exist:
      | name    | value |
      | billing | 1     |
    And I log in as "student1"
    When I visit "/local/studentrecords/index.php"
    Then I should see "Balance due"
    And I should see "$0.00"
    And I should see "View my account"
