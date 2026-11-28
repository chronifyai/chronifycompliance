@local @local_chronifycompliance @javascript
Feature: Teachers check the links of a course
  In order to make sure learners can open every link in my course
  As a teacher
  I need to check the links of the course and see the broken ones

  Background:
    Given the following config values are set as admin:
      | config      | value | plugin                 |
      | linkautonew | 0     | local_chronifycompliance |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the following "courses" exist:
      | fullname    | shortname | enablecompletion |
      | Link course | links     | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | links  | editingteacher |
    And the following "permission overrides" exist:
      | capability                                | permission | role           | contextlevel | reference |
      | local/chronifycompliance:viewcoursereport | Allow      | editingteacher | System       |           |
      | local/chronifycompliance:checklinks       | Allow      | editingteacher | System       |           |
    And the following "activities" exist:
      | activity | course | name         | content                                                        |
      | page     | links  | Reading list | <p><a href="https://www.mistyped.invalid/">Search</a></p> |

  Scenario: Check links and see a domain that does not exist
    Given I am on the "links" "local_chronifycompliance > Course report" page logged in as "teacher1"
    And I click on "Links" "link" in the "[data-region='report-tabs']" "css_element"
    And I should see "Links in this course have not been checked yet."
    When I press "Check links"
    Then I should see "Link check queued."
    And the "Check links" "button" should be disabled
    And I wait "1" seconds
    And I run all adhoc tasks
    And I am on the "links" "local_chronifycompliance > Course report" page
    And I click on "Links" "link" in the "[data-region='report-tabs']" "css_element"
    And I should see "Broken (1)"
    And I should see "https://www.mistyped.invalid/"
    And I should see "The domain does not exist"
    And I should see "Reading list" in the "#cd-links" "css_element"

  Scenario: A new course is checked automatically
    Given the following config values are set as admin:
      | config      | value | plugin                 |
      | linkautonew | 1     | local_chronifycompliance |
    And the following "courses" exist:
      | fullname   | shortname | enablecompletion |
      | New course | newcourse | 1                |
    And the following "course enrolments" exist:
      | user     | course    | role           |
      | teacher1 | newcourse | editingteacher |
    And I am on the "newcourse" "local_chronifycompliance > Course report" page logged in as "teacher1"
    And I click on "Links" "link" in the "[data-region='report-tabs']" "css_element"
    And I should not see "Link check queued"
    And the Chronify.Compliance link checks of changed new courses are queued
    And I reload the page
    And I should see "Link check queued. It starts at the next cron run."
    When I wait "1" seconds
    And I run all adhoc tasks
    And I am on the "newcourse" "local_chronifycompliance > Course report" page
    And I click on "Links" "link" in the "[data-region='report-tabs']" "css_element"
    Then I should see "Links: 0. Broken: 0."
    And the "Check links" "button" should be enabled
