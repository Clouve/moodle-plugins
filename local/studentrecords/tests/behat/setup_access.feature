@local @local_studentrecords
Feature: Setup pages are for setup staff only
  In order to keep the school's settings safe
  As a school
  I need billing setup, messages and outside report changes open to setup staff only

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |

  Scenario: An admin opens the setup pages
    Given I log in as "admin"
    When I visit "/local/studentrecords/messages.php"
    Then I should see "Messages to students"
    And I visit "/local/studentrecords/billingsetup.php"
    And I should see "Price list"
    And I visit "/local/studentrecords/outsidereports.php"
    And I should see "Saved exports"

  Scenario: A student has no setup pages
    Given the following "local_studentrecords > students" exist:
      | user     | studentid |
      | student1 | TST-0001  |
    And I log in as "student1"
    When I visit "/local/studentrecords/index.php"
    Then I should see "My home"
    And I should not see "Student Records setup"
    And I should not see "Billing setup"
    And I should not see "Setup wizard"
