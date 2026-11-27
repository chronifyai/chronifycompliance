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
    When I set the field "Find an enrolled learner by name or email" to "Student"
    And I press "Search"
    And I click on "Sam Student" "link"
    Then I should see "Why hasn't this learner completed?"
    And I should see "1 condition blocks completion" in the "[data-region='learner-status']" "css_element"
    And I should see "Blocking" in the "[data-region='conditions']" "css_element"
    And I should see "The course is set up correctly. The learner still needs to:" in the "[data-region='verdict']" "css_element"
    And I should see "Copy summary"

  Scenario: A course setting problem is named in the verdict
    Given the Chronify.Compliance demo course "requireddeleted" exists
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course                  | role    |
      | student1 | cd-demo-requireddeleted | student |
    When I am on the "cd-demo-requireddeleted" "local_chronifycompliance > Learner diagnostic" page logged in as "admin"
    And I set the field "Find an enrolled learner by name or email" to "Student"
    And I press "Search"
    And I click on "Sam Student" "link"
    Then I should see "This is a course setting problem, not the learner's" in the "[data-region='verdict']" "css_element"
    And I should see "Course: Demo: Required activity deleted"
