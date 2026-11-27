@local @local_chronifycompliance @javascript
Feature: Teachers fix and ignore completion problems in the course report
  In order to make sure learners can complete my course
  As a teacher
  I need to see completion problems, fix them with a preview, undo fixes and ignore findings

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the Chronify.Compliance demo course "nopassgrade" exists
    And the Chronify.Compliance demo course "weakcondition" exists
    And the following "course enrolments" exist:
      | user     | course                | role           |
      | teacher1 | cd-demo-nopassgrade   | editingteacher |
      | teacher1 | cd-demo-weakcondition | editingteacher |
    And the following "permission overrides" exist:
      | capability                         | permission | role           | contextlevel | reference           |
      | local/chronifycompliance:applyfix    | Allow      | editingteacher | Course       | cd-demo-nopassgrade |
      | local/chronifycompliance:viewcoursereport | Allow | editingteacher | System       |                     |
      | local/chronifycompliance:ignorefinding    | Allow | editingteacher | System       |                     |

  Scenario: Preview, apply and undo a fix
    Given I am on the "cd-demo-nopassgrade" "local_chronifycompliance > Course report" page logged in as "teacher1"
    Then I should see "Pass grade missing"
    When I press "Preview fix"
    And I set the field "Grade to pass" to "60"
    And I click on "Update preview" "button" in the "Preview fix" "dialogue"
    Then I should see "60.00" in the "Preview fix" "dialogue"
    When I click on "Apply fix" "button" in the "Preview fix" "dialogue"
    Then I should see "No open problems."
    When I click on "Fixed (1)" "text"
    And I press "Undo fix"
    And I click on "Undo fix" "button" in the "Undo fix" "dialogue"
    Then I should see "Pass grade missing"
    And I should see "Preview fix"

  Scenario: Ignore a finding and reopen it
    Given I am on the "cd-demo-weakcondition" "local_chronifycompliance > Course report" page logged in as "teacher1"
    Then I should see "Weak completion condition"
    And I should not see "Preview fix"
    When I press "Ignore"
    And I set the field "Why is this not a problem?" to "Reading is enough for this page"
    And I click on "Ignore" "button" in the "Ignore" "dialogue"
    Then I should see "No open problems."
    When I click on "Ignored" "text"
    Then I should see "Ignored: Reading is enough for this page"
    When I press "Reopen"
    Then I should see "Weak completion condition"

  Scenario: Only teachers get the course report link
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course              | role    |
      | student1 | cd-demo-nopassgrade | student |
    When I am on the "cd-demo-nopassgrade" "Course" page logged in as "student1"
    Then "Completion health" "link" should not exist
    And I log out
    And I am on the "cd-demo-nopassgrade" "Course" page logged in as "teacher1"
    And I navigate to "Completion health" in current page administration
    And I should see "Pass grade missing"
