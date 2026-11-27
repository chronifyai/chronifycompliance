@local @local_chronifycompliance @javascript
Feature: Administrators see the health of their site on the main dashboard
  In order to know how healthy my Moodle is and what to do first
  As an administrator
  I need one score, a score per pack and the top actions

  Scenario: Finish setup with the first check
    Given the following config values are set as admin:
      | config   | value | plugin                   |
      | scanpace | 0     | local_chronifycompliance |
    And the following "courses" exist:
      | fullname     | shortname | enablecompletion |
      | Plain course | plain     | 1                |
    When I am on the "local_chronifycompliance > Dashboard" page logged in as "admin"
    Then I should see "Finish setup"
    When I press "Run the first check"
    Then I should see "Scan queued for"
    And I should see "Checking your site"
    And I wait "1" seconds
    And I run all adhoc tasks
    And I am on the "local_chronifycompliance > Dashboard" page
    Then I should see "Overall health"
    And I should not see "Finish setup"
    And I should see "No checks yet" in the "[data-pack='security']" "css_element"
    And I should see "The trend appears after the second weekly score"

  Scenario: See the score, the breakdown and fix the top action
    Given the Chronify.Compliance demo course "nopassgrade" exists
    When I am on the "local_chronifycompliance > Dashboard" page logged in as "admin"
    Then I should see "Healthy" in the "[data-region='score']" "css_element"
    And I should see "1 critical" in the "[data-pack='completion']" "css_element"
    And I should see "requires a passing grade" in the "[data-region='top-actions']" "css_element"
    And the "Fix it for me" "button" should be disabled
    When I click on "What costs the most points" "text"
    Then I should see "Pass grade missing" in the "[data-region='breakdown']" "css_element"
    When I click on "Preview fix" "button" in the "[data-region='top-actions']" "css_element"
    And I click on "Apply fix" "button" in the "Preview fix" "dialogue"
    Then "[data-region='undo-toast']" "css_element" should be visible
    And I should not see "requires a passing grade" in the "[data-region='top-actions']" "css_element"
    And I should not see "1 critical" in the "[data-pack='completion']" "css_element"

  Scenario: Paused scans and the pack tiles link to the workspace
    Given the following config values are set as admin:
      | config | value | plugin                   |
      | paused | 1     | local_chronifycompliance |
    And the Chronify.Compliance demo course "nopassgrade" exists
    When I am on the "local_chronifycompliance > Dashboard" page logged in as "admin"
    Then I should see "Scans are paused"
    When I click on "[data-pack='completion']" "css_element"
    Then I should see "Demo: Pass grade missing" in the "reportbuilder-table" "table"
