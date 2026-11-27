@local @local_chronifycompliance @javascript
Feature: Managers scan all courses and filter the dashboard
  In order to find the courses with completion problems
  As a manager
  I need to scan the site and filter the dashboard

  Background:
    Given the Chronify.Compliance demo course "healthy" exists
    And the Chronify.Compliance demo course "nocriteria" exists

  Scenario: Filter the dashboard by health and by problem
    Given I am on the "local_chronifycompliance > Dashboard" page logged in as "admin"
    Then I should see "Courses with critical problems"
    And I should see "Demo: healthy course" in the "reportbuilder-table" "table"
    And I should see "Demo: No completion conditions" in the "reportbuilder-table" "table"
    When I click on ".lcd-tile-critical" "css_element"
    Then I should see "Demo: No completion conditions" in the "reportbuilder-table" "table"
    And I should not see "Demo: healthy course" in the "reportbuilder-table" "table"
    When I click on "Healthy 1" "link" in the ".lcd-chips" "css_element"
    Then I should see "Demo: healthy course" in the "reportbuilder-table" "table"
    And I should not see "Demo: No completion conditions" in the "reportbuilder-table" "table"
    When I click on "All 2" "link" in the ".lcd-chips" "css_element"
    And I click on "No completion conditions" "link" in the ".lcd-panels" "css_element"
    Then I should see "Demo: No completion conditions" in the "reportbuilder-table" "table"
    And I should not see "Demo: healthy course" in the "reportbuilder-table" "table"

  Scenario: Scan all courses
    Given the following config values are set as admin:
      | config   | value | plugin                 |
      | scanpace | 0     | local_chronifycompliance |
    And the following "courses" exist:
      | fullname     | shortname | enablecompletion |
      | Plain course | plain     | 1                |
    And I am on the "local_chronifycompliance > Dashboard" page logged in as "admin"
    And I should not see "Plain course"
    When I press "Scan all courses"
    Then I should see "Scan queued for"
    # The core step skips tasks when caches were purged in the same second.
    And I wait "1" seconds
    And I run all adhoc tasks
    And I am on the "local_chronifycompliance > Dashboard" page
    And I should see "Plain course" in the "reportbuilder-table" "table"
