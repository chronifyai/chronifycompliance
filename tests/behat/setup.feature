@local @local_chronifycompliance @javascript
Feature: Administrators set up Chronify.Compliance on first use
  In order to see results that fit my site within minutes
  As an administrator
  I need a short setup that picks my frameworks and starts the first check

  Background:
    Given the following config values are set as admin:
      | config   | value | plugin                   |
      | scanpace | 0     | local_chronifycompliance |
    And the following "courses" exist:
      | fullname     | shortname | enablecompletion |
      | Plain course | plain     | 1                |

  Scenario: Answer the questions and start the first check
    When I am on the "local_chronifycompliance > Dashboard" page logged in as "admin"
    Then I should see "Set up Chronify.Compliance"
    And I should see "Step 1 of 3"
    When I set the following fields to these values:
      | Sector  | Higher education |
      | Country | United States    |
    And I press "Next"
    Then I should see "Step 2 of 3"
    And the field "NIST CSF 2.0" matches value "1"
    And the field "FERPA" matches value "1"
    And the field "GDPR" matches value "0"
    And I should see "ISO/IEC 27001, SOC 2, HIPAA, WCAG 2.2"
    When I set the field "GDPR" to "1"
    And I press "Next"
    Then I should see "Step 3 of 3"
    And I should see "recently changed courses"
    When I press "Back"
    Then the field "GDPR" matches value "1"
    And I press "Next"
    When I press "Start first check"
    Then I should see "The first check has started"
    And I should see "Checking your site"
    And I wait "1" seconds
    And I run all adhoc tasks
    And I am on the "local_chronifycompliance > Dashboard" page
    Then I should see "Overall health"
    And I should see "No checks yet" in the "[data-pack='security']" "css_element"
    And I should see "The trend appears after the second weekly score"

  Scenario: Skip the setup and finish it later
    When I am on the "local_chronifycompliance > Dashboard" page logged in as "admin"
    And I press "Skip for now"
    Then I should see "Finish setup"
    And I am on the "local_chronifycompliance > Dashboard" page
    And I should see "Finish setup"
    When I click on "Continue setup" "link"
    Then I should see "Step 1 of 3"
