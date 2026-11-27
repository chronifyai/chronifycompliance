@local @local_chronifycompliance
Feature: Teachers see why a learner has not completed a course
  In order to help a learner complete my course
  As a teacher
  I need to see which completion condition blocks the learner

  Scenario: The blocking condition is highlighted
    Given the Chronify.Compliance demo course "healthy" exists
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course          | role           |
      | teacher1 | cd-demo-healthy | teacher        |
      | student1 | cd-demo-healthy | student        |
    And the following "permission overrides" exist:
      | capability                                     | permission | role    | contextlevel | reference |
      | local/chronifycompliance:viewcoursereport      | Allow      | teacher | System       |           |
      | local/chronifycompliance:viewlearnerdiagnostic | Allow      | teacher | System       |           |
    And I am on the "cd-demo-healthy" "local_chronifycompliance > Learner diagnostic" page logged in as "teacher1"
    When I set the field "Find an enrolled learner" to "Student"
    And I press "Search"
    And I click on "Sam Student" "link"
    Then I should see "Conditions that stop this learner from completing the course: 1."
    And I should see "Blocking"
    And I should see "Not met"
