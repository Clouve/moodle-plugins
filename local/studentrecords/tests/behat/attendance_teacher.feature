@local @local_studentrecords
Feature: Teachers take attendance for their linked courses
  In order to keep the last date of attendance
  As a teacher of a Moodle course linked to a course offering
  I need to see the class meetings and take attendance

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname      | shortname |
      | Moodle course | MC1       |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | MC1    | editingteacher |
    And the following "local_studentrecords > terms" exist:
      | name      |
      | Fall 2030 |
    And the following "local_studentrecords > catalog courses" exist:
      | code   | title           |
      | TST101 | Test course 101 |
    And the following "local_studentrecords > offerings" exist:
      | catalogcourse | term      | moodlecourse |
      | TST101        | Fall 2030 | MC1          |
    And the following "local_studentrecords > meetings" exist:
      | offering         | date       | hours |
      | TST101 Fall 2030 | 2024-01-08 | 3     |

  Scenario: A teacher opens the class meetings of their course from the course menu
    Given I log in as "teacher1"
    When I am on "Moodle course" course homepage
    And I navigate to "Attendance" in current page administration
    And I click on "TST101" "link"
    Then I should see "Take attendance"
    And I should see "Add a weekly schedule"
