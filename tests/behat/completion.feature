@local @local_chronifycompliance @javascript
Feature: Managers scan all courses and filter the Completion workspace
  In order to find the courses with completion problems
  As a manager
  I need to scan the site and filter the course list

  Background:
    Given the Chronify.Compliance demo course "healthy" exists
    And the Chronify.Compliance demo course "nocriteria" exists

  Scenario: Filter the course list by health and by problem
    Given I am on the "local_chronifycompliance > Completion" page logged in as "admin"
    Then I should see "Courses with critical problems"
    And I should see "Demo: healthy course" in the "reportbuilder-table" "table"
    And I should see "Demo: No completion conditions" in the "reportbuilder-table" "table"
    When I click on ".lcd-tile-critical" "css_element"
    Then I should see "Demo: No completion conditions" in the "reportbuilder-table" "table"
    And I should not see "Demo: healthy course" in the "reportbuilder-table" "table"
    When I click on "Healthy 1" "link" in the ".lcd-chips" "css_element"
    Then I should see "Demo: healthy course" in the "reportbuilder-table" "table"
    And I should not see "Demo: No completion conditions" in the "reportbuilder-table" "table"
    When I visit "/local/chronifycompliance/completion.php?checkid=nocriteria"
    Then I should see "Demo: No completion conditions" in the "reportbuilder-table" "table"
    And I should not see "Demo: healthy course" in the "reportbuilder-table" "table"

  Scenario: Scan all courses
    Given the following config values are set as admin:
      | config   | value | plugin                 |
      | scanpace | 0     | local_chronifycompliance |
    And the following "courses" exist:
      | fullname     | shortname | enablecompletion |
      | Plain course | plain     | 1                |
    And I am on the "local_chronifycompliance > Completion" page logged in as "admin"
    And I should not see "Plain course"
    When I press "Scan all courses"
    Then I should see "Scan queued for"
    # The core step skips tasks when caches were purged in the same second.
    And I wait "1" seconds
    And I run all adhoc tasks
    And I am on the "local_chronifycompliance > Completion" page
    And I should see "Plain course" in the "reportbuilder-table" "table"

  Scenario: Fix all one click problems of the selected courses and undo them
    Given the Chronify.Compliance demo course "requireddeleted" exists
    And the Chronify.Compliance demo course "nopassgrade" exists
    And I am on the "local_chronifycompliance > Completion" page logged in as "admin"
    And I should see "Demo: Required activity deleted" in the "reportbuilder-table" "table"
    When I press "Fix all one click problems"
    Then I should see "Tick at least one course in the table first."
    And I click on "OK" "button" in the "Fix all one click problems" "dialogue"
    When I set the field "Select all" to "1"
    And I press "Fix all one click problems"
    Then I should see "in 1 courses. Each one is logged and can be undone." in the "Fix all one click problems" "dialogue"
    And I should see "Demo: Required activity deleted" in the "Fix all one click problems" "dialogue"
    And I should see "more problems can be fixed, but each needs a decision" in the "Fix all one click problems" "dialogue"
    When I click on "[data-action='save']" "css_element" in the "Fix all one click problems" "dialogue"
    Then I should see "problems in 1 courses." in the "[data-region='undo-toast']" "css_element"
    When I click on "Undo fix" "button" in the "[data-region='undo-toast']" "css_element"
    Then "[data-region='undo-toast']" "css_element" should not exist

  Scenario: SCORM strip and site checks below the table
    Given the Chronify.Compliance demo course "scormnostatus" exists
    And I am on the "local_chronifycompliance > Completion" page logged in as "admin"
    Then I should see "activities need attention" in the "[data-region='scorm-strip']" "css_element"
    When I click on "Show these courses" "link"
    Then I should see "Demo: " in the "reportbuilder-table" "table"
    And I should see "SCORM problems" in the ".lcd-chips" "css_element"

  Scenario: Security problems of the site are listed with a link to the setting
    Given the Chronify.Compliance demo site problem "adminmfa" exists
    When I am on the "local_chronifycompliance > Completion" page logged in as "admin"
    Then I should see "Multi-factor authentication is off" in the "[data-region='site-checks']" "css_element"
    And I am on the "local_chronifycompliance > Dashboard" page
    And I should not see "No checks yet" in the "[data-pack='security']" "css_element"
    And I should see "critical" in the "[data-pack='security']" "css_element"
